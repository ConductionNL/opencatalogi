<?php

namespace OCA\OpenCatalogi\Controller;

use GuzzleHttp\Exception\GuzzleException;
use OCA\OpenCatalogi\Service\DirectoryService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\MultipleObjectsReturnedException;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IAppConfig;
use OCP\IRequest;
use OCP\App\IAppManager;
use Psr\Container\ContainerInterface;
use Psr\Container\ContainerExceptionInterface;
use Psr\Container\NotFoundExceptionInterface;

/**
 * Controller for handling directory-related operations
 */
class DirectoryController extends Controller
{
    /**
     * Constructor for DirectoryController
     *
     * @param string $appName The name of the app
     * @param IRequest $request The request object
     * @param IAppConfig $config The app configuration
     * @param ContainerInterface $container Server container for dependency injection
     * @param IAppManager $appManager App manager for checking installed apps
     * @param DirectoryService $directoryService The directory service
     */
    public function __construct(
		$appName,
		IRequest $request,
		private readonly IAppConfig $config,
		private readonly ContainerInterface $container,
		private readonly IAppManager $appManager,
		private readonly DirectoryService $directoryService
	)
    {
        parent::__construct($appName, $request);
    }

	/**
	 * Retrieve all directories
	 *
	 * @return JSONResponse The JSON response containing all directories
	 * @throws DoesNotExistException|MultipleObjectsReturnedException|ContainerExceptionInterface|NotFoundExceptionInterface
	 *
     * @NoAdminRequired
	 * @NoCSRFRequired
	 * @PublicPage
	 */
	public function index(): JSONResponse
	{
		
        // Retrieve all request parameters
        $requestParams = $this->request->getParams();

        // Get listing schema and register from configuration
        $listingSchema   = $this->config->getValueString('opencatalogi', 'listing_schema', '');
        $listingRegister = $this->config->getValueString('opencatalogi', 'listing_register', '');

        // Build config for findAll
        $config = [
            'filters' => []
        ];

        // Add schema filter if configured
        if (!empty($listingSchema)) {
            $config['filters']['schema'] = $listingSchema;
        }

        // Add register filter if configured
        if (!empty($listingRegister)) {
            $config['filters']['register'] = $listingRegister;
        }
        
        // Add any additional filters from request params
        if (isset($requestParams['filters'])) {
            $config['filters'] = array_merge($config['filters'], $requestParams['filters']);
        }
        
        // Add pagination and other params
        if (isset($requestParams['limit'])) {
            $config['limit'] = (int) $requestParams['limit'];
        }
        if (isset($requestParams['offset'])) {
            $config['offset'] = (int) $requestParams['offset'];
        }

        // Fetch listing objects based on filters and order
        $result = $this->getObjectService()->findAll($config);
        
        // Convert objects to arrays
        $data = [
            'results' => array_map(function ($object) {
                return $object instanceof \OCP\AppFramework\Db\Entity ? $object->jsonSerialize() : $object;
            }, $result['results'] ?? []),
            'total' => $result['total'] ?? count($result['results'] ?? [])
        ];

        // Return JSON response
        return new JSONResponse($data);
	}

	/**
	 * Synchronize with an external directory
	 *
	 * Synchronizes listings from a specific external directory URL.
	 * Accepts a 'directory' parameter containing the URL to sync with.
	 *
	 * @return JSONResponse The JSON response containing the synchronization result
	 * @throws DoesNotExistException|MultipleObjectsReturnedException|ContainerExceptionInterface|NotFoundExceptionInterface
	 * @throws GuzzleException
	 *
     * @NoAdminRequired
     * @NoCSRFRequired
     * @PublicPage
	 */
	public function update(): JSONResponse
	{
		// Get the directory URL from the request parameters
		$directoryUrl = $this->request->getParam('directory');

		// Validate that directory URL is provided
		if (empty($directoryUrl)) {
			return new JSONResponse([
				'message' => 'Property "directory" is required',
				'error' => 'Missing directory URL parameter'
			], 400);
		}

		// Sync the directory with the provided URL
		try {
			$data = $this->directoryService->syncDirectory($directoryUrl);
			
			// Return success response with sync results
			return new JSONResponse([
				'message' => 'Directory synchronized successfully',
				'data' => $data
			]);
			
		} catch (\InvalidArgumentException $e) {
			// Handle validation errors (invalid URL, etc.)
			return new JSONResponse([
				'message' => 'Invalid directory URL',
				'error' => $e->getMessage()
			], 400);
			
		} catch (GuzzleException $e) {
			// Handle HTTP/network errors
			return new JSONResponse([
				'message' => 'Failed to fetch directory data',
				'error' => $e->getMessage()
			], 502);
			
		} catch (\Exception $e) {
			// Handle other unexpected errors
			return new JSONResponse([
				'message' => 'Directory synchronization failed',
				'error' => $e->getMessage()
			], 500);
		}
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

}
