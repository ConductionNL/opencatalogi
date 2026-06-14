<?php
/**
 * OpenCatalogi Pages Controller.
 *
 * Controller for handling page-related operations in the OpenCatalogi app.
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
 * @spec openspec/changes/retrofit-2026-05-25-annotate-opencatalogi/tasks.md#task-26
 * @spec openspec/changes/retrofit-2026-05-25-annotate-opencatalogi/tasks.md#task-27
 */

namespace OCA\OpenCatalogi\Controller;

use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\Http\Response;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IAppConfig;
use OCP\App\IAppManager;
use Psr\Container\ContainerInterface;
use Psr\Container\ContainerExceptionInterface;
use Psr\Container\NotFoundExceptionInterface;
use RuntimeException;

/**
 * Class PagesController.
 *
 * Controller for handling page-related operations in the OpenCatalogi app.
 *
 * @category  Controller
 * @package   OCA\OpenCatalogi\Controller
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: <git_id>
 * @link      https://www.OpenCatalogi.nl
 */
class PagesController extends Controller
{
    use ResolvesRegisterConfiguration;

    /**
     * Allowed CORS methods.
     *
     * @var string Allowed CORS methods
     */
    private string $corsMethods;

    /**
     * Allowed CORS headers.
     *
     * @var string Allowed CORS headers
     */
    private string $corsAllowedHeaders;

    /**
     * CORS max age.
     *
     * @var integer CORS max age
     */
    private int $corsMaxAge;

    /**
     * PagesController constructor.
     *
     * @param string             $appName            The name of the app
     * @param IRequest           $request            The request object
     * @param IAppConfig         $config             App configuration interface
     * @param ContainerInterface $container          Server container for dependency injection
     * @param IAppManager        $appManager         App manager for checking installed apps
     * @param IL10N              $l10n               Localization service
     * @param string             $corsMethods        Allowed CORS methods
     * @param string             $corsAllowedHeaders Allowed CORS headers
     * @param integer            $corsMaxAge         CORS max age
     *
     * @SuppressWarnings(PHPMD.ExcessiveParameterList)
     */
    public function __construct(
        $appName,
        IRequest $request,
        private readonly IAppConfig $config,
        private readonly ContainerInterface $container,
        private readonly IAppManager $appManager,
        private readonly IL10N $l10n,
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
     * @return \OCA\OpenRegister\Service\ObjectService|null The OpenRegister ObjectService if available, null otherwise.
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
     * Get the schema and register configuration for pages.
     *
     * Resolved through OpenRegister's RegisterResolverService (no empty-string
     * fallback); an unconfigured `page_register`/`page_schema` raises
     * MissingConfigException which the caller converts to a 503.
     *
     * @return array<string, string> Array containing schema and register configuration
     *
     * @throws \RuntimeException                                                       When OpenRegister is unavailable.
     * @throws \OCA\OpenRegister\Service\Resolver\Exception\MissingConfigException When a context key is unconfigured.
     *
     * @spec openspec/specs/opencatalogi-adopt-or-abstractions/spec.md (Requirement: Adopt RegisterResolverService)
     */
    private function getPageConfiguration(): array
    {
        return $this->resolveRegisterConfiguration('page_register', 'page_schema');

    }//end getPageConfiguration()

    /**
     * Resolve the Access-Control-Allow-Origin header value for the current request.
     *
     * Reads the configured allowlist from IAppConfig key 'cors_allowed_origins' (CSV).
     * Special value '*' (the default) means "any origin allowed" and emits a literal '*'
     * — the caller's Origin is NEVER echoed back unless it appears on the allowlist (#735).
     *
     * @return string The header value to use for Access-Control-Allow-Origin.
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
     * @return Response The CORS response
     *
     * @NoCSRFRequired
     * @PublicPage
     *
     * @spec openspec/changes/retrofit-2026-05-25-cross-origin-api-access/tasks.md#task-1
     */
    public function preflightedCors(): Response
    {
        // Create and configure the response.
        $response = new Response();
        $response->addHeader('Access-Control-Allow-Origin', $this->resolveAllowedOrigin());
        $response->addHeader('Access-Control-Allow-Methods', $this->corsMethods);
        $response->addHeader('Access-Control-Max-Age', (string) $this->corsMaxAge);
        $response->addHeader('Access-Control-Allow-Headers', $this->corsAllowedHeaders);
        $response->addHeader('Access-Control-Allow-Credentials', 'false');

        return $response;

    }//end preflightedCors()

