<?php

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

/**
 * Class RobotsController
 *
 * @category  Controller
 * @package   opencatalogi
 * @author    Barry Brands
 * @copyright 2024
 * @license   AGPL-3.0-or-later
 * @version   1.0.0
 * @link      https://github.com/opencatalogi/opencatalogi
 */
class RobotsController extends Controller
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
     * @param int                $corsMaxAge         CORS max age
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
        $this->corsMethods = $corsMethods;
        $this->corsAllowedHeaders = $corsAllowedHeaders;
        $this->corsMaxAge = $corsMaxAge;

    }//end __construct()


    /**
     * @NoAdminRequired
     * @NoCSRFRequired
     * @PublicPage
     */
    public function robots(): Response
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
            rbac: false,
            multi: false,
            published: false,
            deleted: false
        );

        // Build plain text output
        $lines = [];
        foreach ($result as $catalog) {
            if (empty($catalog)) {
                $lines[] = $catalog['endpoint'] . '/sitemap.xml';
            }
        }

        // Combine into plain text
        $content = implode("\n", $lines);

        // Return as plain text response
        $response = new Response();
        $response->setStatus(200);
        $response->addHeader('Content-Type', 'text/plain; charset=utf-8');
        $response->setContent($content);

        return $response;

    }


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



}//end class
