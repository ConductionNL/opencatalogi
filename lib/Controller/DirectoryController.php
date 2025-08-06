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
 * Class DirectoryController
 *
 * Controller for handling directory-related operations in the OpenCatalogi app.
 *
 * @category  Controller
 * @package   opencatalogi
 * @author    Ruben van der Linde
 * @copyright 2024
 * @license   AGPL-3.0-or-later
 * @version   1.0.0
 * @link      https://github.com/opencatalogi/opencatalogi
 */
class DirectoryController extends Controller
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
     * DirectoryController constructor.
     *
     * @param string           $appName            The name of the app
     * @param IRequest         $request            The request object
     * @param DirectoryService $directoryService   The directory service
     * @param string           $corsMethods        Allowed CORS methods
     * @param string           $corsAllowedHeaders Allowed CORS headers
     * @param int              $corsMaxAge         CORS max age
     */
    public function __construct(
        $appName,
        IRequest $request,
        private readonly DirectoryService $directoryService,
        string $corsMethods = 'PUT, POST, GET, DELETE, PATCH',
        string $corsAllowedHeaders = 'Authorization, Content-Type, Accept',
        int $corsMaxAge = 1728000
    ) {
        parent::__construct($appName, $request);
        $this->corsMethods = $corsMethods;
        $this->corsAllowedHeaders = $corsAllowedHeaders;
        $this->corsMaxAge = $corsMaxAge;
    }


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
            
            // Create JSON response with CORS headers
            $response = new JSONResponse($data);
            $origin = isset($this->request->server['HTTP_ORIGIN']) ? $this->request->server['HTTP_ORIGIN'] : '*';
            $response->addHeader('Access-Control-Allow-Origin', $origin);
            $response->addHeader('Access-Control-Allow-Methods', $this->corsMethods);
            $response->addHeader('Access-Control-Allow-Headers', $this->corsAllowedHeaders);
            
            return $response;
        } catch (\Exception $e) {
            // Handle errors gracefully with CORS headers
            $response = new JSONResponse([
                'message' => 'Failed to retrieve directory data',
                'error' => $e->getMessage()
            ], 500);
            $origin = isset($this->request->server['HTTP_ORIGIN']) ? $this->request->server['HTTP_ORIGIN'] : '*';
            $response->addHeader('Access-Control-Allow-Origin', $origin);
            $response->addHeader('Access-Control-Allow-Methods', $this->corsMethods);
            $response->addHeader('Access-Control-Allow-Headers', $this->corsAllowedHeaders);
            
            return $response;
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
            $response = new JSONResponse([
                'message' => 'Property "directory" is required',
                'error' => 'Missing directory URL parameter'
            ], 400);
        } else {
            // Sync the directory with the provided URL
            try {
                $data = $this->directoryService->syncDirectory($directoryUrl);
                
                // Return success response with sync results
                $response = new JSONResponse([
                    'message' => 'Directory synchronized successfully',
                    'data' => $data
                ]);
                
            } catch (\InvalidArgumentException $e) {
                // Handle validation errors (invalid URL, etc.)
                $response = new JSONResponse([
                    'message' => 'Invalid directory URL',
                    'error' => $e->getMessage()
                ], 400);
                
            } catch (GuzzleException $e) {
                // Handle HTTP/network errors
                $response = new JSONResponse([
                    'message' => 'Failed to fetch directory data',
                    'error' => $e->getMessage()
                ], 502);
                
            } catch (\Exception $e) {
                // Handle other unexpected errors
                $response = new JSONResponse([
                    'message' => 'Directory synchronization failed',
                    'error' => $e->getMessage()
                ], 500);
            }
        }

        // Add CORS headers for public API access
        $origin = isset($this->request->server['HTTP_ORIGIN']) ? $this->request->server['HTTP_ORIGIN'] : '*';
        $response->addHeader('Access-Control-Allow-Origin', $origin);
        $response->addHeader('Access-Control-Allow-Methods', $this->corsMethods);
        $response->addHeader('Access-Control-Allow-Headers', $this->corsAllowedHeaders);

        return $response;
    }


}
