<?php

/**
 * OpenCatalogi DCAT-AP-NL harvest service.
 *
 * Read-only rendering layer (hydra ADR-022): no new storage, no new visibility
 * rules, no new query layer. It selects publicly visible publications via the
 * same OpenRegister object-search path the public publications API uses
 * (RBAC-governed `publicationDate <= now` visibility), maps them with
 * {@see DcatMappingService}, and serializes the resulting graph with
 * {@see DcatSerializer}. Documents are derived per request — nothing is
 * persisted. The architectural twin of SitemapService.
 *
 * @category Service
 * @package  OCA\OpenCatalogi\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2025 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2025 Conduction B.V. <info@conduction.nl>
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenCatalogi.nl
 */

namespace OCA\OpenCatalogi\Service;

use OCA\OpenCatalogi\Contract\DcatServiceInterface;
use OCP\App\IAppManager;
use OCP\IAppConfig;
use OCP\IURLGenerator;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Builds DCAT-AP-NL documents from publicly visible OpenCatalogi publications.
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 * @SuppressWarnings(PHPMD.ExcessiveClassComplexity)
 *
 * @spec openspec/changes/dcat-ap-harvest/tasks.md#task-3-serializer-public-endpoints
 */
class DcatService implements DcatServiceInterface {

	/**
	 * Page-size ceiling — identical to the sitemap ceiling (WOO-005).
	 *
	 * @var integer
	 */
	public const MAX_PER_PAGE = 1000;

	/**
	 * DcatService constructor.
	 *
	 * @param ContainerInterface $container Server container for OR service resolution.
	 * @param IAppManager $appManager App manager for OpenRegister availability checks.
	 * @param DcatMappingService $mappingService Pure publication → DCAT
	 *                                           mapping.
	 * @param DcatSerializer $serializer Pure graph → JSON-LD/Turtle/RDF-XML
	 *                                   serializer.
	 * @param IURLGenerator $urlGenerator Nextcloud URL generator (absolute IRIs).
	 * @param IAppConfig $appConfig App config (publisher defaults).
	 * @param LoggerInterface $logger PSR-3 logger.
	 * @param StandardsVersionService $standardsVersions Declared DCAT-AP-NL profile version, and the published one.
	 * @param QualityService|null $qualityService Optional MQA/FAIR scorer for DQV exposure (PQM-002).
	 *
	 * @SuppressWarnings(PHPMD.ExcessiveParameterList)
	 */
	public function __construct(
		private readonly ContainerInterface $container,
		private readonly IAppManager $appManager,
		private readonly DcatMappingService $mappingService,
		private readonly DcatSerializer $serializer,
		private readonly IURLGenerator $urlGenerator,
		private readonly IAppConfig $appConfig,
		private readonly LoggerInterface $logger,
		private readonly StandardsVersionService $standardsVersions,
		private readonly ?QualityService $qualityService = null,
	) {

	}//end __construct()

	/**
	 * Resolve the OpenRegister ObjectService from the container.
	 *
	 * @return \OCA\OpenRegister\Service\ObjectService The OpenRegister ObjectService.
	 *
	 * @throws RuntimeException When OpenRegister is not installed.
	 */
	private function getObjectService(): \OCA\OpenRegister\Service\ObjectService {
		if (in_array(needle: 'openregister', haystack: $this->appManager->getInstalledApps()) === true) {
			return $this->container->get('OCA\OpenRegister\Service\ObjectService');
		}

		throw new RuntimeException('OpenRegister service is not available.');
	}//end getObjectService()

	/**
	 * Resolve the OpenRegister FileService from the container.
	 *
	 * @return \OCA\OpenRegister\Service\FileService The OpenRegister FileService.
	 *
	 * @throws RuntimeException When OpenRegister is not installed.
	 */
	private function getFileService(): \OCA\OpenRegister\Service\FileService {
		if (in_array(needle: 'openregister', haystack: $this->appManager->getInstalledApps()) === true) {
			return $this->container->get('OCA\OpenRegister\Service\FileService');
		}

		throw new RuntimeException('OpenRegister FileService is not available.');
	}//end getFileService()

	/**
	 * Resolve the OpenRegister SchemaMapper from the container.
	 *
	 * @return \OCA\OpenRegister\Db\SchemaMapper The OpenRegister SchemaMapper.
	 *
	 * @throws RuntimeException When OpenRegister is not installed.
	 */
	private function getSchemaMapper(): \OCA\OpenRegister\Db\SchemaMapper {
		if (in_array(needle: 'openregister', haystack: $this->appManager->getInstalledApps()) === true) {
			return $this->container->get('OCA\OpenRegister\Db\SchemaMapper');
		}

		throw new RuntimeException('OpenRegister SchemaMapper is not available.');
	}//end getSchemaMapper()

