<?php

namespace OCA\OpenCatalogi\Controller;

use OCA\OpenCatalogi\Service\DirectoryService;
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
 * Controller for handling Listing-related operations
 */
class ListingsController extends Controller
{


    /**
     * Constructor for ListingsController
     *
     * @param string             $appName          The name of the app
     * @param IRequest           $request          The request object
     * @param IAppConfig         $config           The app configuration
     * @param ContainerInterface $container        Server container for dependency injection
     * @param IAppManager        $appManager       App manager for checking installed apps
     * @param DirectoryService   $directoryService The directory service
     */
    public function __construct(
        $appName,
        IRequest $request,
        private readonly IAppConfig $config,
        private readonly ContainerInterface $container,
        private readonly IAppManager $appManager,
        private readonly DirectoryService $directoryService
    ) {
        parent::__construct($appName, $request);

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
     * Retrieve a list of listings based on provided filters and parameters.
     *
     * @return JSONResponse JSON response containing the list of listings and total count
     * @throws DoesNotExistException|MultipleObjectsReturnedException|ContainerExceptionInterface|NotFoundExceptionInterface
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     */
    public function index(): JSONResponse
    {
        // Retrieve all request parameters
        $requestParams = $this->request->getParams();

        // Get listing schema and register from configuration
        $listingSchema   = $this->config->getValueString('opencatalogi', 'listing_schema', '');
        $listingRegister = $this->config->getValueString('opencatalogi', 'listing_register', '');

        // Build query for searchObjectsPaginated
        $query = [];

        // Add metadata filters
        if (!empty($listingSchema) || !empty($listingRegister)) {
            $query['@self'] = [];
            if (!empty($listingSchema)) {
                $query['@self']['schema'] = $listingSchema;
            }

            if (!empty($listingRegister)) {
                $query['@self']['register'] = $listingRegister;
            }
        }

        // Add any additional filters from request params
        if (isset($requestParams['filters'])) {
            foreach ($requestParams['filters'] as $key => $value) {
                if (!in_array($key, ['schema', 'register'])) {
                    $query[$key] = $value;
                }
            }
        }

        // Add pagination and other params
        if (isset($requestParams['limit'])) {
            $query['_limit'] = (int) $requestParams['limit'];
        }

        if (isset($requestParams['offset'])) {
            $query['_offset'] = (int) $requestParams['offset'];
        }

        // Fetch listing objects using searchObjectsPaginated (handles pagination internally)
        $data = $this->getObjectService()->searchObjectsPaginated($query);

        // Return JSON response
        return new JSONResponse($data);

    }//end index()


    /**
     * Retrieve a specific listing by its ID.
     *
     * @param string|integer $id The ID of the listing to retrieve
     *
     * @return JSONResponse JSON response containing the requested listing
     * @throws DoesNotExistException|MultipleObjectsReturnedException|ContainerExceptionInterface|NotFoundExceptionInterface
     *
     * @PublicPage
     * @NoAdminRequired
     * @NoCSRFRequired
     */
    public function show(string | int $id): JSONResponse
    {
        // Get listing schema and register from configuration
        $listingRegister = $this->config->getValueString('opencatalogi', 'listing_register', '');
        $listingSchema   = $this->config->getValueString('opencatalogi', 'listing_schema', '');

        // Fetch the listing object by its ID with register/schema context
        $object = $this->getObjectService()->find($id, [], false, $listingRegister, $listingSchema);

        // Convert to array if it's an Entity
        $data = $object instanceof \OCP\AppFramework\Db\Entity ? $object->jsonSerialize() : $object;

        // Return the listing as a JSON response
        return new JSONResponse($data);

    }//end show()


    /**
     * Create a new listing.
     *
     * @return JSONResponse The response containing the created listing object.
     * @throws DoesNotExistException|MultipleObjectsReturnedException|ContainerExceptionInterface|NotFoundExceptionInterface
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     */
    public function create(): JSONResponse
    {
        // Get all parameters from the request
        $data = $this->request->getParams();

        // Remove internal/framework fields
        unset($data['id'], $data['_route']);

        // Get listing schema and register from configuration
        $listingRegister = $this->config->getValueString('opencatalogi', 'listing_register', '');
        $listingSchema   = $this->config->getValueString('opencatalogi', 'listing_schema', '');

        // Save the new listing object
        $object = $this->getObjectService()->saveObject($data, [], $listingRegister, $listingSchema);

        // Return the created object as a JSON response
        return new JSONResponse($object);

    }//end create()


    /**
     * Update an existing listing.
     *
     * @param string|integer $id The ID of the listing to update.
     *
     * @return JSONResponse The response containing the updated listing object.
     * @throws DoesNotExistException|MultipleObjectsReturnedException|ContainerExceptionInterface|NotFoundExceptionInterface
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     */
    public function update(string | int $id): JSONResponse
    {
        // Get all parameters from the request
        $data = $this->request->getParams();

        // Remove internal/framework fields
        unset($data['_route']);

        // Get listing schema and register from configuration
        $listingRegister = $this->config->getValueString('opencatalogi', 'listing_register', '');
        $listingSchema   = $this->config->getValueString('opencatalogi', 'listing_schema', '');

        // Save the updated listing object (pass id as UUID for update)
        $object = $this->getObjectService()->saveObject($data, [], $listingRegister, $listingSchema, (string) $id);

        // Return the updated object as a JSON response
        return new JSONResponse($object);

    }//end update()


    /**
     * Delete a listing.
     *
     * @param string|integer $id The ID of the listing to delete.
     *
     * @return JSONResponse The response indicating the result of the deletion.
     * @throws ContainerExceptionInterface|NotFoundExceptionInterface|\OCP\DB\Exception
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     */
    public function destroy(string | int $id): JSONResponse
    {
        // Delete the listing object by its UUID
        $result = $this->getObjectService()->deleteObject((string) $id);

        // Return the result as a JSON response
        return new JSONResponse(['success' => $result], $result === true ? '200' : '404');

    }//end destroy()


    /**
     * Synchronize a specific directory or all directories.
     *
     * When an ID is provided, the corresponding listing is looked up and its
     * directory URL is synced. When no ID is provided, all known directories
     * are synced (equivalent to a cron sync).
     *
     * @param string|null $id The ID of the listing whose directory to synchronize (optional).
     *
     * @return JSONResponse The response containing synchronization results.
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     */
    public function synchronise(?string $id = null): JSONResponse
    {
        try {
            if ($id !== null) {
                // Look up the listing to get its directory URL
                $listingRegister = $this->config->getValueString('opencatalogi', 'listing_register', '');
                $listingSchema   = $this->config->getValueString('opencatalogi', 'listing_schema', '');
                $object          = $this->getObjectService()->find($id, [], false, $listingRegister, $listingSchema);
                $objectData      = $object instanceof \OCP\AppFramework\Db\Entity ? $object->jsonSerialize() : $object;
                $listingData = $objectData['object'] ?? $objectData;

                $directoryUrl = $listingData['directory'] ?? null;
                if (empty($directoryUrl)) {
                    return new JSONResponse(
                        data: ['message' => 'Listing has no directory URL configured'],
                        statusCode: 400
                    );
                }

                $result = $this->directoryService->syncDirectory($directoryUrl);
            } else {
                // Sync all known directories
                $result = $this->directoryService->doCronSync();
            }

            return new JSONResponse($result);
        } catch (\Exception $e) {
            return new JSONResponse(
                data: ['message' => 'Synchronization failed: ' . $e->getMessage()],
                statusCode: 500
            );
        }

    }//end synchronise()


    /**
     * Add a new listing from a URL.
     *
     * @return JSONResponse The response indicating the result of adding the listing.
     *
     * @PublicPage
     * @NoAdminRequired
     * @NoCSRFRequired
     */
    public function add(): JSONResponse
    {
        // Get the URL parameter from the request
        $url = $this->request->getParam('url');

        if (empty($url)) {
            return new JSONResponse(data: ['message' => 'Property "url" is required'], statusCode: 400);
        }

        // Add the new listing by syncing the provided directory URL
        try {
            $result = $this->directoryService->syncDirectory($url);
        } catch (\InvalidArgumentException $exception) {
            return new JSONResponse(data: ['message' => $exception->getMessage()], statusCode: 400);
        } catch (\Exception $exception) {
            return new JSONResponse(data: ['message' => $exception->getMessage()], statusCode: 500);
        }

        // Return the result as a JSON response
        return new JSONResponse($result);

    }//end add()


}//end class
