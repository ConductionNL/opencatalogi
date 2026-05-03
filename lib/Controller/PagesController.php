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
     * @return array<string, string> Array containing schema and register configuration
     */
    private function getPageConfiguration(): array
    {
        // Get the page schema and register from configuration.
        $schema   = $this->config->getValueString($this->appName, 'page_schema', '');
        $register = $this->config->getValueString($this->appName, 'page_register', '');

        return [
            'schema'   => $schema,
            'register' => $register,
        ];

    }//end getPageConfiguration()

    /**
     * Implements a preflighted CORS response for OPTIONS requests.
     *
     * @return Response The CORS response
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     * @PublicPage
     */
    public function preflightedCors(): Response
    {
        // Determine the origin.
        $origin = $this->request->getHeader('Origin');
        if ($origin === '') {
            $origin = '*';
        }

        // Create and configure the response.
        $response = new Response();
        $response->addHeader('Access-Control-Allow-Origin', $origin);
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
     * @NoAdminRequired
     * @NoCSRFRequired
     * @PublicPage
     */
    public function index(): JSONResponse
    {
        // Get page configuration from settings.
        $pageConfig = $this->getPageConfiguration();

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
        // Set rbac=false and multi=false for public page access.
        $result = $this->getObjectService()->searchObjectsPaginated($searchQuery, _rbac: false, _multitenancy: false);

        // Add CORS headers for public API access.
        $response = new JSONResponse($result);
        $origin   = $this->request->getHeader('Origin');
        if ($origin === '') {
            $origin = ($this->request->server['HTTP_ORIGIN'] ?? '*');
        }

        $response->addHeader('Access-Control-Allow-Origin', $origin);
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
     * @NoAdminRequired
     * @NoCSRFRequired
     * @PublicPage
     */
    public function show(string $slug): JSONResponse
    {
        // Get page configuration from settings.
        $pageConfig = $this->getPageConfiguration();

        // Build search query to find page by slug.
        $searchQuery = [
            'slug'    => $slug,
            '_limit'  => 1,
            '_source' => 'database',
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
        // Set rbac=false and multi=false as schema authorization handles access.
        $result = $this->getObjectService()->searchObjectsPaginated($searchQuery, _rbac: false, _multitenancy: false);

        if (empty($result['results']) === true) {
            $response = new JSONResponse(['error' => $this->l10n->t('Page not found')], 404);
        }

        if (empty($result['results']) === false) {
            // Return the first matching page.
            $page     = $result['results'][0];
            $response = new JSONResponse($page);
        }

        // Add CORS headers for public API access.
        $origin = $this->request->getHeader('Origin');
        if ($origin === '') {
            $origin = '*';
        }

        $response->addHeader('Access-Control-Allow-Origin', $origin);
        $response->addHeader('Access-Control-Allow-Methods', $this->corsMethods);
        $response->addHeader('Access-Control-Allow-Headers', $this->corsAllowedHeaders);

        return $response;

    }//end show()
}//end class
