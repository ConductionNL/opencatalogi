<?php

/**
 * OpenCatalogi Federation Controller.
 *
 * Controller for handling federation endpoints that provide access to publication data
 * for internal views like SearchIndex without access control restrictions.
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
 * @spec openspec/specs/federation/spec.md
 * @spec openspec/specs/federation/spec.md
 * @spec openspec/specs/federation/spec.md
 * @spec openspec/specs/federation/spec.md
 * @spec openspec/specs/federation/spec.md
 * @spec openspec/specs/federation/spec.md
 */

namespace OCA\OpenCatalogi\Controller;

use OCA\OpenCatalogi\Contract\PublicationServiceInterface;
use OCA\OpenCatalogi\Contract\WithheldFromPublicationInterface;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\AnonRateLimit;
use OCP\AppFramework\Http\DataDownloadResponse;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\Http\Response;
use OCP\IAppConfig;
use OCP\IL10N;
use OCP\IRequest;
use Psr\Container\ContainerExceptionInterface;
use Psr\Container\NotFoundExceptionInterface;
use Psr\Log\LoggerInterface;

/**
 * Controller for handling federation endpoints.
 *
 * @spec openspec/specs/federation/spec.md
 */
class FederationController extends Controller {
	use AnswersCrossOriginSearchRequests;

	/**
	 * FederationController constructor.
	 *
	 * @param string $appName The name of the app.
	 * @param IRequest $request The request object.
	 * @param PublicationServiceInterface $publicationService The publication service.
	 * @param IL10N $l10n The localization service.
	 * @param LoggerInterface $logger PSR-3 logger.
	 * @param WithheldFromPublicationInterface|null $withheld The public withheld list (REQ-WDW-003).
	 * @param IAppConfig|null $appConfig App config holding the CORS allowlist (without it every origin is allowed).
	 */
	public function __construct(
		$appName,
		IRequest $request,
		private readonly PublicationServiceInterface $publicationService,
		private readonly IL10N $l10n,
		private readonly ?LoggerInterface $logger = null,
		private readonly ?WithheldFromPublicationInterface $withheld = null,
		private readonly ?IAppConfig $appConfig = null,
	) {
		parent::__construct(appName: $appName, request: $request);

	}//end __construct()

	/**
	 * Retrieve all publications from local and federated sources.
	 *
	 * @return JSONResponse JSON response containing publications.
	 *
	 * @NoCSRFRequired
	 * @PublicPage
	 *
	 * @spec openspec/specs/federation/spec.md
	 */
	#[AnonRateLimit(limit: 120, period: 60)]
	public function publications(): JSONResponse {
		// Get all current query parameters.
		$queryParams = $this->request->getParams();
		$baseUrl = $this->paginationBaseUrl();

		try {
			// Use the service method to get aggregated publications.
			$responseData = $this->publicationService->getAggregatedPublications(
				queryParams: $queryParams,
				requestParams: $this->request->getParams(),
				baseUrl: $baseUrl
			);

			return $this->withSearchCorsHeaders(response: new JSONResponse($responseData));
		} catch (\Exception $e) {
			return $this->withSearchCorsHeaders(response: $this->publicationsFailed(exception: $e));
		}

	}//end publications()

	/**
	 * The base URL for pagination links: the request's scheme, host and path without its query.
	 *
	 * @return string The base URL.
	 *
	 * @SuppressWarnings(PHPMD.Superglobals)
	 */
	private function paginationBaseUrl(): string {
		$protocol = 'http';
		if (empty($_SERVER['HTTPS']) === false && $_SERVER['HTTPS'] !== 'off') {
			$protocol = 'https';
		}

		$host = ($_SERVER['HTTP_HOST'] ?? 'localhost');
		$uri = ($_SERVER['REQUEST_URI'] ?? '/');

		return $protocol . '://' . $host . strtok($uri, '?');
	}//end paginationBaseUrl()

