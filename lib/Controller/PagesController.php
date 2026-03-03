<?php
/**
 * Pages controller for OpenCatalogi.
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

declare(strict_types=1);

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
     * PagesController constructor.
     *
     * @param string             $appName            The name of the app.
     * @param IRequest           $request            The request object.
     * @param IAppConfig         $config             App configuration interface.
     * @param ContainerInterface $container          Server container for dependency injection.
     * @param IAppManager        $appManager         App manager for checking installed apps.
     * @param string             $corsMethods        Allowed CORS methods.
     * @param string             $corsAllowedHeaders Allowed CORS headers.
     * @param integer            $corsMaxAge         CORS max age.
     */
    public function __construct(
        string $appName,
        IRequest $request,
        private readonly IAppConfig $config,
        private readonly ContainerInterface $container,
        private readonly IAppManager $appManager,
        string $corsMethods = 'PUT, POST, GET, DELETE, PATCH',
        string $corsAllowedHeaders = 'Authorization, Content-Type, Accept',
        int $corsMaxAge = 1728000
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
     *
     * @throws ContainerExceptionInterface When a container error occurs.
     * @throws NotFoundExceptionInterface When a service is not found.
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
     * @return array<string, string> Array containing schema and register configuration.
     */
    private function getPageConfiguration(): array
    {
        // Get the page schema and register from configuration.
        $schema   = $this->config->getValueString(app: $this->appName, key: 'page_schema', default: '');
        $register = $this->config->getValueString(app: $this->appName, key: 'page_register', default: '');

        return [
            'schema'   => $schema,
            'register' => $register,
        ];

    }//end getPageConfiguration()


    /**
     * Implements a preflighted CORS response for OPTIONS requests.
     *
     * @return \OCP\AppFramework\Http\Response The CORS response.
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     * @PublicPage
     */
    public function preflightedCors(): \OCP\AppFramework\Http\Response
    {
        // Determine the origin.
        $header = $this->request->getHeader('Origin');
        if (empty($header) === false) {
            $origin = $header;
        } else if (isset($this->request->server['HTTP_ORIGIN']) === true) {
            $origin = $this->request->server['HTTP_ORIGIN'];
        } else {
            $origin = '*';
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
     * Get all pages.
     *
     * @return JSONResponse The JSON response containing the list of pages.
     *
     * @throws ContainerExceptionInterface When a container error occurs.
     * @throws NotFoundExceptionInterface When a service is not found.
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     * @PublicPage
     */
    public function index(): JSONResponse
    {
        // Get page configuration from settings.
        $pageConfig = $this->getPageConfiguration();

        // Build config for findAll to get pages.
        $config = [
            'filters' => [],
        ];

        // Add schema filter if configured.
        if (empty($pageConfig['schema']) === false) {
            $config['filters']['schema'] = $pageConfig['schema'];
        }

        // Add register filter if configured.
        if (empty($pageConfig['register']) === false) {
            $config['filters']['register'] = $pageConfig['register'];
        }

        $result = $this->getObjectService()->findAll($config);

        // Convert objects to arrays.
        $data = [
            'results' => array_map(
                function ($object) {
                    if ($object instanceof \OCP\AppFramework\Db\Entity) {
                        return $object->jsonSerialize();
                    }

                    return $object;
                },
                ($result ?? [])
            ),
            'total'   => count(($result ?? [])),
        ];

        // Add CORS headers for public API access.
        $response = new JSONResponse(data: $data);
        $header   = $this->request->getHeader('Origin');
        if (empty($header) === false) {
            $origin = $header;
        } else if (isset($this->request->server['HTTP_ORIGIN']) === true) {
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
     * Get a specific page by its slug.
     *
     * @param string $slug The slug of the page to retrieve.
     *
     * @return JSONResponse The JSON response containing the page details.
     *
     * @throws ContainerExceptionInterface When a container error occurs.
     * @throws NotFoundExceptionInterface When a service is not found.
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     * @PublicPage
     */
    public function show(string $slug): JSONResponse
    {
        // Get page configuration from settings.
        $pageConfig = $this->getPageConfiguration();

        // Build config to find page by slug.
        $config = [
            'filters' => ['slug' => $slug],
        ];

        // Add schema filter if configured.
        if (empty($pageConfig['schema']) === false) {
            $config['filters']['schema'] = $pageConfig['schema'];
        }

        // Add register filter if configured.
        if (empty($pageConfig['register']) === false) {
            $config['filters']['register'] = $pageConfig['register'];
        }

        $pages = $this->getObjectService()->findAll($config);

        if (empty($pages) === true) {
            $response = new JSONResponse(data: ['error' => 'Page not found'], statusCode: 404);
        } else {
            // Return the first matching page.
            if ($pages[0] instanceof \OCP\AppFramework\Db\Entity) {
                $page = $pages[0]->jsonSerialize();
            } else {
                $page = $pages[0];
            }

            $response = new JSONResponse(data: $page);
        }

        // Add CORS headers for public API access.
        $header = $this->request->getHeader('Origin');
        if (empty($header) === false) {
            $origin = $header;
        } else if (isset($this->request->server['HTTP_ORIGIN']) === true) {
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