    /**
     * Get all pages using searchObjectsPaginated.
     *
     * @return JSONResponse The JSON response containing the list of pages
     * @throws ContainerExceptionInterface|NotFoundExceptionInterface
     *
     * @NoCSRFRequired
     * @PublicPage
     *
     * @spec openspec/changes/retrofit-2026-05-25-annotate-opencatalogi/tasks.md#task-26
     */
    public function index(): JSONResponse
    {
        // Get page configuration from settings (resolved via OpenRegister; 503 if unconfigured).
        try {
            $pageConfig = $this->getPageConfiguration();
        } catch (\Throwable $e) {
            return $this->registerConfigErrorResponse($e);
        }

        // Get query parameters from request.
        $queryParams = $this->request->getParams();

        // Build search query.
        $searchQuery = $queryParams;

        // Clean up unwanted parameters.
        unset($searchQuery['id'], $searchQuery['_route']);

        // Add schema filter if configured using _schema for magic mapper routing.
        if (empty($pageConfig['schema']) === false) {
            $searchQuery['_schema'] = $pageConfig['schema'];
        }

        // Add register filter if configured using _register for magic mapper routing.
        if (empty($pageConfig['register']) === false) {
            $searchQuery['_register'] = $pageConfig['register'];
        }

        // Use searchObjectsPaginated for better performance and pagination support.
        // Rbac=true enforces schema authorization; multi=false for public page access.
        $result = $this->getObjectService()->searchObjectsPaginated($searchQuery, _rbac: true, _multitenancy: false);

        // Visibility governed by RBAC on the search above (_rbac: true).
        // Add CORS headers for public API access (#735 — never reflect arbitrary Origin).
        $response = new JSONResponse($result);

        $response->addHeader('Access-Control-Allow-Origin', $this->resolveAllowedOrigin());
        $response->addHeader('Access-Control-Allow-Methods', $this->corsMethods);
        $response->addHeader('Access-Control-Allow-Headers', $this->corsAllowedHeaders);

        return $response;

    }//end index()

    /**
     * Get a specific page by its slug using searchObjectsPaginated.
     *
     * @param string $slug The slug of the page to retrieve
     *
     * @return JSONResponse The JSON response containing the page details
     * @throws ContainerExceptionInterface|NotFoundExceptionInterface
     *
     * @NoCSRFRequired
     * @PublicPage
     *
     * @spec openspec/changes/retrofit-2026-05-25-annotate-opencatalogi/tasks.md#task-27
     */
    public function show(string $slug): JSONResponse
    {
        // Get page configuration from settings (resolved via OpenRegister; 503 if unconfigured).
        try {
            $pageConfig = $this->getPageConfiguration();
        } catch (\Throwable $e) {
            return $this->registerConfigErrorResponse($e);
        }

        // Build search query to find page by slug.
        $searchQuery = [
            'slug'   => $slug,
            '_limit' => 1,
        ];

        // Add schema filter if configured using _schema for magic mapper routing.
        if (empty($pageConfig['schema']) === false) {
            $searchQuery['_schema'] = $pageConfig['schema'];
        }

        // Add register filter if configured using _register for magic mapper routing.
        if (empty($pageConfig['register']) === false) {
            $searchQuery['_register'] = $pageConfig['register'];
        }

        // Use searchObjectsPaginated for better performance.
        // Rbac=true enforces schema authorization; multi=false for public page access.
        $result = $this->getObjectService()->searchObjectsPaginated($searchQuery, _rbac: true, _multitenancy: false);

        $response = new JSONResponse(['error' => $this->l10n->t('Page not found')], 404);
        if (empty($result['results']) === false) {
            // Visibility governed by RBAC on the search above (_rbac: true); a page the
            // caller may not read resolves to an empty result and keeps the 404 above.
            $page     = $result['results'][0];
            $response = new JSONResponse($page);
        }

        // Add CORS headers for public API access (#735 — never reflect arbitrary Origin).
        $response->addHeader('Access-Control-Allow-Origin', $this->resolveAllowedOrigin());
        $response->addHeader('Access-Control-Allow-Methods', $this->corsMethods);
        $response->addHeader('Access-Control-Allow-Headers', $this->corsAllowedHeaders);

        return $response;

    }//end show()
}//end class
