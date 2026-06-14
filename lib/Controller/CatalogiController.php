<?php
/**
 * OpenCatalogi Catalogi Controller.
 *
 * Controller for handling catalog-related operations in the OpenCatalogi app.
 *
 * @category Controller
 * @package  OCA\OpenCatalogi\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2024 Conduction B.V. <info@conduction.nl>
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenCatalogi.nl
 *
 * @spec openspec/changes/retrofit-2026-05-25-annotate-opencatalogi/tasks.md#task-2
 * @spec openspec/changes/retrofit-2026-05-25-annotate-opencatalogi/tasks.md#task-3
 */

namespace OCA\OpenCatalogi\Controller;

use OCA\OpenCatalogi\Service\CatalogiService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\Http\Response;
use OCP\IRequest;
use OCP\IAppConfig;
use OCP\App\IAppManager;
use Psr\Container\ContainerInterface;
use Psr\Container\ContainerExceptionInterface;
use Psr\Container\NotFoundExceptionInterface;
use RuntimeException;

/**
 * Controller for handling catalog-related operations.
 */
class CatalogiController extends Controller
{
    use ResolvesRegisterConfiguration;

    /**
     * Allowed CORS methods.
     *
     * @var string
     */
    private string $corsMethods;

    /**
     * Allowed CORS headers.
     *
     * @var string
     */
    private string $corsAllowedHeaders;

    /**
     * CORS max age.
     *
     * @var integer
     */
    private int $corsMaxAge;

    /**
     * CatalogiController constructor.
     *
     * @param string             $appName            The name of the app.
     * @param IRequest           $request            The request object.
     * @param CatalogiService    $catalogiService    The catalogi service.
     * @param IAppConfig         $config             App configuration interface.
     * @param ContainerInterface $container          Server container for DI.
     * @param IAppManager        $appManager         App manager.
     * @param string             $corsMethods        Allowed CORS methods.
     * @param string             $corsAllowedHeaders Allowed CORS headers.
     * @param integer            $corsMaxAge         CORS max age.
     *
     * @SuppressWarnings(PHPMD.ExcessiveParameterList)
     */
    public function __construct(
        $appName,
        IRequest $request,
        private readonly CatalogiService $catalogiService,
        private readonly IAppConfig $config,
        private readonly ContainerInterface $container,
        private readonly IAppManager $appManager,
        string $corsMethods='PUT, POST, GET, DELETE, PATCH',
        string $corsAllowedHeaders='Authorization, Content-Type, Accept',
        int $corsMaxAge=1728000
    ) {
        parent::__construct($appName, $request);
        $this->corsMethods        = $corsMethods;
        $this->corsAllowedHeaders = $corsAllowedHeaders;
        $this->corsMaxAge         = $corsMaxAge;

    }//end __construct()

    /**
     * Attempts to retrieve the OpenRegister ObjectService from the container.
     *
     * @return \OCA\OpenRegister\Service\ObjectService|null The ObjectService.
     *
     * @throws ContainerExceptionInterface|NotFoundExceptionInterface
     */
    private function getObjectService(): ?\OCA\OpenRegister\Service\ObjectService
    {
        if (in_array(needle: 'openregister', haystack: $this->appManager->getInstalledApps()) === true) {
            return $this->container->get('OCA\OpenRegister\Service\ObjectService');
        }

        throw new RuntimeException('OpenRegister service is not available.');

    }//end getObjectService()

    /**
     * Get the schema and register configuration for catalogs.
     *
     * Resolved through OpenRegister's RegisterResolverService (no empty-string
     * fallback); an unconfigured `catalog_register`/`catalog_schema` raises
     * MissingConfigException which the caller converts to a 503.
     *
     * @return array<string, string> Array containing schema and register configuration.
     *
     * @throws \RuntimeException                                                       When OpenRegister is unavailable.
     * @throws \OCA\OpenRegister\Service\Resolver\Exception\MissingConfigException When a context key is unconfigured.
     *
     * @spec openspec/specs/opencatalogi-adopt-or-abstractions/spec.md (Requirement: Adopt RegisterResolverService)
     */
    private function getCatalogConfiguration(): array
    {
        return $this->resolveRegisterConfiguration('catalog_register', 'catalog_schema');

    }//end getCatalogConfiguration()

    /**
     * Resolve the Access-Control-Allow-Origin header value for the current request.
     *
     * Reads the configured allowlist from IAppConfig key 'cors_allowed_origins' (CSV).
     * Special value '*' (the default) means "any origin allowed" and emits a literal '*'
     * — the caller's Origin is NEVER echoed back unless it appears on the allowlist (#735).
     *
     * @return string The header value to use for Access-Control-Allow-Origin.
     *
     * @spec exclude CORS-policy plumbing; reads IAppConfig allowlist, no Origin reflection.
     */
    private function resolveAllowedOrigin(): string
    {
        $configured = trim($this->config->getValueString($this->appName, 'cors_allowed_origins', '*'));
        if ($configured === '' || $configured === '*') {
            return '*';
        }

        $allowlist = array_filter(
            array_map('trim', explode(',', $configured)),
            static fn(string $entry): bool => $entry !== ''
        );

        $callerOrigin = $this->request->getHeader('Origin');
        if ($callerOrigin === '') {
            $callerOrigin = ($this->request->server['HTTP_ORIGIN'] ?? '');
        }

        if ($callerOrigin !== '' && in_array($callerOrigin, $allowlist, true) === true) {
            return $callerOrigin;
        }

        return ($allowlist[0] ?? '*');

    }//end resolveAllowedOrigin()

