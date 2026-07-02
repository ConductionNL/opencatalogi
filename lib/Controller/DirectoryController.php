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
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2024 Conduction B.V. <info@conduction.nl>
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenCatalogi.nl
 *
 * @spec openspec/changes/retrofit-2026-05-25-annotate-opencatalogi/tasks.md#task-5
 * @spec openspec/changes/retrofit-2026-05-25-annotate-opencatalogi/tasks.md#task-6
 */

namespace OCA\OpenCatalogi\Controller;

use GuzzleHttp\Exception\GuzzleException;
use OCA\OpenCatalogi\Service\DirectoryService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\MultipleObjectsReturnedException;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\Http\Response;
use OCP\IAppConfig;
use OCP\IL10N;
use OCP\IRequest;
use Psr\Container\ContainerExceptionInterface;
use Psr\Container\NotFoundExceptionInterface;
use Psr\Log\LoggerInterface;

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
     * @param LoggerInterface  $logger             PSR-3 logger.
     * @param IAppConfig|null  $config             App config for CORS allowlist (optional).
     * @param string           $corsMethods        Allowed CORS methods.
     * @param string           $corsAllowedHeaders Allowed CORS headers.
     * @param integer          $corsMaxAge         CORS max age.
     */
    public function __construct(
        $appName,
        IRequest $request,
        private readonly DirectoryService $directoryService,
        private readonly IL10N $l10n,
        private readonly ?LoggerInterface $logger=null,
        private readonly ?IAppConfig $config=null,
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
     * Resolve the Access-Control-Allow-Origin header value for the current request.
     *
     * Reads the configured allowlist from IAppConfig key 'cors_allowed_origins' (CSV).
     * Special value '*' (the default) means "any origin allowed" and emits a literal '*'
     * — the caller's Origin is NEVER echoed back unless it appears on the allowlist (#735).
     *
     * @return string The header value to use for Access-Control-Allow-Origin.
     *
     * @spec exclude CORS-policy plumbing; reads IAppConfig allowlist, no Origin reflection.
     */
    private function resolveAllowedOrigin(): string
    {
        $configured = '*';
        if ($this->config !== null) {
            $configured = trim($this->config->getValueString($this->appName, 'cors_allowed_origins', '*'));
        }

        if ($configured === '' || $configured === '*') {
            return '*';
        }

        $allowlist = array_filter(
            array_map('trim', explode(',', $configured)),
            static fn(string $entry): bool => $entry !== ''
        );

        $callerOrigin = $this->request->getHeader('Origin');
        if ($callerOrigin === '') {
            $callerOrigin = ($this->request->server['HTTP_ORIGIN'] ?? '');
        }

        if ($callerOrigin !== '' && in_array($callerOrigin, $allowlist, true) === true) {
            return $callerOrigin;
        }

        return ($allowlist[0] ?? '*');

    }//end resolveAllowedOrigin()

    /**
     * Implements a preflighted CORS response for OPTIONS requests.
     *
     * @return Response The CORS response.
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     * @PublicPage
     *
     * @spec openspec/changes/retrofit-2026-05-25-cross-origin-api-access/tasks.md#task-1
     */
    public function preflightedCors(): Response
    {
        $response = new Response();
        $response->addHeader('Access-Control-Allow-Origin', $this->resolveAllowedOrigin());
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
     * @NoCSRFRequired
     * @PublicPage
     *
     * @spec openspec/changes/retrofit-2026-05-25-annotate-opencatalogi/tasks.md#task-5
     */
    public function index(): JSONResponse
    {
        try {
            // Retrieve all request parameters.
            $requestParams = $this->request->getParams();

            // Use the directory service to get combined directory data.
            $data = $this->directoryService->getDirectory($requestParams);

            // Create JSON response with CORS headers (#735 — never reflect Origin).
            $response = new JSONResponse($data);
            $response->addHeader('Access-Control-Allow-Origin', $this->resolveAllowedOrigin());
            $response->addHeader('Access-Control-Allow-Methods', $this->corsMethods);
            $response->addHeader('Access-Control-Allow-Headers', $this->corsAllowedHeaders);

            return $response;
        } catch (\Exception $e) {
            // Public endpoint — log details server-side, return generic body (#735).
            $this->logger?->error(
                '[DirectoryController::index] Failed to retrieve directory data',
                [
                    'error' => $e->getMessage(),
                    'trace' => $e->getTraceAsString(),
                ]
            );
            $response = new JSONResponse(
                data: ['error' => $this->l10n->t('Internal server error')],
                statusCode: 500
            );
            $response->addHeader('Access-Control-Allow-Origin', $this->resolveAllowedOrigin());
            $response->addHeader('Access-Control-Allow-Methods', $this->corsMethods);
            $response->addHeader('Access-Control-Allow-Headers', $this->corsAllowedHeaders);

            return $response;
        }//end try

    }//end index()

    /**
     * Synchronize with an external directory — federation broadcast-receive endpoint.
     *
     * This route is the target for cross-instance federation gossip: peer OpenCatalogi
     * instances POST here (via `BroadcastService`) to notify us of their existence so
     * we can pull their listings. It MUST stay `@PublicPage` + `@NoCSRFRequired` for
     * that gossip to reach unauthenticated. Accepts a 'directory' parameter with the
     * URL to sync from.
     *
     * **Do not call this endpoint from the OC admin UI.** Admin-initiated
     * peer-registration should hit `POST /api/listings/add` instead (see
     * `ListingsController::add()`), which requires an authenticated user and CSRF
     * token — matching the SB1 / WF1 SSRF hardening from wave-12. Both endpoints
     * funnel into `DirectoryService::syncDirectory($url)`, so their behaviour is
     * identical on the wire; the difference is purely in auth posture. WOO-513
     * migrated the two remaining frontend callers (`AddDirectoryModal.vue`,
     * `DirectoryIndex.vue`) off this public route onto the auth-required one.
     *
     * @return JSONResponse The JSON response containing the synchronization result.
     *
     * @throws DoesNotExistException|MultipleObjectsReturnedException|ContainerExceptionInterface|NotFoundExceptionInterface
     * @throws GuzzleException
     *
     * @NoCSRFRequired
     * @PublicPage
     *
     * @spec openspec/changes/retrofit-2026-05-25-annotate-opencatalogi/tasks.md#task-6
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

            // Add CORS headers for public API access (#735 — never reflect Origin).
            $response->addHeader('Access-Control-Allow-Origin', $this->resolveAllowedOrigin());
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
            // Handle validation errors (invalid URL, etc.). The raw message here is
            // safe because it's the caller's own input being echoed back.
            $response = new JSONResponse(
                data: [
                    'message' => $this->l10n->t('Invalid directory URL'),
                    'error'   => $e->getMessage(),
                ],
                statusCode: 400
            );
        } catch (GuzzleException $e) {
            // Handle HTTP/network errors. Do NOT reflect the upstream response body
            // (it may contain internal content from an SSRF-style probe). Log details
            // server-side instead and return a generic message to the caller.
            $this->logger?->warning(
                '[DirectoryController::update] Upstream fetch failed',
                ['error' => $e->getMessage()]
            );
            $response = new JSONResponse(
                data: [
                    'message' => $this->l10n->t('Failed to fetch directory data'),
                    'error'   => $this->l10n->t('Unable to reach the requested directory'),
                ],
                statusCode: 502
            );
        } catch (\Exception $e) {
            // Handle other unexpected errors. Public endpoint — log server-side, return
            // a generic body so internal details (paths, SQL fragments) do not leak (#735).
            $this->logger?->error(
                '[DirectoryController::update] Directory synchronization failed',
                [
                    'error' => $e->getMessage(),
                    'trace' => $e->getTraceAsString(),
                ]
            );
            $response = new JSONResponse(
                data: ['error' => $this->l10n->t('Internal server error')],
                statusCode: 500
            );
        }//end try

        // Add CORS headers for public API access (#735 — never reflect Origin).
        $response->addHeader('Access-Control-Allow-Origin', $this->resolveAllowedOrigin());
        $response->addHeader('Access-Control-Allow-Methods', $this->corsMethods);
        $response->addHeader('Access-Control-Allow-Headers', $this->corsAllowedHeaders);

        return $response;

    }//end update()
}//end class