	/**
	 * Determine whether DCAT is enabled for a catalog.
	 *
	 * @param array<string, mixed> $catalog The catalog object (jsonSerialize shape).
	 *
	 * @return boolean True when the catalog's `hasDcat` flag is set.
	 *
	 * @spec openspec/specs/dcat-ap-harvest/spec.md#requirement-admin-configuration-and-feed-validation-dcat-010
	 */
	public function isDcatEnabled(array $catalog): bool {
		return filter_var(($catalog['hasDcat'] ?? false), FILTER_VALIDATE_BOOLEAN);
	}//end isDcatEnabled()

	/**
	 * Resolve the catalog-level DCAT defaults (publisher/license/contactPoint).
	 *
	 * Reads per-catalog overrides from the catalog object, falling back to the
	 * instance-level admin-settings defaults stored in app config.
	 *
	 * @param array<string, mixed> $catalog The catalog object.
	 *
	 * @return array<string, mixed> The resolved defaults (publisherName/publisherUri/
	 *                              license/contactPoint/organisation).
	 *
	 * @spec openspec/specs/dcat-ap-harvest/spec.md#requirement-dcat-ap-nl-mandatory-property-completion-dcat-005
	 */
	public function resolveDefaults(array $catalog): array {
		$organisation = ($catalog['organisation'] ?? $catalog['organization'] ?? null);
		if (is_array($organisation) === false) {
			$organisation = null;
		}

		$defaultLicense = 'http://creativecommons.org/publicdomain/zero/1.0/';

		return [
			'publisherName' => ($catalog['dcatPublisherName'] ?? $this->appConfig->getValueString('opencatalogi', 'dcat_publisher_name', '')),
			'publisherUri' => ($catalog['dcatPublisherUri'] ?? $this->appConfig->getValueString('opencatalogi', 'dcat_publisher_uri', '')),
			'license' => ($catalog['dcatLicense'] ?? $this->appConfig->getValueString('opencatalogi', 'dcat_default_license', $defaultLicense)),
			'contactPoint' => ($catalog['dcatContactPoint'] ?? $this->appConfig->getValueString('opencatalogi', 'dcat_contact_point', '')),
			'organisation' => $organisation,
		];

	}//end resolveDefaults()

	/**
	 * Build the absolute per-catalog DCAT endpoint URL.
	 *
	 * @param string $catalogSlug The catalog slug.
	 *
	 * @return string The absolute DCAT endpoint URL.
	 *
	 * @spec openspec/specs/dcat-ap-harvest/spec.md#requirement-federation-directory-advertises-the-dcat-endpoint-dcat-009
	 */
	public function catalogEndpointUrl(string $catalogSlug): string {
		$base = rtrim($this->urlGenerator->getBaseUrl(), '/');
		return "$base/apps/opencatalogi/api/catalogs/$catalogSlug/dcat";
	}//end catalogEndpointUrl()

	/**
	 * Build the canonical public dataset IRI for a publication (PUB-002 URL).
	 *
	 * @param string $catalogSlug The catalog slug.
	 * @param string $uuid The publication UUID.
	 *
	 * @return string The stable dataset IRI.
	 *
	 * @spec openspec/specs/dcat-ap-harvest/spec.md#requirement-attachments-rendered-as-distributions-with-stable-iris-dcat-006
	 */
	public function datasetIri(string $catalogSlug, string $uuid): string {
		$base = rtrim($this->urlGenerator->getBaseUrl(), '/');
		return "$base/apps/opencatalogi/api/$catalogSlug/$uuid";
	}//end datasetIri()

