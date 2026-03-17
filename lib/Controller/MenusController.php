<?php
/**
 * MenusController for OpenCatalogi.
 *
 * @category Controller
 * @package  OCA\OpenCatalogi\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenCatalogi.nl
 */


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
 * Class MenusController
 *
 * Controller for handling menu-related operations in the OpenCatalogi app.
 *
 * @category  Controller
 * @package   opencatalogi
 * @author    Ruben van der Linde
 * @copyright 2024
 * @license   AGPL-3.0-or-later
 * @version   1.0.0
 * @link      https://github.com/opencatalogi/opencatalogi
 */
class MenusController extends Controller
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
     * MenusController constructor.
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
        string $corsMethods='PUT, POST, GET, DELETE, PATCH',
        string $corsAllowedHeaders='Authorization, Content-Type, Accept',
        int $corsMaxAge=1728000
    ) {
        parent::__construct(appName: $appName, request: $request);
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
     * Get the schema and register configuration for menus.
     *
     * @return array<string, string> Array containing schema and register configuration
     */
    private function getMenuConfiguration(): array
    {
        // Get the menu schema and register from configuration.
        $schema   = $this->config->getValueString($this->appName, 'menu_schema', '');
        $register = $this->config->getValueString($this->appName, 'menu_register', '');

        return [
            'schema'   => $schema,
            'register' => $register,
        ];

    }//end getMenuConfiguration()

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
        // Determine the origin.
        if (empty($this->request->getHeader('Origin')) === false) {
            $origin = $this->request->getHeader('Origin');
        } else {
            $origin = ($this->request->server['HTTP_ORIGIN'] ?? '*');
        }

        // Create and configure the response.
        $response = new \OCP\AppFramework\Http\Response();
        $response->addHeader('Access-Control-Allow-Origin', $origin);
        $response->addHeader('Access-Control-Allow-Methods', $this->corsMethods);
        $response->addHeader('Access-Control-Max-Age', (string) $this->corsMaxAge);
        $response->addHeader('Access-Control-Allow-Headers', $this->corsAllowedHeaders);
        $response->addHeader('Access-Control-Allow-Credentials', 'false');

        return $response;

    }//end preflightedCors()

    /**
     * Get all menus - OPTIMIZED with searchObjectsPaginated.
     *
     * @return JSONResponse The JSON response containing the list of menus
     * @throws ContainerExceptionInterface|NotFoundExceptionInterface
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     * @PublicPage
     */
    public function index(): JSONResponse
    {
        // Get menu configuration from settings.
        $menuConfig = $this->getMenuConfiguration();

        // Get query parameters from request.
        $queryParams = $this->request->getParams();

        // Build search query.
        $searchQuery = $queryParams;

        // Clean up unwanted parameters.
        unset($searchQuery['id'], $searchQuery['_route']);

        // Add schema filter - use _schema for magic mapper routing.
        // Use the configured schema if set, otherwise default to schema ID '7' (menu).
        if (empty($menuConfig['schema']) === false) {
            $searchQuery['_schema'] = $menuConfig['schema'];
        } else {
            $searchQuery['_schema'] = '7';
        }

        // Add register filter - use _register for magic mapper routing.
        // Use the configured register if set, otherwise default to register ID '1' (publication).
        if (empty($menuConfig['register']) === false) {
            $searchQuery['_register'] = $menuConfig['register'];
        } else {
            $searchQuery['_register'] = '1';
        }

        // Use searchObjectsPaginated for better performance and pagination support.
        // Set rbac=false, multi=false, published=false to get all menus regardless of published status.
        $result = $this->getObjectService()->searchObjectsPaginated(
            $searchQuery,
            _rbac: false,
            _multitenancy: false,
            published: false
        );

        /*
         * Build paginated response structure.
         * $responseData = [
         * 'results' => $result['results'] ?? [],
         * 'total' => $result['total'] ?? 0,
         * 'limit' => $result['limit'] ?? 20,
         * 'offset' => $result['offset'] ?? 0,
         * 'page' => $result['page'] ?? 1,
         * 'pages' => $result['pages'] ?? 1
         * ];
         *
         * // Add pagination links if present.
         * if (isset($result['next']) === true) {
         * $responseData['next'] = $result['next'];
         * }
         * if (isset($result['prev']) === true) {
         * $responseData['prev'] = $result['prev'];
         * }
         *
         * // Add facets if present.
         * if (isset($result['facets']) === true) {
         * $facetsData = $result['facets'];
         * // Unwrap nested facets if needed.
         * if (isset($facetsData['facets']) === true && is_array($facetsData['facets']) === true) {
         *     $facetsData = $facetsData['facets'];
         * }
         * $responseData['facets'] = $facetsData;
         * }
         * if (isset($result['facetable']) === true) {
         * $responseData['facetable'] = $result['facetable'];
         * }
         */

        // Add CORS headers for public API access.
        $response = new JSONResponse($result);
        if (isset($this->request->server['HTTP_ORIGIN']) === true) {
            $origin = $this->request->server['HTTP_ORIGIN'];
        } else {
            $origin = '*';
        }

        $response->addHeader('Access-Control-Allow-Origin', $origin);
        $response->addHeader('Access-Control-Allow-Methods', $this->corsMethods);
        $response->addHeader('Access-Control-Allow-Headers', $this->corsAllowedHeaders);

        return $response;

    }//end index()

    /**
     * Get a specific menu by its ID.
     *
     * @param string|integer $id The ID of the menu to retrieve
     *
     * @return JSONResponse The JSON response containing the menu details
     * @throws ContainerExceptionInterface|NotFoundExceptionInterface
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     * @PublicPage
     */
    public function show(string|int $id): JSONResponse
    {
        // Use searchObjectsPaginated to find single menu with published=true filter.
        $searchQuery = [
            '_ids'    => [$id],
            '_limit'  => 1,
            '_source' => 'database',
        ];
        $result      = $this->getObjectService()->searchObjectsPaginated(
            $searchQuery,
            _rbac: false,
            _multitenancy: false,
            published: false
        );

        if (empty($result['results']) === true) {
            return new JSONResponse(['error' => 'Menu not found'], 404);
        }

        $menu = $result['results'][0];

        if ($menu instanceof \OCP\AppFramework\Db\Entity) {
            $data = $menu->jsonSerialize();
        } else {
            $data = $menu;
        }

        // Add CORS headers for public API access.
        $response = new JSONResponse($data);
        if (isset($this->request->server['HTTP_ORIGIN']) === true) {
            $origin = $this->request->server['HTTP_ORIGIN'];
        } else {
            $origin = '*';
        }

        $response->addHeader('Access-Control-Allow-Origin', $origin);
        $response->addHeader('Access-Control-Allow-Methods', $this->corsMethods);
        $response->addHeader('Access-Control-Allow-Headers', $this->corsAllowedHeaders);

        return $response;

    }//end show()
}//end class
