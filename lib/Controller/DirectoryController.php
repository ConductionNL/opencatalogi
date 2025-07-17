<?php

namespace OCA\OpenCatalogi\Controller;

use GuzzleHttp\Exception\GuzzleException;
use OCA\OpenCatalogi\Service\DirectoryService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\MultipleObjectsReturnedException;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
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
     * @param DirectoryService $directoryService The directory service
     */
    public function __construct(
		$appName,
		IRequest $request,
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
		try {
			// Retrieve all request parameters
			$requestParams = $this->request->getParams();
			
			// Use the directory service to get combined directory data
			$data = $this->directoryService->getDirectory($requestParams);
			
			// Return JSON response
			return new JSONResponse($data);
		} catch (\Exception $e) {
			// Handle errors gracefully
			return new JSONResponse([
				'message' => 'Failed to retrieve directory data',
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



}