	/**
	 * Build the per-catalog DCAT-AP-NL document for one page of datasets.
	 *
	 * Datasets are selected via OR object search scoped to the catalog's
	 * registers/schemas with `_rbac: true` — the PUB-001/WOO-001 visibility rule
	 * (only publicly visible objects appear). Opted-out schemas
	 * (`"x-dcat": false`) are skipped.
	 *
	 * The CALLER guards the catalog first. For the public feed that is
	 * {@see \OCA\OpenCatalogi\Controller\DcatController::catalog()}, which runs it
	 * through
	 * {@see \OCA\OpenCatalogi\Service\PublicationQueryService::applyCatalogReadRuleGuard()}
	 * — the SCH-PFTS-CAT-002 guard `/api/{catalogSlug}` has applied since WOO-580
	 * — so an anonymous harvester never reaches a schema without
	 * `authorization.read` rules (WOO-581). This method does NOT guard on its own:
	 * the admin-only validate/donlReport routes and StatsController::quality call
	 * it too and keep session RBAC. A new public caller must guard the catalog
	 * before handing it in.
	 *
	 * An empty schema list — nothing configured, or everything dropped by that
	 * guard — yields a valid document with zero datasets, never a register-wide
	 * search.
	 *
	 * @param array<string, mixed> $catalog The catalog object.
	 * @param string $catalogSlug The catalog slug.
	 * @param integer $page The 1-based page number.
	 * @param array<int, array<string, mixed>> $violations Controlled-vocabulary violations collected here (by reference).
	 *
	 * @return array<string, mixed> The JSON-LD document plus `_meta` (lastModified, etag, count).
	 *
	 * @spec openspec/specs/dcat-ap-harvest/spec.md#requirement-per-catalog-dcat-ap-nl-document-endpoint-dcat-001
	 * @spec openspec/specs/dcat-ap-harvest/spec.md#requirement-only-publicly-visible-objects-appear-in-the-feed-dcat-003
	 * @spec openspec/specs/dcat-ap-harvest/spec.md#requirement-harvester-grade-pagination-and-caching-dcat-008
	 */
	public function buildCatalogDocument(array $catalog, string $catalogSlug, int $page = 1, array &$violations = []): array {
		$page = max(1, $page);
		$scope = $this->resolveCatalogScope(catalog: $catalog, catalogSlug: $catalogSlug);
		$result = $this->searchCatalogPage(scope: $scope, page: $page);
		$hasNext = (($result['next'] ?? null) !== null);

		$collected = $this->mapCatalogDatasets(publications: ($result['results'] ?? []), scope: $scope);
		array_push($violations, ...$collected['violations']);

		$graph = array_merge([$this->buildCatalogNode(catalog: $catalog, scope: $scope, datasetRefs: $collected['refs'])], $collected['datasets']);
		$paged = $this->buildHydraPage(catalogIri: $scope['catalogIri'], page: $page, hasNext: $hasNext);
		if ($paged !== null) {
			$graph[] = $paged;
		}

		$document = [
			'@context' => $this->mappingService->context(),
			'@graph' => $graph,
		];
		$document['_meta'] = $this->buildCatalogMeta(
			etagKey: ($catalog['id'] ?? $catalogSlug),
			count: count($collected['datasets']),
			maxModified: $collected['maxModified'],
			paging: ['page' => $page, 'hasNext' => $hasNext]
		);

		return $document;
	}//end buildCatalogDocument()

	/**
	 * Resolve what a catalog document is built from: scope, defaults, schema mappings and options.
	 *
	 * @param array<string, mixed> $catalog     The catalog object.
	 * @param string               $catalogSlug The catalog slug.
	 *
	 * @return array<string, mixed>
	 *
	 * @spec openspec/specs/dcat-ap-harvest/spec.md#requirement-per-catalog-dcat-ap-nl-document-endpoint-dcat-001
	 */
	private function resolveCatalogScope(array $catalog, string $catalogSlug): array {
		$schemas = $this->idList(raw: ($catalog['schemas'] ?? []));
		$catalogHvdDefault = ($catalog['dcatHvdCategory'] ?? null);
		if (is_string($catalogHvdDefault) === false) {
			$catalogHvdDefault = null;
		}

		return [
			'catalogSlug' => $catalogSlug,
			'catalogIri' => $this->catalogEndpointUrl(catalogSlug: $catalogSlug),
			'registers' => $this->idList(raw: ($catalog['registers'] ?? [])),
			'schemas' => $schemas,
			'defaults' => $this->resolveDefaults(catalog: $catalog),
			'schemaMappings' => $this->resolveSchemaMappings(schemaIds: $schemas),
			'schemaHvds' => $this->resolveSchemaHvds(schemaIds: $schemas),
			'catalogHvdDefault' => $catalogHvdDefault,
			// PQM-002: attach W3C DQV quality measurements only when the catalog opts in.
			'dqvExposure' => filter_var(($catalog['dqvExposure'] ?? false), FILTER_VALIDATE_BOOLEAN),
		];
	}//end resolveCatalogScope()

