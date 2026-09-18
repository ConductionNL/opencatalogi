<?php

/**
 * OpenCatalogi Service Catalogue Controller.
 *
 * The public request catalogue, the published case type definitions and their
 * import from an external catalogue, and the knowledge articles beside them.
 *
 * Two failures are kept apart everywhere in here. Publishing something that
 * should not be public is one: a draft article is never served to an anonymous
 * reader, and the check sits beside the read rather than in the surface.
 * Reporting something as published when it is not is the other: a catalogue
 * this app cannot read answers 503, and an external catalogue it could not ask
 * answers 502 with "unreachable", never an empty list.
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
 * @spec openspec/changes/published-service-and-case-type-catalogue/specs/published-service-and-case-type-catalogue/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenCatalogi\Controller;

use OCA\OpenCatalogi\Service\CaseTypeCatalogueService;
use OCA\OpenCatalogi\Service\Catalogue\CatalogueUnreadableException;
use OCA\OpenCatalogi\Service\Catalogue\ExternalCatalogueUnreachableException;
use OCA\OpenCatalogi\Service\KnowledgeArticleService;
use OCA\OpenCatalogi\Service\ServiceCatalogueService;
use OCA\OpenCatalogi\Settings\OpenCatalogiAdmin;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\AnonRateLimit;
use OCP\AppFramework\Http\Attribute\AuthorizedAdminSetting;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\Http\Response;
use OCP\IAppConfig;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IUserSession;
use Psr\Container\ContainerInterface;

/**
 * Serves the published service catalogue and the case type catalogue.
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 *
 * @spec openspec/changes/published-service-and-case-type-catalogue/specs/published-service-and-case-type-catalogue/spec.md
 */
class ServiceCatalogueController extends Controller {
	use ResolvesRegisterConfiguration;

	/**
	 * Constructor.
	 *
	 * @param string $appName The app name.
	 * @param IRequest $request The request.
	 * @param IAppConfig $config App configuration.
	 * @param ContainerInterface $container Server container.
	 * @param IL10N $l10n Localisation.
	 * @param IUserSession $userSession The current session.
	 * @param ServiceCatalogueService $catalogueService The catalogue reader.
	 * @param CaseTypeCatalogueService $caseTypeService The case type importer.
	 * @param KnowledgeArticleService $articleService The knowledge articles.
	 * @param string $corsMethods Allowed CORS methods.
	 * @param string $corsAllowedHeaders Allowed CORS headers.
	 * @param integer $corsMaxAge CORS max age.
	 *
	 * @SuppressWarnings(PHPMD.ExcessiveParameterList)
	 */
	public function __construct(
		$appName,
		IRequest $request,
		private readonly IAppConfig $config,
		private readonly ContainerInterface $container,
		private readonly IL10N $l10n,
		private readonly IUserSession $userSession,
		private readonly ServiceCatalogueService $catalogueService,
		private readonly CaseTypeCatalogueService $caseTypeService,
		private readonly KnowledgeArticleService $articleService,
		private readonly string $corsMethods = 'PUT, POST, GET, DELETE, PATCH',
		private readonly string $corsAllowedHeaders = 'Authorization, Content-Type, Accept',
		private readonly int $corsMaxAge = 1728000,
	) {
		parent::__construct(appName: $appName, request: $request);

	}//end __construct()

	/**
	 * Resolve the Access-Control-Allow-Origin header value for the current request.
	 *
	 * The caller's Origin is never echoed back unless it is on the allowlist.
	 *
	 * @return string The header value.
	 */
	private function resolveAllowedOrigin(): string {
		$configured = trim($this->config->getValueString($this->appName, 'cors_allowed_origins', '*'));
		if ($configured === '' || $configured === '*') {
			return '*';
		}

		$allowlist = array_values(
			array_filter(
				array_map('trim', explode(',', $configured)),
				static fn (string $entry): bool => $entry !== ''
			)
		);

		$callerOrigin = $this->request->getHeader('Origin');
		if ($callerOrigin !== '' && in_array($callerOrigin, $allowlist, true) === true) {
			return $callerOrigin;
		}

		return ($allowlist[0] ?? '*');

	}//end resolveAllowedOrigin()

