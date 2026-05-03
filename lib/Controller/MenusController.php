<?php
/**
 * OpenCatalogi Menus Controller.
 *
 * Controller for handling menu-related operations in the OpenCatalogi app.
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
 * Controller for handling menu-related operations.
 */
class MenusController extends Controller
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
     * MenusController constructor.
     *
     * @param string             $appName            The name of the app.
     * @param IRequest           $request            The request object.
     * @param IAppConfig         $config             App configuration interface.
     * @param ContainerInterface $container          Server container for DI.
     * @param IAppManager        $appManager         App manager.
     * @param IL10N              $l10n               The localization service.
     * @param string             $corsMethods        Allowed CORS methods.
     * @param string             $corsAllowedHeaders Allowed CORS headers.
     * @param integer            $corsMaxAge         CORS max age.
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
     * Get the schema and register configuration for menus.
     *
     * @return array<string, string> Array containing schema and register configuration.
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
     * @return Response The CORS response.
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
     * Get all menus with pagination support.
     *
     * @return JSONResponse The JSON response containing the list of menus.
     *
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
        $searchQuery['_schema'] = '7';
        if (empty($menuConfig['schema']) === false) {
            $searchQuery['_schema'] = $menuConfig['schema'];
        }

        // Add register filter - use _register for magic mapper routing.
        $searchQuery['_register'] = '1';
        if (empty($menuConfig['register']) === false) {
            $searchQuery['_register'] = $menuConfig['register'];
        }

        // Use searchObjectsPaginated for better performance and pagination support.
        $result = $this->getObjectService()->searchObjectsPaginated(
            $searchQuery,
            _rbac: false,
            _multitenancy: false
        );

        // Add CORS headers for public API access.
        $response = new JSONResponse($result);
        $origin   = $this->request->server['HTTP_ORIGIN'] ?? '*';

        $response->addHeader('Access-Control-Allow-Origin', $origin);
        $response->addHeader('Access-Control-Allow-Methods', $this->corsMethods);
        $response->addHeader('Access-Control-Allow-Headers', $this->corsAllowedHeaders);

        return $response;

    }//end index()

    /**
     * Get a specific menu by its ID.
     *
     * @param string|integer $id The ID of the menu to retrieve.
     *
     * @return JSONResponse The JSON response containing the menu details.
     *
     * @throws ContainerExceptionInterface|NotFoundExceptionInterface
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     * @PublicPage
     */
    public function show(string|int $id): JSONResponse
    {
        // Use searchObjectsPaginated to find single menu.
        $searchQuery = [
            '_ids'    => [$id],
            '_limit'  => 1,
            '_source' => 'database',
        ];
        $result      = $this->getObjectService()->searchObjectsPaginated(
            $searchQuery,
            _rbac: false,
            _multitenancy: false
        );

        if (empty($result['results']) === true) {
            return new JSONResponse(data: ['error' => $this->l10n->t('Menu not found')], statusCode: 404);
        }

        $menu = $result['results'][0];

        $data = $menu;
        if ($menu instanceof \OCP\AppFramework\Db\Entity) {
            $data = $menu->jsonSerialize();
        }

        // Add CORS headers for public API access.
        $response = new JSONResponse($data);
        $origin   = $this->request->server['HTTP_ORIGIN'] ?? '*';

        $response->addHeader('Access-Control-Allow-Origin', $origin);
        $response->addHeader('Access-Control-Allow-Methods', $this->corsMethods);
        $response->addHeader('Access-Control-Allow-Headers', $this->corsAllowedHeaders);

        return $response;

    }//end show()
}//end class
