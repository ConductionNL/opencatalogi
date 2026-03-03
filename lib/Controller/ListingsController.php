<?php
/**
 * Listings controller for OpenCatalogi.
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

use GuzzleHttp\Exception\GuzzleException;
use OCA\OpenCatalogi\Service\DirectoryService;
use OCA\OpenCatalogi\Exception\DirectoryUrlException;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\MultipleObjectsReturnedException;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IAppConfig;
use OCP\IRequest;
use OCP\App\IAppManager;
use Psr\Container\ContainerInterface;
use Psr\Container\ContainerExceptionInterface;
use Psr\Container\NotFoundExceptionInterface;

/**
 * Controller for handling Listing-related operations.
 */
class ListingsController extends Controller
{


    /**
     * Constructor for ListingsController.
     *
     * @param string             $appName          The name of the app.
     * @param IRequest           $request          The request object.
     * @param IAppConfig         $config           The app configuration.
     * @param ContainerInterface $container        Server container for dependency injection.
     * @param IAppManager        $appManager       App manager for checking installed apps.
     * @param DirectoryService   $directoryService The directory service.
     */
    public function __construct(
        string $appName,
        IRequest $request,
        private readonly IAppConfig $config,
        private readonly ContainerInterface $container,
        private readonly IAppManager $appManager,
        private readonly DirectoryService $directoryService
    ) {
        parent::__construct(appName: $appName, request: $request);

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
     * Retrieve a list of listings based on provided filters and parameters.
     *
     * @return JSONResponse JSON response containing the list of listings and total count.
     *
     * @throws DoesNotExistException When the object does not exist.
     * @throws MultipleObjectsReturnedException When multiple objects are returned.
     * @throws ContainerExceptionInterface When a container error occurs.
     * @throws NotFoundExceptionInterface When a service is not found.
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     */
    public function index(): JSONResponse
    {
        // Retrieve all request parameters.
        $requestParams = $this->request->getParams();

        // Get listing schema and register from configuration.
        $listingSchema   = $this->config->getValueString(app: 'opencatalogi', key: 'listing_schema', default: '');
        $listingRegister = $this->config->getValueString(app: 'opencatalogi', key: 'listing_register', default: '');

        // Build config for findAll.
        $config = [
            'filters' => [],
        ];

        // Add schema filter if configured.
        if (empty($listingSchema) === false) {
            $config['filters']['schema'] = $listingSchema;
        }

        // Add register filter if configured.
        if (empty($listingRegister) === false) {
            $config['filters']['register'] = $listingRegister;
        }

        // Add any additional filters from request params.
        if (isset($requestParams['filters']) === true) {
            $config['filters'] = array_merge($config['filters'], $requestParams['filters']);
        }

        // Add pagination and other params.
        if (isset($requestParams['limit']) === true) {
            $config['limit'] = (int) $requestParams['limit'];
        }

        if (isset($requestParams['offset']) === true) {
            $config['offset'] = (int) $requestParams['offset'];
        }

        // Fetch listing objects based on filters and order.
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
                ($result['results'] ?? [])
            ),
            'total'   => ($result['total'] ?? count(($result['results'] ?? []))),
        ];