	/**
	 * Add the CORS headers a public endpoint answers with.
	 *
	 * @param JSONResponse $response The response.
	 *
	 * @return JSONResponse The response, with its headers.
	 */
	private function withCors(JSONResponse $response): JSONResponse {
		$response->addHeader('Access-Control-Allow-Origin', $this->resolveAllowedOrigin());
		$response->addHeader('Access-Control-Allow-Methods', $this->corsMethods);
		$response->addHeader('Access-Control-Max-Age', (string)$this->corsMaxAge);
		$response->addHeader('Access-Control-Allow-Headers', $this->corsAllowedHeaders);
		$response->addHeader('Access-Control-Allow-Credentials', 'false');

		return $response;

	}//end withCors()

	/**
	 * Answer a CORS preflight.
	 *
	 * @return Response The preflight response.
	 *
	 * @NoCSRFRequired
	 * @PublicPage
	 *
	 * @spec openspec/specs/cross-origin-api-access/spec.md#requirement-answer-cors-preflight-requests-on-public-api-controllers-cor-001
	 */
	#[AnonRateLimit(limit: 240, period: 60)]
	public function preflightedCors(): Response {
		$response = new Response();
		$response->addHeader('Access-Control-Allow-Origin', $this->resolveAllowedOrigin());
		$response->addHeader('Access-Control-Allow-Methods', $this->corsMethods);
		$response->addHeader('Access-Control-Max-Age', (string)$this->corsMaxAge);
		$response->addHeader('Access-Control-Allow-Headers', $this->corsAllowedHeaders);
		$response->addHeader('Access-Control-Allow-Credentials', 'false');

		return $response;

	}//end preflightedCors()

	/**
	 * The register and schema of the catalogue entries.
	 *
	 * @return array<string, string> The register and schema identifiers.
	 */
	private function entryConfiguration(): array {
		return $this->resolveRegisterConfiguration(
			registerKey: 'service_catalogue_register',
			schemaKey: 'service_catalogue_entry_schema'
		);

	}//end entryConfiguration()

	/**
	 * The register and schema of the case type definitions.
	 *
	 * @return array<string, string> The register and schema identifiers.
	 */
	private function caseTypeConfiguration(): array {
		return $this->resolveRegisterConfiguration(
			registerKey: 'service_catalogue_register',
			schemaKey: 'case_type_definition_schema'
		);

	}//end caseTypeConfiguration()

	/**
	 * The register and schema of the knowledge articles.
	 *
	 * @return array<string, string> The register and schema identifiers.
	 */
	private function articleConfiguration(): array {
		return $this->resolveRegisterConfiguration(
			registerKey: 'service_catalogue_register',
			schemaKey: 'knowledge_article_schema'
		);

	}//end articleConfiguration()

	/**
	 * The register and schema of the article verdicts.
	 *
	 * @return array<string, string> The register and schema identifiers.
	 */
	private function verdictConfiguration(): array {
		return $this->resolveRegisterConfiguration(
			registerKey: 'service_catalogue_register',
			schemaKey: 'article_verdict_schema'
		);

	}//end verdictConfiguration()

	/**
	 * The public request catalogue.
	 *
	 * Readable without an account. Every entry is listed, including one whose
	 * form binding does not resolve: that entry carries `available: false` and
	 * a reason, because an obligation that is hidden reads as an obligation
	 * that does not exist.
	 *
	 * @return JSONResponse The catalogue, grouped.
	 *
	 * @NoCSRFRequired
	 * @PublicPage
	 *
	 * @spec openspec/changes/published-service-and-case-type-catalogue/specs/published-service-and-case-type-catalogue/spec.md#requirement-a-public-catalogue-lists-everything-that-can-be-requested-req-psc-101
	 */
	#[AnonRateLimit(limit: 120, period: 60)]
	public function index(): JSONResponse {
		try {
			$entryConfig = $this->entryConfiguration();
			$caseTypeConfig = $this->caseTypeConfiguration();
		} catch (\Throwable $e) {
			return $this->registerConfigErrorResponse(e: $e);
		}

		$filters = $this->request->getParams();
		unset($filters['_route'], $filters['id']);

		try {
			$catalogue = $this->catalogueService->readCatalogue(
				entryConfig: $entryConfig,
				caseTypeConfig: $caseTypeConfig,
				filters: $filters
			);
		} catch (CatalogueUnreadableException $e) {
			return $this->withCors(
				new JSONResponse(
					data: [
						'error' => 'catalogue-unreadable',
						'message' => $this->l10n->t('The catalogue could not be read, so this is not a list of nothing. Try again later.'),
					],
					statusCode: Http::STATUS_SERVICE_UNAVAILABLE
				)
			);
		}

		return $this->withCors(new JSONResponse($catalogue));

	}//end index()

