<?php

namespace OCA\OpenCatalogi\Controller;

use OCA\OpenCatalogi\Service\CatalogiService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IAppConfig;
use OCP\App\IAppManager;
use Psr\Container\ContainerInterface;
use Psr\Container\ContainerExceptionInterface;
use Psr\Container\NotFoundExceptionInterface;

/**
 * Class CatalogiController
 * Controller for handling catalog-related operations in the OpenCatalogi app.
 *
 * @category  Controller
 * @package   opencatalogi
 * @author    Ruben Linde
 * @copyright 2024
 * @license   AGPL-3.0-or-later
 * @version   1.0.0
 * @link      https://github.com/opencatalogi/opencatalogi
 */
class CatalogiController extends Controller
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
     * CatalogiController constructor.
     *
     * @param string             $appName            The name of the app
     * @param IRequest           $request            The request object
     * @param CatalogiService    $catalogiService    The catalogi service
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
        private readonly CatalogiService $catalogiService,
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
     * Get the schema and register configuration for catalogs.
     *
     * @return array<string, string> Array containing schema and register configuration
     */
    private function getCatalogConfiguration(): array
    {
        // Get the catalog schema and register from configuration
        $schema   = $this->config->getValueString($this->appName, 'catalog_schema', '');
        $register = $this->config->getValueString($this->appName, 'catalog_register', '');

        return [
            'schema'   => $schema,
            'register' => $register,
        ];

    }//end getCatalogConfiguration()


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

    }//end preflightedCors()


    /**
     * Retrieve a list of publications based on all available catalogs.
     *
     * @param  string|integer|null $catalogId Optional ID of a specific catalog to filter by
     * @return JSONResponse JSON response containing the list of publications and total count
     * @throws ContainerExceptionInterface|NotFoundExceptionInterface
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     * @PublicPage
     */
    public function index(): JSONResponse
    {
        // Get catalog configuration from settings
        $catalogConfig = $this->getCatalogConfiguration();

        // Retrieve all request parameters
        $requestParams = $this->request->getParams();

        // Build search query for searchObjectsPaginated
        $searchQuery = $this->getObjectService()->buildSearchQuery($requestParams);

        // Add schema filter if configured
        if (!empty($catalogConfig['schema'])) {
            $searchQuery['@self']['schema'] = $catalogConfig['schema'];
        }

        // Add register filter if configured
        if (!empty($catalogConfig['register'])) {
            $searchQuery['@self']['register'] = $catalogConfig['register'];
        }

        // Fetch catalog objects using searchObjectsPaginated
        $result = $this->getObjectService()->searchObjectsPaginated(
            query: $searchQuery,
            _rbac: false,
            _multitenancy: false,
            published: false,
            deleted: false
        );

        // Add CORS headers for public API access
        $response = new JSONResponse($result);
        $origin   = isset($this->request->server['HTTP_ORIGIN']) ? $this->request->server['HTTP_ORIGIN'] : '*';
        $response->addHeader('Access-Control-Allow-Origin', $origin);
        $response->addHeader('Access-Control-Allow-Methods', $this->corsMethods);
        $response->addHeader('Access-Control-Allow-Headers', $this->corsAllowedHeaders);

        return $response;

    }//end index()


    /**
     * Retrieve a list of catalogs based on provided filters and parameters.
     *
     * @param  string|integer $id The ID of the catalog to use as a filter
     * @return JSONResponse JSON response containing the list of catalogs and total count
     * @throws ContainerExceptionInterface|NotFoundExceptionInterface
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     * @PublicPage
     */
    public function show(string | int $id): JSONResponse
    {
        // Get all objects using the catalog's registers and schemas as filters
        $response = $this->catalogiService->index($id);

        // Add CORS headers for public API access
        $origin = isset($this->request->server['HTTP_ORIGIN']) ? $this->request->server['HTTP_ORIGIN'] : '*';
        $response->addHeader('Access-Control-Allow-Origin', $origin);
        $response->addHeader('Access-Control-Allow-Methods', $this->corsMethods);
        $response->addHeader('Access-Control-Allow-Headers', $this->corsAllowedHeaders);

        return $response;

    }//end show()


}//end class