	/**
	 * Search one page of the catalog's publications, newest first.
	 *
	 * FAILS CLOSED on an empty scope: no schema or no register yields zero datasets,
	 * never a register-wide search (WOO-581). RBAC governs visibility (PUB-001 /
	 * WOO-001): anonymous callers receive only publicly visible objects.
	 *
	 * @param array $scope The resolved catalog scope.
	 * @param int   $page  The 1-based page number.
	 *
	 * @return array The paginated search result.
	 *
	 * @SuppressWarnings(PHPMD.StaticAccess) CallerScope::writeScope() is a pure static function.
	 *
	 * @spec openspec/specs/dcat-ap-harvest/spec.md#requirement-only-publicly-visible-objects-appear-in-the-feed-dcat-003
	 */
	private function searchCatalogPage(array $scope, int $page): array {
		if ($scope['schemas'] === [] || $scope['registers'] === []) {
			return ['results' => [], 'next' => null];
		}

		// One schema in one register is the scalar fast path; anything else keeps the
		// lists OR's routers read (CallerScope::writeScope()).
		$searchQuery = ['_limit' => self::MAX_PER_PAGE, '_page' => $page];
		$searchQuery = CallerScope::writeScope(query: $searchQuery, registers: $scope['registers'], schemas: $scope['schemas']);
		$searchQuery['_order']['updated'] = 'desc';

		return $this->getObjectService()->searchObjectsPaginated(
			query: $searchQuery,
			_rbac: true,
			_multitenancy: false,
			deleted: false
		);
	}//end searchCatalogPage()

	/**
	 * Map a page of publications to DCAT datasets, skipping schemas that opted out.
	 *
	 * @param array $publications The publications of this page.
	 * @param array $scope        The resolved catalog scope.
	 *
	 * @return array{datasets: array, refs: array, maxModified: int, violations: array<int, array<string, mixed>>}
	 *
	 * @spec openspec/specs/dcat-ap-harvest/spec.md#requirement-per-catalog-dcat-ap-nl-document-endpoint-dcat-001
	 */
	private function mapCatalogDatasets(array $publications, array $scope): array {
		$scope['fileService'] = $this->getFileService();
		$collected = ['datasets' => [], 'refs' => [], 'maxModified' => 0, 'violations' => []];
		foreach ($publications as $publication) {
			$publication = $this->toArray(item: $publication);
			$schemaId = (int)($publication['@self']['schema'] ?? 0);
			if (array_key_exists($schemaId, $scope['schemaMappings']) === true && $scope['schemaMappings'][$schemaId] === null) {
				continue;
			}

			$source = $this->datasetSource(publication: $publication, scope: $scope);
			$collected['datasets'][] = $this->mapCatalogDataset(source: $source, scope: $scope);
			$collected['refs'][] = ['@id' => $source->datasetIri];
			array_push($collected['violations'], ...$source->violations);

			$modified = strtotime((string)($publication['@self']['updated'] ?? $publication['publicationDate'] ?? ''));
			if ($modified !== false && $modified > $collected['maxModified']) {
				$collected['maxModified'] = $modified;
			}
		}

		return $collected;
	}//end mapCatalogDatasets()

	/**
	 * One publication with its dataset IRI and its published attachments.
	 *
	 * The paginated search omits files, so they are fetched here, as SitemapService
	 * does; a failing lookup yields a dataset without distributions.
	 *
	 * @param array $publication The publication.
	 * @param array $scope       The resolved catalog scope, with the file service.
	 *
	 * @return DcatDatasetSource
	 *
	 * @spec openspec/specs/dcat-ap-harvest/spec.md#requirement-per-catalog-dcat-ap-nl-document-endpoint-dcat-001
	 */
	private function datasetSource(array $publication, array $scope): DcatDatasetSource {
		$uuid = (string)($publication['@self']['uuid'] ?? $publication['id'] ?? '');

		$files = [];
		try {
			$fileService = $scope['fileService'];
			$files = ($fileService->formatFiles($fileService->getFiles(object: ($publication['id'] ?? $uuid)))['results'] ?? []);
		} catch (\Throwable $e) {
			$this->logger->debug('[DcatService] Could not load files for publication', ['uuid' => $uuid, 'error' => $e->getMessage()]);
		}

		return new DcatDatasetSource(
			publication: $publication,
			datasetIri: $this->datasetIri(catalogSlug: $scope['catalogSlug'], uuid: $uuid),
			files: $files
		);
	}//end datasetSource()

