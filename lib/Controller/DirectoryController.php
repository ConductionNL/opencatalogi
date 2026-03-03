<?php
/**
 * Directory controller for OpenCatalogi.
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
use OCP\AppFramework\Controller;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\MultipleObjectsReturnedException;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use Psr\Container\ContainerExceptionInterface;
use Psr\Container\NotFoundExceptionInterface;

/**
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
     * DirectoryController constructor.
     *
     * @param string           $appName            The name of the app.
     * @param IRequest         $request            The request object.
     * @param DirectoryService $directoryService   The directory service.
     * @param string           $corsMethods        Allowed CORS methods.
     * @param string           $corsAllowedHeaders Allowed CORS headers.
     * @param integer          $corsMaxAge         CORS max age.
     */
    public function __construct(
        string $appName,
        IRequest $request,
        private readonly DirectoryService $directoryService,
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
        if (isset($this->request->server['HTTP_ORIGIN']) === true) {
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
     * Retrieve all directories.
     *
     * @return JSONResponse The JSON response containing all directories.
     *
     * @throws DoesNotExistException When the object does not exist.
     * @throws MultipleObjectsReturnedException When multiple objects are returned.
     * @throws ContainerExceptionInterface When a container error occurs.
     * @throws NotFoundExceptionInterface When a service is not found.
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     * @PublicPage
     */
    public function index(): JSONResponse
    {
        try {
            // Retrieve all request parameters.
            $requestParams = $this->request->getParams();

            // Use the directory service to get combined directory data.
            $data = $this->directoryService->getDirectory($requestParams);

            // Create JSON response with CORS headers.
            $response = new JSONResponse(data: $data);
            if (isset($this->request->server['HTTP_ORIGIN']) === true) {
                $origin = $this->request->server['HTTP_ORIGIN'];
            } else {
                $origin = '*';
            }

            $response->addHeader('Access-Control-Allow-Origin', $origin);
            $response->addHeader('Access-Control-Allow-Methods', $this->corsMethods);
            $response->addHeader('Access-Control-Allow-Headers', $this->corsAllowedHeaders);

            return $response;
        } catch (\Exception $e) {
            // Handle errors gracefully with CORS headers.
            $response = new JSONResponse(
                data: [
                    'message' => 'Failed to retrieve directory data',
                    'error'   => $e->getMessage(),
                ],
                statusCode: 500
            );
            if (isset($this->request->server['HTTP_ORIGIN']) === true) {
                $origin = $this->request->server['HTTP_ORIGIN'];
            } else {
                $origin = '*';
            }

            $response->addHeader('Access-Control-Allow-Origin', $origin);
            $response->addHeader('Access-Control-Allow-Methods', $this->corsMethods);
            $response->addHeader('Access-Control-Allow-Headers', $this->corsAllowedHeaders);

            return $response;
        }//end try

    }//end index()


    /**
     * Synchronize with an external directory.
     *
     * Synchronizes listings from a specific external directory URL.
     * Accepts a 'directory' parameter containing the URL to sync with.
     *
     * @return JSONResponse The JSON response containing the synchronization result.
     *
     * @throws DoesNotExistException When the object does not exist.
     * @throws MultipleObjectsReturnedException When multiple objects are returned.
     * @throws ContainerExceptionInterface When a container error occurs.
     * @throws NotFoundExceptionInterface When a service is not found.
     * @throws GuzzleException When an HTTP error occurs.
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     * @PublicPage
     */
    public function update(): JSONResponse
    {
        // Get the directory URL from the request parameters.
        $directoryUrl = $this->request->getParam('directory');

        // Validate that directory URL is provided.
        if (empty($directoryUrl) === true) {
            $response = new JSONResponse(
                data: [
                    'message' => 'Property "directory" is required',
                    'error'   => 'Missing directory URL parameter',
                ],
                statusCode: 400
            );
        } else {
            // Sync the directory with the provided URL.
            try {
                $data = $this->directoryService->syncDirectory($directoryUrl);

                // Return success response with sync results.
                $response = new JSONResponse(
                    data: [
                        'message' => 'Directory synchronized successfully',
                        'data'    => $data,
                    ]
                );
            } catch (\InvalidArgumentException $e) {
                // Handle validation errors (invalid URL, etc.).
                $response = new JSONResponse(
                    data: [
                        'message' => 'Invalid directory URL',
                        'error'   => $e->getMessage(),
                    ],
                    statusCode: 400
                );
            } catch (GuzzleException $e) {
                // Handle HTTP/network errors.
                $response = new JSONResponse(
                    data: [
                        'message' => 'Failed to fetch directory data',
                        'error'   => $e->getMessage(),
                    ],
                    statusCode: 502
                );
            } catch (\Exception $e) {
                // Handle other unexpected errors.
                $response = new JSONResponse(
                    data: [
                        'message' => 'Directory synchronization failed',
                        'error'   => $e->getMessage(),
                    ],
                    statusCode: 500
                );
            }//end try
        }//end if

        // Add CORS headers for public API access.
        if (isset($this->request->server['HTTP_ORIGIN']) === true) {
            $origin = $this->request->server['HTTP_ORIGIN'];
        } else {
            $origin = '*';
        }

        $response->addHeader('Access-Control-Allow-Origin', $origin);
        $response->addHeader('Access-Control-Allow-Methods', $this->corsMethods);
        $response->addHeader('Access-Control-Allow-Headers', $this->corsAllowedHeaders);

        return $response;

    }//end update()


}//end class