	/**
	 * Log a failed aggregation and answer 500.
	 *
	 * @param \Exception $exception Why the aggregation failed.
	 *
	 * @return JSONResponse The 500 answer.
	 */
	private function publicationsFailed(\Exception $exception): JSONResponse {
		$this->logger?->error(
			'[FederationController::publications] Failed to retrieve publications',
			[
				'error' => $exception->getMessage(),
				'trace' => $exception->getTraceAsString(),
			]
		);
		return new JSONResponse(
			data: ['error' => $this->l10n->t('Failed to retrieve publications')],
			statusCode: 500
		);
	}//end publicationsFailed()

	/**
	 * Retrieve a specific publication by its ID.
	 *
	 * @param string $id The ID of the publication to retrieve.
	 *
	 * @return JSONResponse JSON response containing the requested publication.
	 *
	 * @throws ContainerExceptionInterface|NotFoundExceptionInterface
	 *
	 * @NoCSRFRequired
	 * @PublicPage
	 *
	 * @spec openspec/specs/federation/spec.md
	 */
	#[AnonRateLimit(limit: 120, period: 60)]
	public function publication(string $id): JSONResponse {
		try {
			// Use the service method to get the publication with federation support.
			$result = $this->publicationService->getFederatedPublication($id, $this->request->getParams());

			// REQ-WDW-003: the same `withheld` list publications#show answers, for a
			// publication of this instance whose catalogue opted in. A publication a peer
			// answered (marked `federated`, carrying `sources`) is never described from this
			// instance's catalogues: its register and schema ids are the peer's.
			$isLocal = (($result['federated'] ?? false) !== true
				&& is_array($result['data']) === true
				&& array_key_exists('sources', $result['data']) === false);
			if ($this->withheld !== null && $result['status'] === 200 && $isLocal === true) {
				$result['data'] = $this->withheld->addForPublication(response: $result['data']);
			}

			return $this->withSearchCorsHeaders(response: new JSONResponse($result['data'], $result['status']));
		} catch (\Exception $e) {
			$this->logger?->error(
				'[FederationController::publication] Failed to retrieve publication',
				[
					'id' => $id,
					'error' => $e->getMessage(),
					'trace' => $e->getTraceAsString(),
				]
			);
			return $this->withSearchCorsHeaders(response: new JSONResponse(
				data: ['error' => $this->l10n->t('Failed to retrieve publication')],
				statusCode: 500
			));
		}

	}//end publication()

	/**
	 * Retrieve objects that this publication references.
	 *
	 * This method returns all objects that this publication uses/references.
	 * When aggregation is enabled, it also searches federated catalogs.
	 *
	 * @param string $id The ID of the publication to retrieve relations for.
	 *
	 * @return JSONResponse A JSON response containing the related objects.
	 *
	 * @throws ContainerExceptionInterface|NotFoundExceptionInterface
	 *
	 * @NoCSRFRequired
	 * @PublicPage
	 *
	 * @spec openspec/specs/federation/spec.md
	 */
	#[AnonRateLimit(limit: 120, period: 60)]
	public function publicationUses(string $id): JSONResponse {
		try {
			// Use the service method to get the publication uses with federation support.
			$result = $this->publicationService->getFederatedUses($id, $this->request->getParams());

			return $this->withSearchCorsHeaders(response: new JSONResponse($result['data'], $result['status']));
		} catch (\Exception $e) {
			$this->logger?->error(
				'[FederationController::publicationUses] Failed to retrieve publication uses',
				[
					'id' => $id,
					'error' => $e->getMessage(),
					'trace' => $e->getTraceAsString(),
				]
			);
			return $this->withSearchCorsHeaders(response: new JSONResponse(
				data: ['error' => $this->l10n->t('Failed to retrieve publication uses')],
				statusCode: 500
			));
		}

	}//end publicationUses()

