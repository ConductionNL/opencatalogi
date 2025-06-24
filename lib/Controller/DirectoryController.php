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
	 * @PublicPage
	 * @NoCSRFRequired
	 */
	public function index(): JSONResponse
	{
		try {
			// Get all directories from the directory service
			$directories = $this->directoryService->getUniqueDirectories();
			
			// Format the response to match expected structure
			$data = [
				'results' => $directories,
				'count' => count($directories),
				'total' => count($directories)
			];

			// Return JSON response with the directory data
			return new JSONResponse($data);
		} catch (\Exception $e) {
			return new JSONResponse([
				'message' => 'Failed to retrieve directories',
				'error' => $e->getMessage()
			], 500);
		}
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
	 * @PublicPage
	 * @NoCSRFRequired
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
	 * Show a specific directory
	 *
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 * @param string|int $id The ID of the directory to show
	 * @return JSONResponse The JSON response containing the directory details
	 */
	public function show(string|int $id): JSONResponse
	{
		// TODO: Implement the logic to retrieve and return the specific directory
		// This method is currently empty and needs to be implemented

		return new JSONResponse([]);
	}

	/**
	 * Get a specific publication type, used by external applications to synchronyse
	 *
	 * @PublicPage
	 * @NoCSRFRequired
	 * @param string|int $id The ID of the publication type to retrieve
	 * @return JSONResponse The JSON response containing the publication type details
	 */
	public function publicationType(string|int $id): JSONResponse
	{
		try {
			$publicationType = $this->getObjectService()->getObject('publicationType', $id);
			return new JSONResponse($publicationType);
		} catch (DoesNotExistException $e) {
			return new JSONResponse(['error' => 'Publication type not found'], 404);
		} catch (\Exception $e) {
			return new JSONResponse(['error' => 'An error occurred while retrieving the publication type'], 500);
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
