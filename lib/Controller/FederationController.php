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

use OCA\OpenCatalogi\Contract\AppIdentity;
use OCA\OpenCatalogi\Contract\PublicationQueryServiceInterface;
use OCA\OpenCatalogi\Contract\LocalPublicationStateInterface;
use OCA\OpenCatalogi\Contract\PublicationServiceInterface;
use OCA\OpenCatalogi\Contract\PublicationUsage;
use OCA\OpenCatalogi\Contract\UsageCounterServiceInterface;
use OCA\OpenCatalogi\Service\AppliedFilters;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\AnonRateLimit;
use OCP\AppFramework\Http\DataDownloadResponse;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\Http\Response;
use OCP\IAppConfig;
use OCP\IL10N;
use OCP\IRequest;
use Psr\Container\ContainerExceptionInterface;
use Psr\Container\ContainerInterface;
use Psr\Container\NotFoundExceptionInterface;
use Psr\Log\LoggerInterface;

/**
 * Controller for handling federation endpoints.
 *
 * @spec openspec/specs/federation/spec.md
 */
class FederationController extends Controller {
	use AnswersInTheVisitorsLanguage;
	use AnswersCrossOriginSearchRequests;

	/**
	 * App config holding the CORS allowlist; null allows every origin.
	 *
	 * @var IAppConfig|null
	 */
	private readonly ?IAppConfig $appConfig;

	/**
	 * The request's collector of applied filters (REQ-ASF-001).
	 *
	 * @var AppliedFilters|null
	 */
	private readonly ?AppliedFilters $appliedFilters;

	/**
	 * Counts a served local publication and download (ANA-001); null counts nothing.
	 *
	 * @var UsageCounterServiceInterface|null
	 */
	private readonly ?UsageCounterServiceInterface $usageCounter;

	/**
	 * Negotiates the visitor's language (REQ-PTR-002); null leaves the response untranslated.
	 *
	 * @var PublicationQueryServiceInterface|null
	 */
	private readonly ?PublicationQueryServiceInterface $queryService;

	/**
	 * FederationController constructor.
	 *
	 * @param IRequest $request The request object.
	 * @param PublicationServiceInterface $publicationService The publication service.
	 * @param IL10N $l10n The localization service.
	 * @param LoggerInterface $logger PSR-3 logger.
	 * @param LocalPublicationStateInterface|null $localState Adds the withheld list (REQ-WDW-003) and the comment period state (REQ-PDP-003).
	 * @param ContainerInterface|null $container DI container for the optional collaborators: the app config
	 *                                           holding the CORS allowlist (without it every origin is allowed),
	 *                                           the request's collector of applied filters (REQ-ASF-001),
	 *                                           the usage counter of served local publications (ANA-001)
	 *                                           and the query service negotiating the visitor's language (REQ-PTR-002).
	 */
	public function __construct(
		IRequest $request,
		private readonly PublicationServiceInterface $publicationService,
		private readonly IL10N $l10n,
		private readonly ?LoggerInterface $logger = null,
		private readonly ?LocalPublicationStateInterface $localState = null,
		?ContainerInterface $container = null,
	) {
		parent::__construct(appName: AppIdentity::APP_ID, request: $request);
		$appConfig = self::optional(container: $container, class: IAppConfig::class);
		$appliedFilters = self::optional(container: $container, class: AppliedFilters::class);
		if ($appConfig instanceof IAppConfig === false) {
			$appConfig = null;
		}

		if ($appliedFilters instanceof AppliedFilters === false) {
			$appliedFilters = null;
		}

		$this->appConfig = $appConfig;
		$this->appliedFilters = $appliedFilters;
		$usageCounter = self::optional(container: $container, class: UsageCounterServiceInterface::class);
		if ($usageCounter instanceof UsageCounterServiceInterface === false) {
			$usageCounter = null;
		}

		$this->usageCounter = $usageCounter;

		$queryService = self::optional(container: $container, class: PublicationQueryServiceInterface::class);
		$this->queryService = $queryService instanceof PublicationQueryServiceInterface ? $queryService : null;

	}//end __construct()