	/**
	 * The entries an administrator has to repair.
	 *
	 * @return JSONResponse The unavailable entries with their reasons.
	 *
	 * @spec openspec/changes/published-service-and-case-type-catalogue/specs/published-service-and-case-type-catalogue/spec.md#requirement-a-public-catalogue-lists-everything-that-can-be-requested-req-psc-101
	 */
	#[AuthorizedAdminSetting(settings: OpenCatalogiAdmin::class)]
	public function unavailableEntries(): JSONResponse {
		try {
			$entryConfig = $this->entryConfiguration();
			$caseTypeConfig = $this->caseTypeConfiguration();
		} catch (\Throwable $e) {
			return $this->registerConfigErrorResponse(e: $e);
		}

		try {
			$catalogue = $this->catalogueService->readCatalogue(
				entryConfig: $entryConfig,
				caseTypeConfig: $caseTypeConfig,
				filters: ['_limit' => 1000]
			);
		} catch (CatalogueUnreadableException $e) {
			return new JSONResponse(
				data: [
					'error' => 'catalogue-unreadable',
					'message' => $this->l10n->t('The catalogue could not be read, so this is not a list of nothing.'),
				],
				statusCode: Http::STATUS_SERVICE_UNAVAILABLE
			);
		}

		$unavailable = array_values(
			array_filter(
				$catalogue['entries'],
				static fn (array $entry): bool => $entry['available'] === false
			)
		);

		return new JSONResponse(
			[
				'results' => $unavailable,
				'total' => count($unavailable),
			]
		);

	}//end unavailableEntries()

	/**
	 * The links a published case type carries, and when it was last synchronised.
	 *
	 * @param string $id The case type definition id.
	 *
	 * @return JSONResponse The definition with its links and its sync date.
	 *
	 * @NoCSRFRequired
	 * @PublicPage
	 *
	 * @spec openspec/changes/published-service-and-case-type-catalogue/specs/published-service-and-case-type-catalogue/spec.md#requirement-a-published-case-type-links-to-its-form-and-its-api-description-req-psc-102
	 */
	#[AnonRateLimit(limit: 120, period: 60)]
	public function caseType(string $id): JSONResponse {
		try {
			$caseTypeConfig = $this->caseTypeConfiguration();
		} catch (\Throwable $e) {
			return $this->registerConfigErrorResponse(e: $e);
		}

		try {
			$objectService = $this->catalogueService->getObjectService();
			$found = $objectService->find(
				id: $id,
				register: $caseTypeConfig['register'],
				schema: $caseTypeConfig['schema']
			);
		} catch (CatalogueUnreadableException $e) {
			return $this->withCors(
				new JSONResponse(
					data: ['error' => 'catalogue-unreadable', 'message' => $this->l10n->t('The catalogue could not be read.')],
					statusCode: Http::STATUS_SERVICE_UNAVAILABLE
				)
			);
		} catch (\Throwable $e) {
			return $this->withCors(
				new JSONResponse(data: ['error' => 'not-found'], statusCode: Http::STATUS_NOT_FOUND)
			);
		}

		$definition = $this->asArray(object: $found);

		return $this->withCors(
			new JSONResponse(
				array_merge(
					$definition,
					[
						'links' => $this->caseTypeService->publishedLinks(definition: $definition),
						'syncedAt' => $this->caseTypeService->syncedAt(definition: $definition),
					]
				)
			)
		);

	}//end caseType()

