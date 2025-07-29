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
     * Get all themes.
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

        // Build config for findAll to get themes
        $config = [
            'filters' => []
        ];

        // Add schema filter if configured
        if (!empty($themeConfig['schema'])) {
            $config['filters']['schema'] = $themeConfig['schema'];
        }

        // Add register filter if configured
        if (!empty($themeConfig['register'])) {
            $config['filters']['register'] = $themeConfig['register'];
        }

        $result = $this->getObjectService()->findAll($config);
        
        $data = [
            'results' => array_map(function ($object) {
                return $object instanceof \OCP\AppFramework\Db\Entity ? $object->jsonSerialize() : $object;
            }, $result ?? []),
            'total' => count($result ?? [])
        ];

        // Add CORS headers for public API access
        $response = new JSONResponse($data);
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