	/**
	 * One optional collaborator from the container, or null when there is none.
	 *
	 * @param ContainerInterface|null $container The DI container.
	 * @param string                  $class     The service to resolve.
	 *
	 * @return mixed The service, or null.
	 *
	 * @spec openspec/changes/search-cors-on-every-public-endpoint/specs/cross-origin-api-access/spec.md#requirement-the-search-and-federation-endpoints-answer-cross-origin-calls-req-sco-001
	 */
	private static function optional(?ContainerInterface $container, string $class): mixed {
		if ($container === null) {
			return null;
		}

		try {
			return $container->get($class);
		} catch (ContainerExceptionInterface | NotFoundExceptionInterface $e) {
			return null;
		}

	}//end optional()

	/**
	 * Retrieve all publications from local and federated sources.
	 *
	 * @return JSONResponse JSON response containing publications.
	 *
	 * @NoCSRFRequired
	 * @PublicPage
	 *
	 * @SuppressWarnings(PHPMD.StaticAccess) AppliedFilters::forResponse() is a pure fail-closed reader.
	 *
	 * @spec openspec/specs/federation/spec.md
	 * @spec openspec/changes/api-says-what-it-filtered/specs/search/spec.md
	 * @spec openspec/changes/publication-translations/specs/publications/spec.md#requirement-the-public-api-answers-in-the-visitor-s-language-req-ptr-002
	 */
	#[AnonRateLimit(limit: 120, period: 60)]
	public function publications(): JSONResponse {
		$this->negotiateVisitorLanguage(queryService: $this->queryService);

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
			$responseData['appliedFilters'] = AppliedFilters::forResponse(appliedFilters: $this->appliedFilters);

			return $this->withVisitorLanguageHeaders(
				response: $this->withSearchCorsHeaders(response: new JSONResponse($responseData)),
				queryService: $this->queryService
			);
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
	 * @spec openspec/changes/publication-translations/specs/publications/spec.md#requirement-the-public-api-answers-in-the-visitor-s-language-req-ptr-002
	 */
	#[AnonRateLimit(limit: 120, period: 60)]
	public function publication(string $id): JSONResponse {
		$this->negotiateVisitorLanguage(queryService: $this->queryService);

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
			if ($this->localState !== null && $result['status'] === 200 && $isLocal === true) {
				$result['data'] = $this->localState->addTo(publication: $result['data']);
			}

			if ($result['status'] === 200 && $isLocal === true) {
				$this->countReach(publicationId: $id, kind: UsageCounterServiceInterface::KIND_VIEW);
			}

			return $this->withVisitorLanguageHeaders(
				response: $this->withSearchCorsHeaders(response: new JSONResponse($result['data'], $result['status'])),
				queryService: $this->queryService
			);
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
		$response = $this->withSearchCorsHeaders(response: $this->publicationService->download(id: $id));
		if ($response instanceof DataDownloadResponse) {
			$this->countReach(publicationId: $id, kind: UsageCounterServiceInterface::KIND_DOWNLOAD);
		}

		return $response;
	}//end publicationDownload()

	/**
	 * Count one public read of a publication of this instance, never failing the read.
	 *
	 * The federation endpoint is a public read path like publications#show, so a
	 * successful detail answer is a view and a served file a download (ANA-001).
	 * HEAD requests are not counted, and a counter failure is logged and swallowed.
	 *
	 * @param string $publicationId The publication id the caller asked for.
	 * @param string $kind          UsageCounterServiceInterface::KIND_VIEW or KIND_DOWNLOAD.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/capability-rows-close-gaps/tasks.md#task-2.1
	 */
	private function countReach(string $publicationId, string $kind): void {
		if ($this->usageCounter === null || strtoupper((string)$this->request->getMethod()) === 'HEAD') {
			return;
		}

		try {
			$this->usageCounter->increment(
				usage: new PublicationUsage(publicationId: $publicationId, kind: $kind),
				userAgent: $this->request->getHeader('User-Agent')
			);
		} catch (\Throwable $e) {
			$this->logger?->warning('[FederationController] usage count failed (swallowed)', ['error' => $e->getMessage()]);
		}
	}//end countReach()

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