	/**
	 * Import a case type definition from a registered external catalogue.
	 *
	 * @return JSONResponse The imported definition, or why the source could not be asked.
	 *
	 * @spec openspec/changes/published-service-and-case-type-catalogue/specs/published-service-and-case-type-catalogue/spec.md#requirement-case-types-are-imported-from-a-published-external-catalogue-and-resynchronised-req-psc-103
	 */
	#[AuthorizedAdminSetting(settings: OpenCatalogiAdmin::class)]
	public function importCaseType(): JSONResponse {
		$sourceId = trim((string)$this->request->getParam('sourceId', ''));
		$externalId = trim((string)$this->request->getParam('externalId', ''));

		if ($sourceId === '' || $externalId === '') {
			return new JSONResponse(
				data: ['error' => 'missing-parameters', 'message' => $this->l10n->t('Name the external catalogue and the definition to import.')],
				statusCode: Http::STATUS_BAD_REQUEST
			);
		}

		try {
			$caseTypeConfig = $this->caseTypeConfiguration();
		} catch (\Throwable $e) {
			return $this->registerConfigErrorResponse(e: $e);
		}

		try {
			$definition = $this->caseTypeService->importDefinition(sourceId: $sourceId, externalId: $externalId);
		} catch (ExternalCatalogueUnreachableException $e) {
			return $this->unreachableResponse(e: $e);
		}

		try {
			$objectService = $this->catalogueService->getObjectService();
			$saved = $objectService->saveObject(
				object: $definition,
				extend: [],
				register: $caseTypeConfig['register'],
				schema: $caseTypeConfig['schema']
			);
		} catch (CatalogueUnreadableException $e) {
			return new JSONResponse(
				data: ['error' => 'catalogue-unreadable', 'message' => $this->l10n->t('The definition was read but could not be stored.')],
				statusCode: Http::STATUS_SERVICE_UNAVAILABLE
			);
		}

		return new JSONResponse($this->asArray(object: $saved), Http::STATUS_CREATED);

	}//end importCaseType()

	/**
	 * Show what a resynchronisation would change, before anything applies.
	 *
	 * @param string $id The local case type definition id.
	 *
	 * @return JSONResponse The difference against the source.
	 *
	 * @spec openspec/changes/published-service-and-case-type-catalogue/specs/published-service-and-case-type-catalogue/spec.md#requirement-case-types-are-imported-from-a-published-external-catalogue-and-resynchronised-req-psc-103
	 */
	#[AuthorizedAdminSetting(settings: OpenCatalogiAdmin::class)]
	public function previewResync(string $id): JSONResponse {
		try {
			$caseTypeConfig = $this->caseTypeConfiguration();
			$objectService = $this->catalogueService->getObjectService();
			$local = $this->asArray(
				object: $objectService->find(
					id: $id,
					register: $caseTypeConfig['register'],
					schema: $caseTypeConfig['schema']
				)
			);
		} catch (CatalogueUnreadableException $e) {
			return new JSONResponse(
				data: ['error' => 'catalogue-unreadable'],
				statusCode: Http::STATUS_SERVICE_UNAVAILABLE
			);
		} catch (\Throwable $e) {
			return $this->registerConfigErrorResponse(e: $e);
		}

		$source = ($local['source'] ?? []);
		if (is_array($source) === false || ($source['sourceId'] ?? '') === '') {
			return new JSONResponse(
				data: ['error' => 'not-imported', 'message' => $this->l10n->t('This definition was not imported, so there is no source to compare it with.')],
				statusCode: Http::STATUS_BAD_REQUEST
			);
		}

		try {
			$diff = $this->caseTypeService->diffAgainstSource(
				local: $local,
				sourceId: (string)$source['sourceId'],
				externalId: (string)($source['externalId'] ?? '')
			);
		} catch (ExternalCatalogueUnreachableException $e) {
			return $this->unreachableResponse(e: $e);
		}

		return new JSONResponse($diff);

	}//end previewResync()