        // Return JSON response.
        return new JSONResponse(data: $data);

    }//end index()


    /**
     * Retrieve a specific listing by its ID.
     *
     * @param string|integer $id The ID of the listing to retrieve.
     *
     * @return JSONResponse JSON response containing the requested listing.
     *
     * @throws DoesNotExistException When the object does not exist.
     * @throws MultipleObjectsReturnedException When multiple objects are returned.
     * @throws ContainerExceptionInterface When a container error occurs.
     * @throws NotFoundExceptionInterface When a service is not found.
     *
     * @PublicPage
     * @NoAdminRequired
     * @NoCSRFRequired
     */
    public function show(string | int $id): JSONResponse
    {
        // Fetch the listing object by its ID.
        $object = $this->getObjectService()->find($id);

        // Convert to array if it's an Entity.
        if ($object instanceof \OCP\AppFramework\Db\Entity) {
            $data = $object->jsonSerialize();
        } else {
            $data = $object;
        }

        // Return the listing as a JSON response.
        return new JSONResponse(data: $data);

    }//end show()


    /**
     * Create a new listing.
     *
     * @return JSONResponse The response containing the created listing object.
     *
     * @throws DoesNotExistException When the object does not exist.
     * @throws MultipleObjectsReturnedException When multiple objects are returned.
     * @throws ContainerExceptionInterface When a container error occurs.
     * @throws NotFoundExceptionInterface When a service is not found.
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     */
    public function create(): JSONResponse
    {
        // Get all parameters from the request.
        $data = $this->request->getParams();

        // Remove the 'id' field if it exists, as we're creating a new object.
        unset($data['id']);

        // Save the new listing object.
        $object = $this->getObjectService()->saveObject('listing', $data);

        // Return the created object as a JSON response.
        return new JSONResponse(data: $object);

    }//end create()


    /**
     * Update an existing listing.
     *
     * @param string|integer $id The ID of the listing to update.
     *
     * @return JSONResponse The response containing the updated listing object.
     *
     * @throws DoesNotExistException When the object does not exist.
     * @throws MultipleObjectsReturnedException When multiple objects are returned.
     * @throws ContainerExceptionInterface When a container error occurs.
     * @throws NotFoundExceptionInterface When a service is not found.
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     */
    public function update(string | int $id): JSONResponse
    {
        // Get all parameters from the request.
        $data = $this->request->getParams();

        // Ensure the ID in the data matches the ID in the URL.
        $data['id'] = $id;

        // Save the updated listing object.
        $object = $this->getObjectService()->saveObject('listing', $data);

        // Return the updated object as a JSON response.
        return new JSONResponse(data: $object);

    }//end update()


    /**
     * Delete a listing.
     *
     * @param string|integer $id The ID of the listing to delete.
     *
     * @return JSONResponse The response indicating the result of the deletion.
     *
     * @throws ContainerExceptionInterface When a container error occurs.
     * @throws NotFoundExceptionInterface When a service is not found.
     * @throws \OCP\DB\Exception When a database error occurs.
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     */
    public function destroy(string | int $id): JSONResponse
    {
        // Delete the listing object.
        $result = $this->getObjectService()->deleteObject('listing', $id);

        // Return the result as a JSON response.
        if ($result === true) {
            $statusCode = '200';
        } else {
            $statusCode = '404';
        }

        return new JSONResponse(data: ['success' => $result], statusCode: $statusCode);

    }//end destroy()


    /**
     * Synchronize a listing or all listings.
     *
     * @param string|null $id The ID of the listing to synchronize (optional).
     *
     * @return JSONResponse The response indicating the result of the synchronization.
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     */
    public function synchronise(?string $id = null): JSONResponse
    {
        // Synchronize the specified listing or all listings.
        $result = $this->directoryService->doCronSync();

        // Return the result as a JSON response.
        return new JSONResponse(data: ['success' => $result]);

    }//end synchronise()


    /**
     * Add a new listing from a URL.
     *
     * @return JSONResponse The response indicating the result of adding the listing.
     *
     * @throws GuzzleException When an HTTP error occurs.
     *
     * @PublicPage
     * @NoAdminRequired
     * @NoCSRFRequired
     */
    public function add(): JSONResponse
    {
        // Get the URL parameter from the request.
        $url = $this->request->getParam('url');

        // Add the new listing using the provided URL.
        try {
            $result = $this->directoryService->syncDirectory($url);
        } catch (DirectoryUrlException $exception) {
            if ($exception->getMessage() === 'URL is required') {
                $exception->setMessage('Property "url" is required');
            }

            return new JSONResponse(data: ['message' => $exception->getMessage()], statusCode: 400);
        }

        // Return the result as a JSON response.
        return new JSONResponse(data: ['success' => $result]);

    }//end add()


}//end class