	/**
	 * Map one publication to a DCAT dataset; its violations are left on the source.
	 *
	 * @param DcatDatasetSource $source The publication, its IRI and its attachments.
	 * @param array             $scope  The resolved catalog scope.
	 *
	 * @return array The dataset node.
	 *
	 * @spec openspec/specs/dcat-ap-harvest/spec.md#requirement-per-catalog-dcat-ap-nl-document-endpoint-dcat-001
	 */
	private function mapCatalogDataset(DcatDatasetSource $source, array $scope): array {
		$schemaId = (int)($source->publication['@self']['schema'] ?? 0);
		$profile = new DcatMappingProfile(
			mapping: ($scope['schemaMappings'][$schemaId] ?? DcatMappingService::DEFAULT_MAPPING),
			defaults: $scope['defaults'],
			hvd: ($scope['schemaHvds'][$schemaId] ?? null),
			catalogHvdDefault: $scope['catalogHvdDefault']
		);
		$dataset = $this->mappingService->mapDataset(source: $source, profile: $profile);

		// PQM-002: additive DQV measurements, only when the catalog opts in.
		if ($scope['dqvExposure'] === true && $this->qualityService !== null) {
			$dataset['dqv:hasQualityMeasurement'] = $this->qualityService->dqvMeasurements($this->qualityService->scoreDataset($dataset));
		}

		return $dataset;
	}//end mapCatalogDataset()

	/**
	 * The dcat:Catalog node, declaring the DCAT-AP-NL profile it targets.
	 *
	 * The declared profile lets a harvester and our own validator read the same version.
	 *
	 * @param array $catalog     The catalog object.
	 * @param array $scope       The resolved catalog scope.
	 * @param array $datasetRefs The `@id` references of the datasets on this page.
	 *
	 * @return array
	 *
	 * @spec openspec/specs/dcat-ap-harvest/spec.md#requirement-per-catalog-dcat-ap-nl-document-endpoint-dcat-001
	 */
	private function buildCatalogNode(array $catalog, array $scope, array $datasetRefs): array {
		$catalogNode = [
			'@id' => $scope['catalogIri'],
			'@type' => 'dcat:Catalog',
			'dct:title' => ($catalog['title'] ?? $scope['catalogSlug']),
			'dct:conformsTo' => ['@id' => StandardsVersionService::DCAT_AP_NL_PROFILE],
			'dcat:dataset' => $datasetRefs,
		];

		if (($catalog['description'] ?? '') !== '') {
			$catalogNode['dct:description'] = $catalog['description'];
		}

		$defaults = $scope['defaults'];
		$publisher = $this->mappingService->buildPublisher(organisation: ($defaults['organisation'] ?? null), defaults: $defaults);
		if ($publisher !== null) {
			$catalogNode['dct:publisher'] = $publisher;
		}

		return $catalogNode;
	}//end buildCatalogNode()

	/**
	 * The Hydra paging node, or null for a single page.
	 *
	 * @param string $catalogIri The catalog endpoint URL.
	 * @param int    $page       The 1-based page number.
	 * @param bool   $hasNext    Whether a next page exists.
	 *
	 * @return array|null
	 *
	 * @spec openspec/specs/dcat-ap-harvest/spec.md#requirement-harvester-grade-pagination-and-caching-dcat-008
	 */
	private function buildHydraPage(string $catalogIri, int $page, bool $hasNext): ?array {
		if ($page <= 1 && $hasNext === false) {
			return null;
		}

		$paged = [
			'@id' => "$catalogIri?page=$page",
			'@type' => 'hydra:PagedCollection',
		];
		if ($hasNext === true) {
			$paged['hydra:next'] = ['@id' => "$catalogIri?page=" . ($page + 1)];
		}

		if ($page > 1) {
			$paged['hydra:previous'] = ['@id' => "$catalogIri?page=" . ($page - 1)];
		}

		return $paged;
	}//end buildHydraPage()

	/**
	 * The `_meta` block: Last-Modified from the newest dataset (or now), ETag, count and paging.
	 *
	 * @param mixed $etagKey     The catalog id, or its slug.
	 * @param int   $count       The number of datasets on this page.
	 * @param int   $maxModified The newest dataset timestamp, 0 when none is known.
	 * @param array $paging      The page number and whether a next page exists.
	 *
	 * @return array
	 *
	 * @spec openspec/specs/dcat-ap-harvest/spec.md#requirement-harvester-grade-pagination-and-caching-dcat-008
	 */
	private function buildCatalogMeta($etagKey, int $count, int $maxModified, array $paging): array {
		$lastModified = gmdate('D, d M Y H:i:s') . ' GMT';
		if ($maxModified > 0) {
			$lastModified = gmdate('D, d M Y H:i:s', $maxModified) . ' GMT';
		}

		return [
			'lastModified' => $lastModified,
			'etag' => '"' . md5($etagKey . ':' . $count . ':' . $maxModified . ':' . $paging['page']) . '"',
			'count' => $count,
			'hasNext' => $paging['hasNext'],
		];
	}//end buildCatalogMeta()