	/**
	 * Apply the properties an administrator accepted from a resynchronisation.
	 *
	 * @param string $id The local case type definition id.
	 *
	 * @return JSONResponse The saved definition.
	 *
	 * @spec openspec/changes/published-service-and-case-type-catalogue/specs/published-service-and-case-type-catalogue/spec.md#requirement-case-types-are-imported-from-a-published-external-catalogue-and-resynchronised-req-psc-103
	 */
	#[AuthorizedAdminSetting(settings: OpenCatalogiAdmin::class)]
	public function applyResync(string $id): JSONResponse {
		$accepted = $this->request->getParam('accepted', []);
		if (is_array($accepted) === false) {
			$accepted = [];
		}

		try {
			$caseTypeConfig = $this->caseTypeConfiguration();
			$objectService = $this->catalogueService->getObjectService();
			$local = $this->asArray(
				object: $objectService->find(
					id: $id,
					register: $caseTypeConfig['register'],
					schema: $caseTypeConfig['schema']
				)
			);
		} catch (CatalogueUnreadableException $e) {
			return new JSONResponse(data: ['error' => 'catalogue-unreadable'], statusCode: Http::STATUS_SERVICE_UNAVAILABLE);
		} catch (\Throwable $e) {
			return $this->registerConfigErrorResponse(e: $e);
		}

		$source = ($local['source'] ?? []);
		if (is_array($source) === false || ($source['sourceId'] ?? '') === '') {
			return new JSONResponse(
				data: ['error' => 'not-imported'],
				statusCode: Http::STATUS_BAD_REQUEST
			);
		}

		try {
			$diff = $this->caseTypeService->diffAgainstSource(
				local: $local,
				sourceId: (string)$source['sourceId'],
				externalId: (string)($source['externalId'] ?? '')
			);
		} catch (ExternalCatalogueUnreachableException $e) {
			return $this->unreachableResponse(e: $e);
		}

		$updated = $this->caseTypeService->applyResync(
			local: $local,
			diff: $diff,
			accepted: array_values(array_map('strval', $accepted))
		);

		$saved = $objectService->saveObject(
			object: $updated,
			extend: [],
			register: $caseTypeConfig['register'],
			schema: $caseTypeConfig['schema'],
			uuid: $id
		);

		return new JSONResponse($this->asArray(object: $saved));

	}//end applyResync()

	/**
	 * Record a reader's verdict on a knowledge article.
	 *
	 * @param string $id The article id.
	 *
	 * @return JSONResponse The counts after the verdict.
	 *
	 * @NoCSRFRequired
	 * @PublicPage
	 *
	 * @spec openspec/changes/published-service-and-case-type-catalogue/specs/published-service-and-case-type-catalogue/spec.md#requirement-a-reader-says-whether-an-article-helped-and-the-count-is-visible-req-psc-104
	 */
	#[AnonRateLimit(limit: 20, period: 60)]
	public function recordVerdict(string $id): JSONResponse {
		$helpful = filter_var($this->request->getParam('helpful', null), FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE);
		if ($helpful === null) {
			return $this->withCors(
				new JSONResponse(
					data: ['error' => 'missing-verdict', 'message' => $this->l10n->t('Say whether the article helped.')],
					statusCode: Http::STATUS_BAD_REQUEST
				)
			);
		}

		$readerToken = $this->readerToken();

		try {
			$articleConfig = $this->articleConfiguration();
			$verdictConfig = $this->verdictConfiguration();
			$objectService = $this->catalogueService->getObjectService();
			$article = $this->asArray(
				object: $objectService->find(
					id: $id,
					register: $articleConfig['register'],
					schema: $articleConfig['schema']
				)
			);
		} catch (CatalogueUnreadableException $e) {
			return $this->withCors(
				new JSONResponse(data: ['error' => 'catalogue-unreadable'], statusCode: Http::STATUS_SERVICE_UNAVAILABLE)
			);
		} catch (\Throwable $e) {
			return $this->withCors(new JSONResponse(data: ['error' => 'not-found'], statusCode: Http::STATUS_NOT_FOUND));
		}

		if ($this->articleService->isPublic(article: $article) === false) {
			return $this->withCors(new JSONResponse(data: ['error' => 'not-found'], statusCode: Http::STATUS_NOT_FOUND));
		}

		$existing = $objectService->searchObjects(
			[
				'@self' => ['register' => $verdictConfig['register'], 'schema' => $verdictConfig['schema']],
				'article' => $id,
				'_limit' => 1000,
			],
			_rbac: false
		);

		$outcome = $this->articleService->recordVerdict(
			article: array_merge($article, ['id' => $id]),
			existingVerdicts: array_map([$this, 'asArray'], $existing),
			readerToken: $readerToken,
			helpful: $helpful
		);

		if ($outcome['counted'] === true) {
			$objectService->saveObject(
				object: $outcome['verdict'],
				extend: [],
				register: $verdictConfig['register'],
				schema: $verdictConfig['schema']
			);
			$objectService->saveObject(
				object: $outcome['article'],
				extend: [],
				register: $articleConfig['register'],
				schema: $articleConfig['schema'],
				uuid: $id
			);
		}

		return $this->withCors(
			new JSONResponse(
				array_merge(
					$this->articleService->publicCounts(article: $outcome['article']),
					['counted' => $outcome['counted']]
				)
			)
		);

	}//end recordVerdict()

