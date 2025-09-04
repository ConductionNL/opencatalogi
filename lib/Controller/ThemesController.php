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
 * Class ThemesController
 *
 * Controller for handling theme-related operations in the OpenCatalogi app.
 *
 * @category  Controller
 * @package   opencatalogi
 * @author    Ruben van der Linde
 * @copyright 2024
 * @license   AGPL-3.0-or-later
 * @version   1.0.0
 * @link      https://github.com/opencatalogi/opencatalogi
 */
class ThemesController extends Controller
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
     * @var int CORS max age
     */
    private int $corsMaxAge;

    /**
     * ThemesController constructor.
     *
     * @param string             $appName            The name of the app
     * @param IRequest           $request            The request object
     * @param IAppConfig         $config             App configuration interface
     * @param ContainerInterface $container          Server container for dependency injection
     * @param IAppManager        $appManager         App manager for checking installed apps
     * @param string             $corsMethods        Allowed CORS methods
     * @param string             $corsAllowedHeaders Allowed CORS headers
     * @param int                $corsMaxAge         CORS max age
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
        $this->corsMethods = $corsMethods;
        $this->corsAllowedHeaders = $corsAllowedHeaders;
        $this->corsMaxAge = $corsMaxAge;

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
     * Get the schema and register configuration for themes.
     *
     * @return array<string, string> Array containing schema and register configuration
     */
    private function getThemeConfiguration(): array
    {
        // Get the theme schema and register from configuration
        $schema   = $this->config->getValueString($this->appName, 'theme_schema', '');
        $register = $this->config->getValueString($this->appName, 'theme_register', '');

        return [
            'schema'   => $schema,
            'register' => $register,
        ];

    }//end getThemeConfiguration()


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
        $origin = isset($this->request->server['HTTP_ORIGIN']) ? $this->request->server['HTTP_ORIGIN'] : '*';

        // Create and configure the response
        $response = new \OCP\AppFramework\Http\Response();
        $response->addHeader('Access-Control-Allow-Origin', $origin);
        $response->addHeader('Access-Control-Allow-Methods', $this->corsMethods);
        $response->addHeader('Access-Control-Max-Age', (string) $this->corsMaxAge);
        $response->addHeader('Access-Control-Allow-Headers', $this->corsAllowedHeaders);
        $response->addHeader('Access-Control-Allow-Credentials', 'false');

        return $response;
    }


    /**
     * Get all themes - OPTIMIZED with searchObjectsPaginated.
     *
     * @return JSONResponse The JSON response containing the list of themes
     * @throws ContainerExceptionInterface|NotFoundExceptionInterface
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     * @PublicPage
     */
    public function index(): JSONResponse
    {
        // Get theme configuration from settings
        $themeConfig = $this->getThemeConfiguration();
        
        // Get query parameters from request
        $queryParams = $this->request->getParams();
        
        // Build search query
        $searchQuery = $queryParams;
        
        // Clean up unwanted parameters
        unset($searchQuery['id'], $searchQuery['_route']);

        // Add schema filter if configured - use proper OpenRegister syntax
        if (!empty($themeConfig['schema'])) {
            $searchQuery['@self']['schema'] = $themeConfig['schema'];
        }

        // Add register filter if configured - use proper OpenRegister syntax
        if (!empty($themeConfig['register'])) {
            $searchQuery['@self']['register'] = $themeConfig['register'];
        }

        // Use searchObjectsPaginated for better performance and pagination support
        $result = $this->getObjectService()->searchObjectsPaginated($searchQuery, rbac: false, multi: false);
        
        // Build paginated response structure
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

        // Add CORS headers for public API access
        $response = new JSONResponse($responseData);
        $origin = isset($this->request->server['HTTP_ORIGIN']) ? $this->request->server['HTTP_ORIGIN'] : '*';
        $response->addHeader('Access-Control-Allow-Origin', $origin);
        $response->addHeader('Access-Control-Allow-Methods', $this->corsMethods);
        $response->addHeader('Access-Control-Allow-Headers', $this->corsAllowedHeaders);

        return $response;

    }//end index()


    /**
     * Get a specific theme by its ID.
     *
     * @param string|int $id The ID of the theme to retrieve
     *
     * @return JSONResponse The JSON response containing the theme details
     * @throws ContainerExceptionInterface|NotFoundExceptionInterface
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     * @PublicPage
     */
    public function show(string|int $id): JSONResponse
    {
        $theme = $this->getObjectService()->find($id);
        
        $data = $theme instanceof \OCP\AppFramework\Db\Entity ? $theme->jsonSerialize() : $theme;
        
        // Add CORS headers for public API access
        $response = new JSONResponse($data);
        $origin = isset($this->request->server['HTTP_ORIGIN']) ? $this->request->server['HTTP_ORIGIN'] : '*';
        $response->addHeader('Access-Control-Allow-Origin', $origin);
        $response->addHeader('Access-Control-Allow-Methods', $this->corsMethods);
        $response->addHeader('Access-Control-Allow-Headers', $this->corsAllowedHeaders);

        return $response;

    }//end show()


}//end class