	/**
	 * Build the instance-level DCAT document listing every DCAT-enabled catalog.
	 *
	 * @return array<string, mixed> The JSON-LD document plus `_meta`.
	 *
	 * @spec openspec/specs/dcat-ap-harvest/spec.md#requirement-instance-level-dcat-catalog-document-dcat-002
	 */
	public function buildInstanceDocument(): array {
		$catalogs = $this->getDcatEnabledCatalogs();

		$base = rtrim($this->urlGenerator->getBaseUrl(), '/');
		$instanceIri = "$base/apps/opencatalogi/api/dcat";

		$graph = [];
		foreach ($catalogs as $catalog) {
			$slug = (string)($catalog['slug'] ?? '');
			if ($slug === '') {
				continue;
			}

			$graph[] = [
				'@id' => $this->catalogEndpointUrl(catalogSlug: $slug),
				'@type' => 'dcat:Catalog',
				'dct:title' => ($catalog['title'] ?? $slug),
				'foaf:homepage' => ['@id' => $this->catalogEndpointUrl(catalogSlug: $slug)],
			];
		}

		// Instance-level publisher, completed from the configured owning Organisation
		// via the DCAT-005 fallback chain. When no instance-level publisher is
		// configured, fall back to the first DCAT-enabled catalog's owning Organisation.
		$organisation = null;
		if ($catalogs !== []) {
			$catDefaults = $this->resolveDefaults(catalog: $catalogs[0]);
			$organisation = ($catDefaults['organisation'] ?? null);
		}

		$defaults = [
			'publisherName' => $this->appConfig->getValueString('opencatalogi', 'dcat_publisher_name', ''),
			'publisherUri' => $this->appConfig->getValueString('opencatalogi', 'dcat_publisher_uri', ''),
		];
		$publisher = $this->mappingService->buildPublisher($organisation, $defaults);

		// DONL harvest-source metadata (DCAT-NPF-001): the source catalog node MUST
		// carry publisher, license, contactPoint, modified and homepage.
		$rootNode = [
			'@id' => $instanceIri,
			'@type' => 'dcat:Catalog',
			'dct:title' => $this->appConfig->getValueString('opencatalogi', 'dcat_instance_title', 'OpenCatalogi'),
			'dct:modified' => gmdate('Y-m-d\TH:i:sP'),
			'foaf:homepage' => ['@id' => $this->appConfig->getValueString('opencatalogi', 'dcat_instance_homepage', $base)],
			'dcat:catalog' => array_map(static fn ($node) => ['@id' => $node['@id']], $graph),
		];
		if ($publisher !== null) {
			$rootNode['dct:publisher'] = $publisher;
		}

		$license = $this->appConfig->getValueString('opencatalogi', 'dcat_default_license', 'http://creativecommons.org/publicdomain/zero/1.0/');
		if ($license !== '') {
			$rootNode['dct:license'] = ['@id' => $license];
		}

		$contactPoint = $this->appConfig->getValueString('opencatalogi', 'dcat_contact_point', '');
		if ($contactPoint !== '') {
			$rootNode['dcat:contactPoint'] = ['@type' => 'vcard:Organization', 'vcard:fn' => $contactPoint];
		}

		$document = [
			'@context' => $this->mappingService->context(),
			'@graph' => array_merge([$rootNode], $graph),
		];

		$etag = '"' . md5('instance:' . count($graph)) . '"';
		$document['_meta'] = [
			'lastModified' => gmdate('D, d M Y H:i:s') . ' GMT',
			'etag' => $etag,
			'count' => count($graph),
			'hasNext' => false,
		];

		return $document;
	}//end buildInstanceDocument()