	/**
	 * Turn an answer on a case into a draft knowledge article.
	 *
	 * Authenticated. The draft is not public and the case is not written to:
	 * the extraction copies, it never moves.
	 *
	 * @return JSONResponse The draft article.
	 *
	 * @NoAdminRequired
	 *
	 * @spec openspec/changes/published-service-and-case-type-catalogue/specs/published-service-and-case-type-catalogue/spec.md#requirement-the-answer-on-a-case-becomes-an-article-in-one-action-req-psc-105
	 */
	public function extractArticle(): JSONResponse {
		if ($this->userSession->getUser() === null) {
			return new JSONResponse(data: ['error' => 'not-logged-in'], statusCode: Http::STATUS_UNAUTHORIZED);
		}

		$case = $this->request->getParam('case', []);
		if (is_array($case) === false || $case === []) {
			return new JSONResponse(
				data: ['error' => 'missing-case', 'message' => $this->l10n->t('Name the case the answer comes from.')],
				statusCode: Http::STATUS_BAD_REQUEST
			);
		}

		$answerProperty = (string)$this->request->getParam('answerProperty', 'answer');

		try {
			$draft = $this->articleService->extractDraft(case: $case, answerProperty: $answerProperty);
		} catch (\InvalidArgumentException $e) {
			return new JSONResponse(
				data: ['error' => 'no-answer', 'message' => $e->getMessage()],
				statusCode: Http::STATUS_BAD_REQUEST
			);
		}

		try {
			$articleConfig = $this->articleConfiguration();
			$objectService = $this->catalogueService->getObjectService();
		} catch (CatalogueUnreadableException $e) {
			return new JSONResponse(data: ['error' => 'catalogue-unreadable'], statusCode: Http::STATUS_SERVICE_UNAVAILABLE);
		} catch (\Throwable $e) {
			return $this->registerConfigErrorResponse(e: $e);
		}

		$saved = $objectService->saveObject(
			object: $draft,
			extend: [],
			register: $articleConfig['register'],
			schema: $articleConfig['schema']
		);

		return new JSONResponse($this->asArray(object: $saved), Http::STATUS_CREATED);

	}//end extractArticle()

	/**
	 * The unreachable answer for an external catalogue we could not ask.
	 *
	 * 502, not 200 with an empty list: an administrator told "no definitions"
	 * looks at the national catalogue, and an administrator told "unreachable"
	 * looks at the gateway.
	 *
	 * @param ExternalCatalogueUnreachableException $e The failure.
	 *
	 * @return JSONResponse The refusal.
	 */
	private function unreachableResponse(ExternalCatalogueUnreachableException $e): JSONResponse {
		return new JSONResponse(
			data: [
				'error' => 'external-catalogue-unreachable',
				'message' => $this->l10n->t('The external catalogue could not be reached, so nothing is known about it right now.'),
				'detail' => $e->getMessage(),
			],
			statusCode: Http::STATUS_BAD_GATEWAY
		);

	}//end unreachableResponse()

	/**
	 * The token that identifies a reader for the length of one verdict.
	 *
	 * A signed-in reader is their user id; an anonymous one is their session
	 * id. Neither is stored: the service hashes it with a salt.
	 *
	 * @return string The token.
	 */
	private function readerToken(): string {
		$user = $this->userSession->getUser();
		if ($user !== null) {
			return 'user:' . $user->getUID();
		}

		$sessionToken = (string)$this->request->getParam('readerToken', '');
		if ($sessionToken !== '') {
			return 'token:' . $sessionToken;
		}

		return 'address:' . (string)$this->request->getRemoteAddress();

	}//end readerToken()

	/**
	 * Normalise an OpenRegister result to a plain array.
	 *
	 * @param mixed $object The result.
	 *
	 * @return array<string, mixed> The properties.
	 */
	private function asArray(mixed $object): array {
		if (is_object($object) === true && method_exists($object, 'jsonSerialize') === true) {
			$object = $object->jsonSerialize();
		}

		if (is_array($object) === false) {
			return [];
		}

		if (isset($object['object']) === true && is_array($object['object']) === true) {
			$properties = $object['object'];
			$properties['id'] = ($object['id'] ?? ($properties['id'] ?? null));

			return $properties;
		}

		return $object;

	}//end asArray()
}//end class