	/**
	 * Retrieve objects that use this publication.
	 *
	 * This method returns all objects that reference (use) this publication.
	 * When aggregation is enabled, it also searches federated catalogs.
	 *
	 * @param string $id The ID of the publication to retrieve uses for.
	 *
	 * @return JSONResponse A JSON response containing the referenced objects.
	 *
	 * @throws ContainerExceptionInterface|NotFoundExceptionInterface
	 *
	 * @NoCSRFRequired
	 * @PublicPage
	 *
	 * @spec openspec/specs/federation/spec.md
	 */
	#[AnonRateLimit(limit: 120, period: 60)]
	public function publicationUsed(string $id): JSONResponse {
		try {
			// Use the service method to get the publication used with federation support.
			$result = $this->publicationService->getFederatedUsed($id, $this->request->getParams());

			return $this->withSearchCorsHeaders(response: new JSONResponse($result['data'], $result['status']));
		} catch (\Exception $e) {
			$this->logger?->error(
				'[FederationController::publicationUsed] Failed to retrieve publication used',
				[
					'id' => $id,
					'error' => $e->getMessage(),
					'trace' => $e->getTraceAsString(),
				]
			);
			return $this->withSearchCorsHeaders(response: new JSONResponse(
				data: ['error' => $this->l10n->t('Failed to retrieve publication used')],
				statusCode: 500
			));
		}

	}//end publicationUsed()

	/**
	 * Retrieve attachments/files of a publication.
	 *
	 * @param string $id Id of publication.
	 *
	 * @return JSONResponse JSON response containing the requested attachments/files.
	 *
	 * @throws ContainerExceptionInterface|NotFoundExceptionInterface
	 *
	 * @NoCSRFRequired
	 * @PublicPage
	 *
	 * @no-admin-idor-exempt PublicationService::attachments() refuses an id outside the configured
	 *   catalog scope (isObjectInCatalogScope()), reads the object with OpenRegister find() (RBAC on,
	 *   anonymous callers get public-group reads only) and lists shared files only (sharedFilesOnly).
	 *   An id not held here is only answered with what the public federation endpoint of the
	 *   instance that holds it returns (getFederatedAttachments()).
	 *
	 * @spec openspec/specs/federation/spec.md
	 * @spec openspec/changes/federation-open-remote-publication/specs/federation/spec.md#requirement-the-attachments-of-a-remote-publication-are-listed-from-the-source-req-for-002
	 */
	#[AnonRateLimit(limit: 120, period: 60)]
	public function publicationAttachments(string $id): JSONResponse {
		return $this->withSearchCorsHeaders(
			response: $this->publicationService->getFederatedAttachments(id: $id, queryParams: $this->request->getParams())
		);
	}//end publicationAttachments()

	// Lower than the sibling reads: a download moves file bytes, so the cost of
	// one call is materially higher than a metadata lookup.
	/**
	 * Download all files of a publication as ZIP.
	 *
	 * @param string $id Id of publication.
	 *
	 * @return DataDownloadResponse|JSONResponse JSON response for download or error response.
	 *
	 * @throws ContainerExceptionInterface|NotFoundExceptionInterface
	 *
	 * @NoCSRFRequired
	 * @PublicPage
	 *
	 * @no-admin-idor-exempt PublicationService::download() refuses an id outside the configured
	 *   catalog scope (isObjectInCatalogScope()), reads the object with OpenRegister find() (RBAC on,
	 *   anonymous callers get public-group reads only) before any file is zipped, and OpenRegister's
	 *   createObjectFilesZip() drops restricted files (FileRestrictionGuard).
	 *
	 * @spec openspec/specs/federation/spec.md
	 */
	#[AnonRateLimit(limit: 60, period: 60)]
	public function publicationDownload(string $id): DataDownloadResponse|JSONResponse {
		return $this->withSearchCorsHeaders(response: $this->publicationService->download(id: $id));
	}//end publicationDownload()

	/**
	 * Answer a CORS preflight on the federation routes.
	 *
	 * The preflight carries no resource data; the allowed origin comes from the
	 * shared allowlist rule.
	 *
	 * @return Response The preflight answer.
	 *
	 * @NoCSRFRequired
	 * @PublicPage
	 *
	 * @spec openspec/changes/search-cors-on-every-public-endpoint/specs/cross-origin-api-access/spec.md#requirement-the-search-and-federation-endpoints-answer-cross-origin-calls-req-sco-001
	 */
	#[AnonRateLimit(limit: 240, period: 60)]
	public function preflightedCors(): Response {
		return $this->searchPreflight();
	}//end preflightedCors()
}//end class
