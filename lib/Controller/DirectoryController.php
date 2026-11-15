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
 * @spec openspec/specs/dashboard/spec.md
 * @spec openspec/specs/dashboard/spec.md
 */

namespace OCA\OpenCatalogi\Controller;

use GuzzleHttp\Exception\GuzzleException;
use OCA\OpenCatalogi\Contract\DirectoryServiceInterface;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\MultipleObjectsReturnedException;
use OCP\AppFramework\Http\Attribute\AnonRateLimit;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\Http\Response;
use OCP\IL10N;
use OCP\IRequest;
use Psr\Container\ContainerExceptionInterface;
use Psr\Container\NotFoundExceptionInterface;
use Psr\Log\LoggerInterface;

/**
 * Controller for handling directory-related operations.
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects) CorsSettings is the thirteenth type, shared with the other list controllers.
 */
class DirectoryController extends Controller {
	use SendsListCorsHeaders;

	/**
	 * DirectoryController constructor.
	 *
	 * @param string $appName The name of the app.
	 * @param IRequest $request The request object.
	 * @param DirectoryServiceInterface $directoryService The directory service.
	 * @param IL10N $l10n The localization service.
	 * @param LoggerInterface $logger PSR-3 logger.
	 * @param ListCorsPolicy $cors Allowed CORS methods, headers and max age, and the configured origin allowlist.
	 */
	public function __construct(
		$appName,
		IRequest $request,
		private readonly DirectoryServiceInterface $directoryService,
		private readonly IL10N $l10n,
		private readonly ?LoggerInterface $logger = null,
		private readonly ListCorsPolicy $cors = new ListCorsPolicy(),
	) {
		parent::__construct(appName: $appName, request: $request);

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
	 * @SuppressWarnings(PHPMD.UnusedPrivateMethod) Called from the SendsListCorsHeaders trait.
	 *
	 * @spec exclude CORS-policy plumbing; reads IAppConfig allowlist, no Origin reflection.
	 */
	private function resolveAllowedOrigin(): string {
		$callerOrigin = $this->request->getHeader('Origin');
		if ($callerOrigin === '') {
			$callerOrigin = ($this->request->server['HTTP_ORIGIN'] ?? '');
		}

		return $this->cors->allowedOrigin(appName: $this->appName, callerOrigin: $callerOrigin);
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
	 * @spec openspec/specs/cross-origin-api-access/spec.md#requirement-answer-cors-preflight-requests-on-public-api-controllers-cor-001
	 */
	#[AnonRateLimit(limit: 240, period: 60)]
	public function preflightedCors(): Response {
		return $this->withListPreflightHeaders(response: new Response());
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
	 * @no-admin-idor-exempt DirectoryService::getDirectory() searches only the configured listing and
	 *       catalog register/schema, with RBAC and multitenancy on; request parameters filter within that scope.
	 *
	 * @spec openspec/specs/dashboard/spec.md
	 */
	#[AnonRateLimit(limit: 120, period: 60)]
	public function index(): JSONResponse {
		try {
			// Retrieve all request parameters.
			$requestParams = $this->request->getParams();

			// Use the directory service to get combined directory data.
			$data = $this->directoryService->getDirectory($requestParams);

			// Create JSON response with CORS headers (#735 — never reflect Origin).
			$response = new JSONResponse($data);
			return $this->withListCorsHeaders(response: $response);
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
			return $this->withListCorsHeaders(response: $response);
		}//end try

	}//end index()

	/**
	 * Sync with an external directory — federation broadcast-receive endpoint.
	 *
	 * Public + no-CSRF because peers POST here (via `BroadcastService`) to notify
	 * us of their existence. **Admin UIs must not call this** — they should hit
	 * `POST /api/listings/add` (admin-only, CSRF-protected — WOO-513 migrated the
	 * two remaining callers). Response wrap here is `{message, data: $result}`
	 * whereas `/api/listings/add` returns `$result` bare; `AddDirectoryModal.vue`
	 * handles both via `response.data.data ?? response.data`.
	 *
	 * @return JSONResponse The JSON response containing the synchronization result.
	 *
	 * @throws DoesNotExistException|MultipleObjectsReturnedException|ContainerExceptionInterface|NotFoundExceptionInterface
	 * @throws GuzzleException
	 *
	 * @NoCSRFRequired
	 * @PublicPage
	 *
	 * @spec openspec/specs/dashboard/spec.md
	 */
	#[AnonRateLimit(limit: 30, period: 60)]
	public function update(): JSONResponse {
		// Get the directory URL from the request parameters.
		$directoryUrl = $this->request->getParam('directory');

		// Add CORS headers for public API access (#735 — never reflect Origin).
		if (empty($directoryUrl) === true) {
			return $this->withListCorsHeaders(response: $this->missingDirectoryAnswer());
		}

		return $this->withListCorsHeaders(response: $this->syncAnswer(directoryUrl: $directoryUrl));
	}//end update()

	/**
	 * The 400 for a sync request without a directory URL.
	 *
	 * @return JSONResponse The answer.
	 */
	private function missingDirectoryAnswer(): JSONResponse {
		return new JSONResponse(
			data: [
				'message' => $this->l10n->t('Property "directory" is required'),
				'error' => $this->l10n->t('Missing directory URL parameter'),
			],
			statusCode: 400
		);
	}//end missingDirectoryAnswer()

	/**
	 * Sync the directory and answer with the result, or with why it failed.
	 *
	 * @param mixed $directoryUrl The directory URL the peer posted.
	 *
	 * @return JSONResponse The answer.
	 */
	private function syncAnswer(mixed $directoryUrl): JSONResponse {
		try {
			$data = $this->directoryService->syncDirectory($directoryUrl);

			return new JSONResponse(
				[
					'message' => $this->l10n->t('Directory synchronized successfully'),
					'data' => $data,
				]
			);
		} catch (\InvalidArgumentException $e) {
			// Handle validation errors (invalid URL, etc.). The raw message here is
			// safe because it's the caller's own input being echoed back.
			return new JSONResponse(
				data: [
					'message' => $this->l10n->t('Invalid directory URL'),
					'error' => $e->getMessage(),
				],
				statusCode: 400
			);
		} catch (GuzzleException $e) {
			return $this->upstreamFailedAnswer(exception: $e);
		} catch (\Exception $e) {
			return $this->syncFailedAnswer(exception: $e);
		}//end try
	}//end syncAnswer()

	/**
	 * The 502 when the directory cannot be fetched. Do NOT reflect the upstream
	 * response body (it may contain internal content from an SSRF-style probe):
	 * log the details server-side and give the caller a generic message.
	 *
	 * @param GuzzleException $exception The fetch failure.
	 *
	 * @return JSONResponse The answer.
	 */
	private function upstreamFailedAnswer(GuzzleException $exception): JSONResponse {
		$this->logger?->warning(
			'[DirectoryController::update] Upstream fetch failed',
			['error' => $exception->getMessage()]
		);

		return new JSONResponse(
			data: [
				'message' => $this->l10n->t('Failed to fetch directory data'),
				'error' => $this->l10n->t('Unable to reach the requested directory'),
			],
			statusCode: 502
		);
	}//end upstreamFailedAnswer()

	/**
	 * The 500 for any other failure. Public endpoint — log server-side, return
	 * a generic body so internal details (paths, SQL fragments) do not leak (#735).
	 *
	 * @param \Exception $exception The failure.
	 *
	 * @return JSONResponse The answer.
	 */
	private function syncFailedAnswer(\Exception $exception): JSONResponse {
		$this->logger?->error(
			'[DirectoryController::update] Directory synchronization failed',
			[
				'error' => $exception->getMessage(),
				'trace' => $exception->getTraceAsString(),
			]
		);

		return new JSONResponse(
			data: ['error' => $this->l10n->t('Internal server error')],
			statusCode: 500
		);
	}//end syncFailedAnswer()
}//end class
