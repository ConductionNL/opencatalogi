<?php

namespace OCA\OpenCatalogi\Controller;

use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IAppConfig;
use OCP\App\IAppManager;
use Psr\Container\ContainerInterface;
use Psr\Container\ContainerExceptionInterface;
use Psr\Container\NotFoundExceptionInterface;

/**
 * Class PagesController
 *
 * Controller for handling page-related operations in the OpenCatalogi app.
 *
 * @category  Controller
 * @package   opencatalogi
 * @author    Ruben van der Linde
 * @copyright 2024
 * @license   AGPL-3.0-or-later
 * @version   1.0.0
 * @link      https://github.com/opencatalogi/opencatalogi
 */
class PagesController extends Controller
{

    /**
     * @var string Allowed CORS methods
     */
    private string $corsMethods;

    /**
     * @var string Allowed CORS headers
     */
    private string $corsAllowedHeaders;

    /**
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
        string $corsMethods = 'PUT, POST, GET, DELETE, PATCH',
        string $corsAllowedHeaders = 'Authorization, Content-Type, Accept',
        int $corsMaxAge = 1728000
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

        throw new \RuntimeException('OpenRegister service is not available.');

    }//end getObjectService()


    /**
     * Get the schema and register configuration for pages.
     *
     * @return array<string, string> Array containing schema and register configuration
     */
    private function getPageConfiguration(): array
    {
        // Get the page schema and register from configuration
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
     * @return \OCP\AppFramework\Http\Response The CORS response
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     * @PublicPage
     */
    public function preflightedCors(): \OCP\AppFramework\Http\Response
    {
        // Determine the origin
        $origin = $this->request->getHeader('Origin') ?: ($this->request->server['HTTP_ORIGIN'] ?? '*');

        // Create and configure the response
        $response = new \OCP\AppFramework\Http\Response();
        $response->addHeader('Access-Control-Allow-Origin', $origin);
        $response->addHeader('Access-Control-Allow-Methods', $this->corsMethods);
        $response->addHeader('Access-Control-Max-Age', (string) $this->corsMaxAge);
        $response->addHeader('Access-Control-Allow-Headers', $this->corsAllowedHeaders);
        $response->addHeader('Access-Control-Allow-Credentials', 'false');

        return $response;

    }//end preflightedCors()


    /**
     * Get all pages - OPTIMIZED with searchObjectsPaginated.
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
        // Get page configuration from settings
        $pageConfig = $this->getPageConfiguration();

        // Get query parameters from request
        $queryParams = $this->request->getParams();

        // Build search query
        $searchQuery = $queryParams;

        // Clean up unwanted parameters
        unset($searchQuery['id'], $searchQuery['_route']);

        // Add schema filter if configured - use _schema for magic mapper routing
        if (!empty($pageConfig['schema'])) {
            $searchQuery['_schema'] = $pageConfig['schema'];
        }

        // Add register filter if configured - use _register for magic mapper routing
        if (!empty($pageConfig['register'])) {
            $searchQuery['_register'] = $pageConfig['register'];
        }

        // Use searchObjectsPaginated for better performance and pagination support.
        // Set _rbac=false, _multitenancy=false, published=false for public page access.
        $result = $this->getObjectService()->searchObjectsPaginated($searchQuery, _rbac: false, _multitenancy: false, published: false);

        // WORKAROUND: OpenRegister ignores @self filters, so we filter clientside.
        // Remove any results that don't match the configured schema and register.
        if (isset($result['results']) && is_array($result['results'])) {
            $result['results'] = array_values(array_filter($result['results'], function($item) use ($pageConfig) {
                // Convert ObjectEntity to array if needed.
                if (is_object($item) && method_exists($item, 'jsonSerialize')) {
                    $item = $item->jsonSerialize();
                }
                
                $self = $item['@self'] ?? [];
                $schemaMatch = !isset($pageConfig['schema']) || ($self['schema'] ?? null) === $pageConfig['schema'];
                $registerMatch = !isset($pageConfig['register']) || ($self['register'] ?? null) === $pageConfig['register'];
                return $schemaMatch && $registerMatch;
            }));
            $result['total'] = count($result['results']);
        }

        // Build paginated response structure
        /*
            $responseData = [
            'results' => $result['results'] ?? [],
            'total' => $result['total'] ?? 0,
            'limit' => $result['limit'] ?? 20,
            'offset' => $result['offset'] ?? 0,
            'page' => $result['page'] ?? 1,
            'pages' => $result['pages'] ?? 1
            ];

            // Add pagination links if present
            if (isset($result['next'])) {
            $responseData['next'] = $result['next'];
            }
            if (isset($result['prev'])) {
            $responseData['prev'] = $result['prev'];
            }

            // Add facets if present
            if (isset($result['facets'])) {
            $facetsData = $result['facets'];
            // Unwrap nested facets if needed
            if (isset($facetsData['facets']) && is_array($facetsData['facets'])) {
                $facetsData = $facetsData['facets'];
            }
            $responseData['facets'] = $facetsData;
            }
            if (isset($result['facetable'])) {
            $responseData['facetable'] = $result['facetable'];
            }
        */

        // Add CORS headers for public API access
        $response = new JSONResponse($result);
        $origin   = $this->request->getHeader('Origin') ?: ($this->request->server['HTTP_ORIGIN'] ?? '*');
        $response->addHeader('Access-Control-Allow-Origin', $origin);
        $response->addHeader('Access-Control-Allow-Methods', $this->corsMethods);
        $response->addHeader('Access-Control-Allow-Headers', $this->corsAllowedHeaders);

        return $response;

    }//end index()


    /**
     * Get a specific page by its slug - OPTIMIZED with searchObjectsPaginated.
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
// Use database for reliable slug lookup.
        ];

        // Add schema filter if configured - use _schema for magic mapper routing
        if (!empty($pageConfig['schema'])) {
            $searchQuery['_schema'] = $pageConfig['schema'];
        }

        // Add register filter if configured - use _register for magic mapper routing
        if (!empty($pageConfig['register'])) {
            $searchQuery['_register'] = $pageConfig['register'];
        }

        // Use searchObjectsPaginated for better performance and pagination support.
        // Set _rbac=false, _multitenancy=false, published=false for public page access.
        $result = $this->getObjectService()->searchObjectsPaginated($searchQuery, _rbac: false, _multitenancy: false, published: false);

        if (empty($result['results'])) {
            $response = new JSONResponse(['error' => 'Page not found'], 404);
        } else {
            // Return the first matching page
            $page     = $result['results'][0];
            $response = new JSONResponse($page);
        }

        // Add CORS headers for public API access
        $origin = $this->request->getHeader('Origin') ?: ($this->request->server['HTTP_ORIGIN'] ?? '*');
        $response->addHeader('Access-Control-Allow-Origin', $origin);
        $response->addHeader('Access-Control-Allow-Methods', $this->corsMethods);
        $response->addHeader('Access-Control-Allow-Headers', $this->corsAllowedHeaders);

        return $response;

    }//end show()


}//end class