	/**
	 * Validate a catalog's feed against the DCAT-AP-NL mandatory-property checklist.
	 *
	 * Advisory only — never gates serving. Reports the dataset IRIs that lack any
	 * resolvable mandatory property (title, publisher).
	 *
	 * @param array<string, mixed> $catalog The catalog object.
	 * @param string $catalogSlug The catalog slug.
	 *
	 * @return array<int, array<string, mixed>> One entry per violating dataset.
	 *
	 * @spec openspec/specs/dcat-ap-harvest/spec.md#requirement-admin-configuration-and-feed-validation-dcat-010
	 */
	public function validateCatalog(array $catalog, string $catalogSlug): array {
		$document = $this->buildCatalogDocument(catalog: $catalog, catalogSlug: $catalogSlug, page: 1);
		$violations = $this->profileVersionViolations();
		foreach ($this->serializer->graphNodes($document) as $node) {
			if (($node['@type'] ?? '') !== 'dcat:Dataset') {
				continue;
			}

			$missing = $this->mandatoryViolations(node: $node);
			if (empty($missing) === false) {
				$violations[] = [
					'iri' => ($node['@id'] ?? ''),
					'missing' => $missing,
				];
			}
		}

		return $violations;
	}//end validateCatalog()

	/**
	 * The profile-version violation, when the publisher has moved past the version we target.
	 *
	 * A feed that declares a superseded profile is a feed whose mandatory-property
	 * checklist is the wrong checklist, so it is reported as a violation rather than
	 * left to a reader to notice. An unreadable release index yields no violation but
	 * is still reported by the controller as `unknown`, never as current.
	 *
	 * @return array<int, array<string, mixed>> One entry when behind, empty otherwise.
	 *
	 * @spec openspec/specs/dcat-ap-harvest/spec.md#requirement-admin-configuration-and-feed-validation-dcat-010
	 */
	private function profileVersionViolations(): array {
		$profile = $this->standardsVersions->dcatApNlVersion();
		if ($profile['status'] !== StandardsVersionService::STATUS_BEHIND) {
			return [];
		}

		return [
			[
				'axis' => 'profile-version',
				'declared' => $profile['declared'],
				'published' => $profile['published'],
			],
		];

	}//end profileVersionViolations()

	/**
	 * The canonical instance-level harvest-source URL for data.overheid.nl (DONL).
	 *
	 * @return string The `…/api/dcat` harvest-source URL.
	 *
	 * @spec openspec/specs/dcat-ap-harvest/spec.md
	 */
	public function harvestSourceUrl(): string {
		$base = rtrim($this->urlGenerator->getBaseUrl(), '/');
		return "$base/apps/opencatalogi/api/dcat";
	}//end harvestSourceUrl()

	/**
	 * Validate a catalog's feed against the data.overheid.nl (DONL) rule-set.
	 *
	 * Advisory only — never gates serving (DCAT-010 / DCAT-NPF-001). Reports the
	 * canonical harvest-source URL, per-dataset mandatory-property gaps, and the
	 * per-dataset controlled-theme violations collected during rendering (unresolved
	 * `dcat:theme` values that were omitted rather than leaked, DCAT-NPF-003).
	 *
	 * @param array<string, mixed> $catalog The catalog object.
	 * @param string $catalogSlug The catalog slug.
	 *
	 * @return array{sourceUrl: string, valid: bool, violations: array<int, array<string, mixed>>}
	 *
	 * @spec openspec/specs/dcat-ap-harvest/spec.md
	 */
	public function validateForDonl(array $catalog, string $catalogSlug): array {
		$themeViolations = [];
		$document = $this->buildCatalogDocument(
			catalog: $catalog,
			catalogSlug: $catalogSlug,
			page: 1,
			violations: $themeViolations
		);

		$violations = array_merge($this->profileVersionViolations(), $themeViolations);
		foreach ($this->serializer->graphNodes($document) as $node) {
			if (($node['@type'] ?? '') !== 'dcat:Dataset') {
				continue;
			}

			$missing = $this->mandatoryViolations(node: $node);
			if (empty($missing) === false) {
				$violations[] = [
					'iri' => ($node['@id'] ?? ''),
					'axis' => 'mandatory',
					'missing' => $missing,
				];
			}
		}

		return [
			'sourceUrl' => $this->harvestSourceUrl(),
			'valid' => ($violations === []),
			'violations' => $violations,
		];

	}//end validateForDonl()

	/**
	 * Determine the DCAT-AP-NL mandatory properties missing from a dataset node.
	 *
	 * @param array<string, mixed> $node The dataset node.
	 *
	 * @return array<int, string> The missing mandatory property CURIEs.
	 *
	 * @spec openspec/specs/dcat-ap-harvest/spec.md#requirement-admin-configuration-and-feed-validation-dcat-010
	 */
	public function mandatoryViolations(array $node): array {
		$required = ['dct:title', 'dct:publisher', 'dcat:landingPage'];
		$missing = [];
		foreach ($required as $property) {
			if (isset($node[$property]) === false || $node[$property] === '' || $node[$property] === []) {
				$missing[] = $property;
			}
		}

		return $missing;
	}//end mandatoryViolations()

