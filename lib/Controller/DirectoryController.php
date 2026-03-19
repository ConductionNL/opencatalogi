<?php
/**
 * OpenCatalogi Directory Controller.
 *
 * Controller for handling directory-related operations in the OpenCatalogi app.
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

namespace OCA\OpenCatalogi\Controller;

use GuzzleHttp\Exception\GuzzleException;
use OCA\OpenCatalogi\Service\DirectoryService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\MultipleObjectsReturnedException;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\Http\Response;
use OCP\IL10N;
use OCP\IRequest;
use Psr\Container\ContainerExceptionInterface;
use Psr\Container\NotFoundExceptionInterface;

/**
 * Controller for handling directory-related operations.
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
     * @param IL10N            $l10n               The localization service.
     * @param string           $corsMethods        Allowed CORS methods.
     * @param string           $corsAllowedHeaders Allowed CORS headers.
     * @param integer          $corsMaxAge         CORS max age.
     */
    public function __construct(
        $appName,
        IRequest $request,
        private readonly DirectoryService $directoryService,
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
     * Retrieve all directories.
     *
     * @return JSONResponse The JSON response containing all directories.
     *
     * @throws DoesNotExistException|MultipleObjectsReturnedException|ContainerExceptionInterface|NotFoundExceptionInterface
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
            $response = new JSONResponse($data);
            $origin = $this->request->server['HTTP_ORIGIN'] ?? '*';

            $response->addHeader('Access-Control-Allow-Origin', $origin);
            $response->addHeader('Access-Control-Allow-Methods', $this->corsMethods);
            $response->addHeader('Access-Control-Allow-Headers', $this->corsAllowedHeaders);

            return $response;
        } catch (\Exception $e) {
            // Handle errors gracefully with CORS headers.
            $response = new JSONResponse(
                data: [
                    'message' => $this->l10n->t('Failed to retrieve directory data'),
                    'error'   => $e->getMessage(),
                ],
                statusCode: 500
            );
            $origin = $this->request->server['HTTP_ORIGIN'] ?? '*';

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
     * @throws DoesNotExistException|MultipleObjectsReturnedException|ContainerExceptionInterface|NotFoundExceptionInterface
     * @throws GuzzleException
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
                    'message' => $this->l10n->t('Property "directory" is required'),
                    'error'   => $this->l10n->t('Missing directory URL parameter'),
                ],
                statusCode: 400
            );

            // Add CORS headers for public API access.
            $origin = $this->request->getHeader('Origin');
            if ($origin === '') {
                $origin = '*';
            }

            $response->addHeader('Access-Control-Allow-Origin', $origin);
            $response->addHeader('Access-Control-Allow-Methods', $this->corsMethods);
            $response->addHeader('Access-Control-Allow-Headers', $this->corsAllowedHeaders);

            return $response;
        }//end if

        // Sync the directory with the provided URL.
        try {
            $data = $this->directoryService->syncDirectory($directoryUrl);

            // Return success response with sync results.
            $response = new JSONResponse(
                [
                    'message' => $this->l10n->t('Directory synchronized successfully'),
                    'data'    => $data,
                ]
            );
        } catch (\InvalidArgumentException $e) {
            // Handle validation errors (invalid URL, etc.).
            $response = new JSONResponse(
                data: [
                    'message' => $this->l10n->t('Invalid directory URL'),
                    'error'   => $e->getMessage(),
                ],
                statusCode: 400
            );
        } catch (GuzzleException $e) {
            // Handle HTTP/network errors.
            $response = new JSONResponse(
                data: [
                    'message' => $this->l10n->t('Failed to fetch directory data'),
                    'error'   => $e->getMessage(),
                ],
                statusCode: 502
            );
        } catch (\Exception $e) {
            // Handle other unexpected errors.
            $response = new JSONResponse(
                data: [
                    'message' => $this->l10n->t('Directory synchronization failed'),
                    'error'   => $e->getMessage(),
                ],
                statusCode: 500
            );
        }//end try

        // Add CORS headers for public API access.
        $origin = $this->request->getHeader('Origin');
        if ($origin === '') {
            $origin = '*';
        }

        $response->addHeader('Access-Control-Allow-Origin', $origin);
        $response->addHeader('Access-Control-Allow-Methods', $this->corsMethods);
        $response->addHeader('Access-Control-Allow-Headers', $this->corsAllowedHeaders);

        return $response;

    }//end update()
}//end class