    /**
     * Implements a preflighted CORS response for OPTIONS requests.
     *
     * @return Response The CORS response.
     *
     * @NoCSRFRequired
     * @PublicPage
     *
     * @spec openspec/changes/retrofit-2026-05-25-cross-origin-api-access/tasks.md#task-1
     */
    public function preflightedCors(): Response
    {
        $response = new Response();
        $response->addHeader('Access-Control-Allow-Origin', $this->resolveAllowedOrigin());
        $response->addHeader('Access-Control-Allow-Methods', $this->corsMethods);
        $response->addHeader('Access-Control-Max-Age', (string) $this->corsMaxAge);
        $response->addHeader('Access-Control-Allow-Headers', $this->corsAllowedHeaders);
        $response->addHeader('Access-Control-Allow-Credentials', 'false');

        return $response;

    }//end preflightedCors()

    /**
     * Retrieve a list of publications based on all available catalogs.
     *
     * @return JSONResponse JSON response containing the list of publications.
     *
     * @throws ContainerExceptionInterface|NotFoundExceptionInterface
     *
     * @NoCSRFRequired
     * @PublicPage
     *
     * @spec openspec/changes/retrofit-2026-05-25-annotate-opencatalogi/tasks.md#task-2
     */
    public function index(): JSONResponse
    {
        // Get catalog configuration from settings (resolved via OpenRegister; 503 if unconfigured).
        try {
            $catalogConfig = $this->getCatalogConfiguration();
        } catch (\Throwable $e) {
            return $this->registerConfigErrorResponse($e);
        }

        // Retrieve all request parameters.
        $requestParams = $this->request->getParams();

        // Build search query for searchObjectsPaginated.
        $searchQuery = $this->getObjectService()->buildSearchQuery($requestParams);

        // Constrain the query to the configured catalog register/schema.
        // Set the top-level _register/_schema keys directly: the @self keys produced
        // by buildSearchQuery are overwritten/normalized downstream, so the configured
        // scope must be pinned via the magic-mapper routing keys to actually take effect.
        if (empty($catalogConfig['schema']) === false) {
            $searchQuery['_schema']         = $catalogConfig['schema'];
            $searchQuery['@self']['schema'] = $catalogConfig['schema'];
        }

        if (empty($catalogConfig['register']) === false) {
            $searchQuery['_register']         = $catalogConfig['register'];
            $searchQuery['@self']['register'] = $catalogConfig['register'];
        }

        // Fetch catalog objects using searchObjectsPaginated.
        // _rbac: true enforces schema authorization rules (anonymous callers only see
        // what RBAC permits); multitenancy is left enabled so org scoping is not bypassed.
        $result = $this->getObjectService()->searchObjectsPaginated(
            query: $searchQuery,
            _rbac: true,
            deleted: false
        );

        // Visibility is governed by OpenRegister RBAC (the catalog schema grants the public
        // group read only when published <= $now); the search above runs with _rbac: true.
        // Add CORS headers for public API access (#735 — never reflect arbitrary Origin).
        $response = new JSONResponse($result);
        $response->addHeader('Access-Control-Allow-Origin', $this->resolveAllowedOrigin());
        $response->addHeader('Access-Control-Allow-Methods', $this->corsMethods);
        $response->addHeader('Access-Control-Allow-Headers', $this->corsAllowedHeaders);

        return $response;

    }//end index()

    /**
     * Retrieve a list of catalogs based on provided filters and parameters.
     *
     * @param string|integer $id The ID of the catalog to use as a filter.
     *
     * @return JSONResponse JSON response containing the list of catalogs.
     *
     * @throws ContainerExceptionInterface|NotFoundExceptionInterface
     *
     * @NoCSRFRequired
     * @PublicPage
     *
     * @spec openspec/changes/retrofit-2026-05-25-annotate-opencatalogi/tasks.md#task-3
     */
    public function show(string | int $id): JSONResponse
    {
        // Get all objects using the catalog's registers and schemas as filters.
        $response = $this->catalogiService->index($id);

        // Add CORS headers for public API access (#735 — never reflect arbitrary Origin).
        $response->addHeader('Access-Control-Allow-Origin', $this->resolveAllowedOrigin());
        $response->addHeader('Access-Control-Allow-Methods', $this->corsMethods);
        $response->addHeader('Access-Control-Allow-Headers', $this->corsAllowedHeaders);

        return $response;

    }//end show()
}//end class