	/**
	 * Fetch all DCAT-enabled catalogs on the instance.
	 *
	 * @return array<int, array<string, mixed>> The DCAT-enabled catalog objects.
	 *
	 * @spec openspec/specs/dcat-ap-harvest/spec.md#requirement-instance-level-dcat-catalog-document-dcat-002
	 */
	public function getDcatEnabledCatalogs(): array {
		$register = $this->appConfig->getValueString('opencatalogi', 'catalog_register', '');
		$schema = $this->appConfig->getValueString('opencatalogi', 'catalog_schema', '');
		if ($register === '' || $schema === '') {
			return [];
		}

		$query = [
			'@self' => ['register' => $register, 'schema' => $schema],
			'hasDcat' => true,
			'_limit' => self::MAX_PER_PAGE,
		];

		$result = $this->getObjectService()->searchObjectsPaginated(
			query: $query,
			_rbac: true,
			_multitenancy: false,
			deleted: false
		);

		$catalogs = [];
		foreach (($result['results'] ?? []) as $catalog) {
			$catalogs[] = $this->toArray(item: $catalog);
		}

		return $catalogs;
	}//end getDcatEnabledCatalogs()

	/**
	 * Resolve per-schema DCAT mappings (or null for opted-out schemas).
	 *
	 * @param array<int, int> $schemaIds The catalog's configured schema IDs.
	 *
	 * @return array<int, array<string, string>|null> Map schemaId => mapping|null.
	 *
	 * @spec openspec/specs/dcat-ap-harvest/spec.md#requirement-schema-driven-dcat-mapping-via-x-dcat-annotation-dcat-004
	 */
	private function resolveSchemaMappings(array $schemaIds): array {
		$mappings = [];
		$schemaMapper = $this->getSchemaMapper();
		foreach ($schemaIds as $schemaId) {
			try {
				$schema = $schemaMapper->find((int)$schemaId);
				$schemaData = $schema->jsonSerialize();
				$mappings[(int)$schemaId] = $this->mappingService->resolveMapping($schemaData);
			} catch (\Throwable $e) {
				$this->logger->debug('[DcatService] Could not resolve schema mapping', ['schema' => $schemaId, 'error' => $e->getMessage()]);
				$mappings[(int)$schemaId] = DcatMappingService::DEFAULT_MAPPING;
			}
		}

		return $mappings;
	}//end resolveSchemaMappings()

	/**
	 * Resolve the optional `x-dcat.hvd` block for each schema.
	 *
	 * @param array<int, int> $schemaIds The catalog's schema IDs.
	 *
	 * @return array<int, array<string, mixed>|null> Schema ID → HVD config (or null).
	 *
	 * @spec openspec/specs/dcat-ap-harvest/spec.md
	 */
	private function resolveSchemaHvds(array $schemaIds): array {
		$hvds = [];
		$schemaMapper = $this->getSchemaMapper();
		foreach ($schemaIds as $schemaId) {
			try {
				$schema = $schemaMapper->find((int)$schemaId);
				$schemaData = $schema->jsonSerialize();
				$hvds[(int)$schemaId] = $this->mappingService->resolveHvd($schemaData);
			} catch (\Throwable $e) {
				$hvds[(int)$schemaId] = null;
			}
		}

		return $hvds;
	}//end resolveSchemaHvds()

	/**
	 * Normalise a catalog register/schema list (array or JSON string) to integer IDs.
	 *
	 * @param array<mixed>|string|null $raw The catalog field value.
	 *
	 * @return array<int, int> The integer ID list.
	 */
	private function idList(array|string|null $raw): array {
		if ($raw === null) {
			return [];
		}

		if (is_string($raw) === true) {
			$decoded = json_decode($raw, true);
			$raw = [];
			if (is_array($decoded) === true) {
				$raw = $decoded;
			}
		}

		$ids = [];
		foreach ($raw as $value) {
			if (is_numeric($value) === true) {
				$ids[] = (int)$value;
			}
		}

		return $ids;
	}//end idList()

	/**
	 * Normalise an OpenRegister entity or array result to an array.
	 *
	 * @param mixed $item An ObjectEntity (jsonSerialize) or a plain array.
	 *
	 * @return array<string, mixed> The array representation.
	 */
	private function toArray(mixed $item): array {
		if (is_array($item) === true) {
			return $item;
		}

		if (is_object($item) === true && method_exists($item, 'jsonSerialize') === true) {
			return $item->jsonSerialize();
		}

		return [];
	}//end toArray()
}//end class
