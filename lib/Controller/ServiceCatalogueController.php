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
use Psr\Container\ContainerInterface;

/**
 * Serves the published service catalogue and the case type catalogue.
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 *
 * @spec openspec/changes/published-service-and-case-type-catalogue/specs/published-service-and-case-type-catalogue/spec.md
 */
class ServiceCatalogueController extends Controller {
	use AnswersCrossOriginRequests;
	use ReadsOpenRegisterResults;
	use ResolvesRegisterConfiguration;

	/**
	 * Constructor.
	 *
	 * @param string $appName The app name.
	 * @param IRequest $request The request.
	 * @param IAppConfig $config App configuration.
	 * @param ContainerInterface $container Server container.
	 * @param IL10N $l10n Localisation.
	 * @param ServiceCatalogueService $catalogueService The catalogue reader.
	 * @param CaseTypeCatalogueService $caseTypeService The case type importer.
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
		private readonly ServiceCatalogueService $catalogueService,
		private readonly CaseTypeCatalogueService $caseTypeService,
		private readonly string $corsMethods = 'PUT, POST, GET, DELETE, PATCH',
		private readonly string $corsAllowedHeaders = 'Authorization, Content-Type, Accept',
		private readonly int $corsMaxAge = 1728000,
	) {
		parent::__construct(appName: $appName, request: $request);

	}//end __construct()

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
	 * The register and schema of one of this controller's schemas.
	 *
	 * Everything this controller reads lives in the service catalogue
	 * register, so only the schema key varies.
	 *
	 * @param string $schemaKey The `<thing>_schema` config key.
	 *
	 * @return array<string, string> The register and schema identifiers.
	 */
	private function configurationFor(string $schemaKey): array {
		return $this->resolveRegisterConfiguration(
			registerKey: 'service_catalogue_register',
			schemaKey: $schemaKey
		);

	}//end configurationFor()

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
			$entryConfig = $this->configurationFor(schemaKey: 'service_catalogue_entry_schema');
			$caseTypeConfig = $this->configurationFor(schemaKey: 'case_type_definition_schema');
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
				response: new JSONResponse(
					data: [
						'error' => 'catalogue-unreadable',
						'message' => $this->l10n->t('The catalogue could not be read, so this is not a list of nothing. Try again later.'),
					],
					statusCode: Http::STATUS_SERVICE_UNAVAILABLE
				)
			);
		}

		return $this->withCors(response: new JSONResponse($catalogue));

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
			$entryConfig = $this->configurationFor(schemaKey: 'service_catalogue_entry_schema');
			$caseTypeConfig = $this->configurationFor(schemaKey: 'case_type_definition_schema');
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
			$caseTypeConfig = $this->configurationFor(schemaKey: 'case_type_definition_schema');
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
				response: new JSONResponse(
					data: ['error' => 'catalogue-unreadable', 'message' => $this->l10n->t('The catalogue could not be read.')],
					statusCode: Http::STATUS_SERVICE_UNAVAILABLE
				)
			);
		} catch (\Throwable $e) {
			return $this->withCors(
				response: new JSONResponse(data: ['error' => 'not-found'], statusCode: Http::STATUS_NOT_FOUND)
			);
		}

		$definition = $this->asArray(object: $found);

		return $this->withCors(
			response: new JSONResponse(
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
			$caseTypeConfig = $this->configurationFor(schemaKey: 'case_type_definition_schema');
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
			$caseTypeConfig = $this->configurationFor(schemaKey: 'case_type_definition_schema');
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
			$caseTypeConfig = $this->configurationFor(schemaKey: 'case_type_definition_schema');
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
}//end class
