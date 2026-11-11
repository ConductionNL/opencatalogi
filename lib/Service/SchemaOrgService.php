<?php

/**
 * OpenCatalogi schema.org JSON-LD discoverability service.
 *
 * Read-only rendering layer (hydra ADR-022): no new storage and no new
 * visibility rule. It renders a schema.org JSON-LD representation of a
 * publication or catalog from the `x-schema-org` markers already declared on the
 * OpenRegister schemas (ADR-048/051), reusing the same OpenRegister object-search
 * path (RBAC-governed `publicationDate <= now` visibility) the public
 * publications API uses. The parallel open-web surface to {@see DcatService}:
 * DCAT-AP-NL RDF is for government/EU harvesters, schema.org JSON-LD is what
 * Google Dataset Search indexes.
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

use OCA\OpenCatalogi\Contract\PublicationQueryServiceInterface;
use OCA\OpenCatalogi\Contract\SchemaOrgServiceInterface;
use OCP\IURLGenerator;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Renders schema.org JSON-LD nodes for publications and catalogs.
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 * @SuppressWarnings(PHPMD.ExcessiveClassComplexity)
 *
 * @spec openspec/specs/structured-data-discoverability/spec.md
 */
class SchemaOrgService implements SchemaOrgServiceInterface {

	/**
	 * Schema.org JSON-LD context IRI.
	 *
	 * @var string
	 */
	public const CONTEXT = 'https://schema.org';

	/**
	 * Page-size ceiling for the catalog dataset listing (mirrors DcatService).
	 *
	 * @var integer
	 */
	public const MAX_PER_PAGE = 1000;

	/**
	 * The `@type` a publication defaults to when its schema declares no marker.
	 *
	 * @var string
	 */
	private const DEFAULT_TYPE = 'CreativeWork';

	/**
	 * Constructor.
	 *
	 * @param ContainerInterface $container Server container for OR service resolution.
	 * @param DcatService $dcatService Reused for canonical IRIs and catalog defaults.
	 * @param IURLGenerator $urlGenerator Nextcloud URL generator (absolute IRIs).
	 * @param LoggerInterface $logger PSR-3 logger.
	 */
	public function __construct(
		private readonly ContainerInterface $container,
		private readonly DcatService $dcatService,
		private readonly IURLGenerator $urlGenerator,
		private readonly LoggerInterface $logger,
	) {

	}//end __construct()

	/**
	 * Resolve the OpenRegister ObjectService, or null when unavailable.
	 *
	 * @return object|null The ObjectService, or null.
	 *
	 * @spec exclude Lazy DI accessor for the OR ObjectService; pure plumbing.
	 */
	private function getObjectService(): ?object {
		try {
			return $this->container->get('OCA\OpenRegister\Service\ObjectService');
		} catch (\Throwable $e) {
			return null;
		}

	}//end getObjectService()

	/**
	 * Resolve the OpenRegister FileService, or null when unavailable.
	 *
	 * @return object|null The FileService, or null.
	 *
	 * @spec exclude Lazy DI accessor for the OR FileService; pure plumbing.
	 */
	private function getFileService(): ?object {
		try {
			return $this->container->get('OCA\OpenRegister\Service\FileService');
		} catch (\Throwable $e) {
			return null;
		}

	}//end getFileService()

	/**
	 * Resolve the OpenRegister SchemaMapper, or null when unavailable.
	 *
	 * @return object|null The SchemaMapper, or null.
	 *
	 * @spec exclude Lazy DI accessor for the OR SchemaMapper; pure plumbing.
	 */
	private function getSchemaMapper(): ?object {
		try {
			return $this->container->get('OCA\OpenRegister\Db\SchemaMapper');
		} catch (\Throwable $e) {
			return null;
		}

	}//end getSchemaMapper()

	/**
	 * Normalise an OpenRegister object (entity or array) to a plain array.
	 *
	 * @param mixed $item The OR object.
	 *
	 * @return array<string, mixed> The object's fields.
	 *
	 * @spec exclude Shape-normalisation plumbing; no domain behaviour.
	 */
	private function toArray(mixed $item): array {
		if (is_array($item) === true) {
			return $item;
		}

		if (is_object($item) === true && method_exists($item, 'jsonSerialize') === true) {
			$data = $item->jsonSerialize();
			if (is_array($data) === true) {
				return $data;
			}
		}

		return [];
	}//end toArray()

	/**
	 * Resolve the schema.org `@type` for a schema from its `x-schema-org` marker.
	 *
	 * Reads the CURIE (`schema:X`) declared on the schema (ADR-048/051) and strips
	 * the `schema:` prefix. Never introduces an app-local marker registry.
	 *
	 * @param integer $schemaId The OpenRegister schema ID.
	 *
	 * @return string The bare schema.org type (e.g. `CreativeWork`), defaulting to `CreativeWork`.
	 *
	 * @spec openspec/specs/structured-data-discoverability/spec.md
	 */
	public function markerTypeForSchema(int $schemaId): string {
		$schemaMapper = $this->getSchemaMapper();
		if ($schemaMapper === null) {
			return self::DEFAULT_TYPE;
		}

		try {
			$marker = (string)($this->toArray(item: $schemaMapper->find($schemaId))['x-schema-org'] ?? '');
		} catch (\Throwable $e) {
			$this->logger->debug('[SchemaOrgService] Could not resolve schema marker', ['schema' => $schemaId, 'error' => $e->getMessage()]);
			return self::DEFAULT_TYPE;
		}

		return $this->bareType(marker: $marker);
	}//end markerTypeForSchema()

	/**
	 * The bare schema.org type of a `schema:X` marker.
	 *
	 * @param string $marker The schema's `x-schema-org` value.
	 *
	 * @return string The type without the `schema:` prefix, or the default type when nothing is left.
	 *
	 * @spec openspec/specs/structured-data-discoverability/spec.md
	 */
	private function bareType(string $marker): string {
		// Strip the `schema:` CURIE prefix, leaving the bare type.
		$type = preg_replace('/^schema:/', '', $marker);
		if ($type === null || $type === '') {
			return self::DEFAULT_TYPE;
		}

		return $type;
	}//end bareType()

	/**
	 * Whether the catalog elects `schema:Dataset` for its publications.
	 *
	 * Reuses the catalog config surface — a `schemaOrgDataset` boolean on the
	 * catalog object — defaulting off. Google Dataset Search indexes only
	 * `Dataset`, so open-data catalogs opt in.
	 *
	 * @param array<string, mixed> $catalog The catalog object.
	 *
	 * @return boolean True when the catalog elects the Dataset shape.
	 *
	 * @spec openspec/specs/structured-data-discoverability/spec.md
	 */
	public function isDatasetElected(array $catalog): bool {
		return filter_var(($catalog['schemaOrgDataset'] ?? false), FILTER_VALIDATE_BOOLEAN);
	}//end isDatasetElected()

	/**
	 * Build the canonical schema.org catalog URL (its `@id`).
	 *
	 * @param string $catalogSlug The catalog slug.
	 *
	 * @return string The absolute catalog schema.org endpoint URL.
	 *
	 * @spec exclude URL-assembly plumbing.
	 */
	private function catalogNodeUrl(string $catalogSlug): string {
		$base = rtrim($this->urlGenerator->getBaseUrl(), '/');
		return "$base/apps/opencatalogi/api/catalogs/$catalogSlug/schema";
	}//end catalogNodeUrl()

	/**
	 * Build a schema.org publisher `Organization` node from the catalog defaults.
	 *
	 * @param array<string, mixed> $catalog The catalog object.
	 *
	 * @return array<string, mixed>|null The Organization node, or null when nothing resolves.
	 *
	 * @spec exclude Publisher-assembly plumbing reusing the DCAT default chain.
	 */
	private function buildPublisher(array $catalog): ?array {
		$defaults = $this->dcatService->resolveDefaults($catalog);
		$organisation = ($defaults['organisation'] ?? null);
		$name = ($organisation['title'] ?? $organisation['name'] ?? $defaults['publisherName'] ?? '');
		$uri = ($organisation['oin'] ?? $organisation['uri'] ?? $defaults['publisherUri'] ?? '');

		$publisher = array_filter(['name' => $name, '@id' => $uri], static fn ($value) => $value !== '');
		if ($publisher === []) {
			return null;
		}

		return ['@type' => 'Organization'] + array_map('strval', $publisher);
	}//end buildPublisher()

	/**
	 * Map a publication's published attachments to schema.org `DataDownload` nodes.
	 *
	 * Reuses the DCAT-006 download-URL + media-type resolution by loading the same
	 * formatFiles shape the DCAT distribution machinery consumes.
	 *
	 * @param array<string, mixed> $publication The publication object.
	 *
	 * @return array<int, array<string, mixed>> The `DataDownload` nodes.
	 *
	 * @spec openspec/specs/structured-data-discoverability/spec.md
	 */
	private function buildDistributions(array $publication): array {
		$fileService = $this->getFileService();
		$objectId = ($publication['id'] ?? $publication['@self']['uuid'] ?? null);
		if ($fileService === null || $objectId === null) {
			return [];
		}

		$distributions = [];
		foreach ($this->attachmentsOf(fileService: $fileService, objectId: $objectId) as $file) {
			$node = $this->downloadNode(file: $file);
			if ($node !== null) {
				$distributions[] = $node;
			}
		}

		return $distributions;
	}//end buildDistributions()

	/**
	 * The formatted attachments of a publication, or none when they cannot be loaded.
	 *
	 * @param object $fileService The OpenRegister FileService.
	 * @param mixed $objectId The publication id or uuid.
	 *
	 * @return mixed The formatFiles `results`.
	 *
	 * @spec openspec/specs/structured-data-discoverability/spec.md
	 */
	private function attachmentsOf(object $fileService, mixed $objectId): mixed {
		try {
			return ($fileService->formatFiles($fileService->getFiles(object: $objectId))['results'] ?? []);
		} catch (\Throwable $e) {
			$this->logger->debug('[SchemaOrgService] Could not load files', ['id' => $objectId, 'error' => $e->getMessage()]);
			return [];
		}
	}//end attachmentsOf()

	/**
	 * The `DataDownload` node of one attachment, or null when it has no download url.
	 *
	 * @param array<string, mixed> $file The formatted attachment.
	 *
	 * @return array<string, string>|null The node.
	 *
	 * @spec openspec/specs/structured-data-discoverability/spec.md
	 */
	private function downloadNode(array $file): ?array {
		$downloadUrl = ($file['downloadUrl'] ?? $file['accessUrl'] ?? null);
		if (is_string($downloadUrl) === false || $downloadUrl === '') {
			return null;
		}

		return ['@type' => 'DataDownload', 'contentUrl' => $downloadUrl] + $this->downloadDetails(file: $file);
	}//end downloadNode()

	/**
	 * The media type, name and size of an attachment, each only when usable.
	 *
	 * @param array<string, mixed> $file The formatted attachment.
	 *
	 * @return array<string, string> The `encodingFormat`, `name` and `contentSize` fields.
	 *
	 * @spec openspec/specs/structured-data-discoverability/spec.md
	 */
	private function downloadDetails(array $file): array {
		$details = array_filter(
			[
				'encodingFormat' => ($file['mimetype'] ?? $file['mimeType'] ?? null),
				'name' => ($file['title'] ?? $file['name'] ?? null),
			],
			$this->isNonEmptyString(...)
		);
		if (isset($file['size']) === true && is_numeric($file['size']) === true) {
			$details['contentSize'] = (string)((int)$file['size']);
		}

		return $details;
	}//end downloadDetails()

	/**
	 * Whether a value is a non-empty string.
	 *
	 * @param mixed $value The value.
	 *
	 * @return bool True for a string other than ''.
	 *
	 * @spec openspec/specs/structured-data-discoverability/spec.md
	 */
	private function isNonEmptyString(mixed $value): bool {
		return is_string($value) === true && $value !== '';
	}//end isNonEmptyString()

	/**
	 * Build the schema.org JSON-LD node for a single publication.
	 *
	 * The `@type` is the publication schema's `x-schema-org` marker, or `Dataset`
	 * when the catalog elects it. When elected, the node carries `distribution`
	 * (`DataDownload` per attachment) and an `includedInDataCatalog` backlink.
	 *
	 * @param array<string, mixed> $object The rendered publication object.
	 * @param array<string, mixed> $catalog The owning catalog object.
	 * @param string $catalogSlug The catalog slug.
	 *
	 * @return array<string, mixed> The schema.org JSON-LD node (single document).
	 *
	 * @spec openspec/specs/structured-data-discoverability/spec.md
	 */
	public function buildPublicationNode(array $object, array $catalog, string $catalogSlug): array {
		$url = $this->dcatService->datasetIri($catalogSlug, (string)($object['@self']['uuid'] ?? $object['id'] ?? ''));
		$elected = $this->isDatasetElected(catalog: $catalog);
		$node = array_merge(
			['@context' => self::CONTEXT, '@type' => $this->nodeType(object: $object, elected: $elected), '@id' => $url, 'url' => $url],
			$this->descriptiveFields(object: $object),
			$this->publisherField(catalog: $catalog)
		);

		if ($elected === true) {
			$node += $this->distributionField(publication: $object);
			$node['includedInDataCatalog'] = $this->catalogBacklink(catalog: $catalog, catalogSlug: $catalogSlug);
		}

		return $node;
	}//end buildPublicationNode()

	/**
	 * The `publisher` field of a node, or none when no publisher resolves.
	 *
	 * @param array<string, mixed> $catalog The catalog object.
	 *
	 * @return array<string, array<string, mixed>> The field.
	 *
	 * @spec openspec/specs/structured-data-discoverability/spec.md
	 */
	private function publisherField(array $catalog): array {
		$publisher = $this->buildPublisher(catalog: $catalog);
		if ($publisher === null) {
			return [];
		}

		return ['publisher' => $publisher];
	}//end publisherField()

	/**
	 * The `distribution` field of an elected publication, or none without downloads.
	 *
	 * @param array<string, mixed> $publication The publication object.
	 *
	 * @return array<string, array<int, array<string, mixed>>> The field.
	 *
	 * @spec openspec/specs/structured-data-discoverability/spec.md
	 */
	private function distributionField(array $publication): array {
		$distributions = $this->buildDistributions(publication: $publication);
		if ($distributions === []) {
			return [];
		}

		return ['distribution' => $distributions];
	}//end distributionField()

	/**
	 * The given text fields that are not '', as strings.
	 *
	 * @param array<string, mixed> $texts The candidate fields.
	 *
	 * @return array<string, string> The usable fields, in order.
	 *
	 * @spec openspec/specs/structured-data-discoverability/spec.md
	 */
	private function textFields(array $texts): array {
		return array_map('strval', array_filter($texts, static fn ($value) => $value !== ''));
	}//end textFields()

	/**
	 * The `@type` of a publication node: `Dataset` when the catalog elects it,
	 * otherwise the schema's `x-schema-org` marker.
	 *
	 * The marker is looked up in both cases.
	 *
	 * @param array<string, mixed> $object The rendered publication object.
	 * @param bool $elected Whether the catalog elects the Dataset shape.
	 *
	 * @return string The type.
	 *
	 * @spec openspec/specs/structured-data-discoverability/spec.md
	 */
	private function nodeType(array $object, bool $elected): string {
		$type = $this->markerTypeForSchema(schemaId: (int)($object['@self']['schema'] ?? 0));
		if ($elected === true) {
			$type = 'Dataset';
		}

		return $type;
	}//end nodeType()

	/**
	 * The name, description, modification date, licence and keywords a publication carries.
	 *
	 * A field is left out when the publication has no usable value for it.
	 *
	 * @param array<string, mixed> $object The rendered publication object.
	 *
	 * @return array<string, mixed> The fields, in node order.
	 *
	 * @spec openspec/specs/structured-data-discoverability/spec.md
	 */
	private function descriptiveFields(array $object): array {
		$fields = $this->textFields(
			texts: [
				'name' => ($object['title'] ?? $object['name'] ?? ''),
				'description' => ($object['description'] ?? $object['summary'] ?? ''),
			]
		);

		$modified = ($object['@self']['updated'] ?? $object['publicationDate'] ?? null);
		if ($modified !== null && $modified !== '') {
			$fields['dateModified'] = $this->isoDate(value: (string)$modified);
		}

		return $fields + $this->licenseAndKeywords(object: $object);
	}//end descriptiveFields()

	/**
	 * The licence and keywords of a publication, each only when usable.
	 *
	 * @param array<string, mixed> $object The rendered publication object.
	 *
	 * @return array<string, mixed> The `license` and `keywords` fields.
	 *
	 * @spec openspec/specs/structured-data-discoverability/spec.md
	 */
	private function licenseAndKeywords(array $object): array {
		$fields = [];
		$license = ($object['license'] ?? null);
		if ($this->isNonEmptyString(value: $license) === true) {
			$fields['license'] = $license;
		}

		$keywords = ($object['tags'] ?? null);
		if (is_array($keywords) === true && $keywords !== []) {
			$fields['keywords'] = $this->keywordStrings(keywords: $keywords);
		}

		return $fields;
	}//end licenseAndKeywords()

	/**
	 * The non-empty scalar tags of a publication, as strings.
	 *
	 * @param array<int|string, mixed> $keywords The publication's tags.
	 *
	 * @return list<string> The keywords.
	 *
	 * @spec openspec/specs/structured-data-discoverability/spec.md
	 */
	private function keywordStrings(array $keywords): array {
		$strings = [];
		foreach ($keywords as $keyword) {
			if (is_scalar($keyword) === true && (string)$keyword !== '') {
				$strings[] = (string)$keyword;
			}
		}

		return $strings;
	}//end keywordStrings()

	/**
	 * The `includedInDataCatalog` backlink of an elected publication.
	 *
	 * @param array<string, mixed> $catalog The owning catalog object.
	 * @param string $catalogSlug The catalog slug.
	 *
	 * @return array<string, string> The DataCatalog reference.
	 *
	 * @spec openspec/specs/structured-data-discoverability/spec.md
	 */
	private function catalogBacklink(array $catalog, string $catalogSlug): array {
		return [
			'@type' => 'DataCatalog',
			'@id' => $this->catalogNodeUrl(catalogSlug: $catalogSlug),
			'name' => (string)($catalog['title'] ?? $catalogSlug),
		];
	}//end catalogBacklink()

	/**
	 * Build the schema.org `DataCatalog` node for a catalog, listing its publicly
	 * visible publications as `dataset` references.
	 *
	 * @param array<string, mixed> $catalog The catalog object.
	 * @param string $catalogSlug The catalog slug.
	 *
	 * @return array<string, mixed> The schema.org `DataCatalog` JSON-LD node.
	 *
	 * @spec openspec/specs/structured-data-discoverability/spec.md
	 */
	public function buildCatalogNode(array $catalog, string $catalogSlug): array {
		$catalogUrl = $this->catalogNodeUrl(catalogSlug: $catalogSlug);

		$node = array_merge(
			[
				'@context' => self::CONTEXT,
				'@type' => 'DataCatalog',
				'@id' => $catalogUrl,
				'url' => $catalogUrl,
				'name' => (string)($catalog['title'] ?? $catalogSlug),
			],
			$this->textFields(texts: ['description' => ($catalog['description'] ?? '')]),
			$this->publisherField(catalog: $catalog)
		);
		return $node + ['dataset' => $this->listVisiblePublicationRefs(catalog: $catalog, catalogSlug: $catalogSlug)];
	}//end buildCatalogNode()

	/**
	 * List the catalog's publicly visible publications as `{@id}` dataset refs.
	 *
	 * Selection goes through the OR object-search path with `_rbac: true`. A signed-in
	 * caller may read drafts that way, so the refs keep only publications inside their
	 * publication window ({@see PublicationQueryServiceInterface::isObjectPublic()}).
	 *
	 * @param array<string, mixed> $catalog The catalog object.
	 * @param string $catalogSlug The catalog slug.
	 *
	 * @return array<int, array<string, string>> The dataset reference nodes.
	 *
	 * @spec openspec/specs/structured-data-discoverability/spec.md
	 */
	private function listVisiblePublicationRefs(array $catalog, string $catalogSlug): array {
		$objectService = $this->getObjectService();
		$searchQuery = $this->catalogScopeQuery(catalog: $catalog);
		if ($objectService === null || $searchQuery === null) {
			return [];
		}

		try {
			$result = $objectService->searchObjectsPaginated(query: $searchQuery, _rbac: true, _multitenancy: false);
		} catch (\Throwable $e) {
			$this->logger->debug('[SchemaOrgService] Catalog dataset listing failed', ['catalog' => $catalogSlug, 'error' => $e->getMessage()]);
			return [];
		}

		return $this->datasetRefs(publications: ($result['results'] ?? []), catalogSlug: $catalogSlug);
	}//end listVisiblePublicationRefs()

	/**
	 * The search over the catalog's registers and schemas, or null when either list is empty.
	 *
	 * One schema in one register takes OR's scalar fast path; anything else keeps the
	 * lists OR's multi-schema route reads (WOO-581 review round 4, CallerScope::writeScope()).
	 *
	 * @param array<string, mixed> $catalog The catalog object.
	 *
	 * @return array<string, mixed>|null
	 *
	 * @SuppressWarnings(PHPMD.StaticAccess) CallerScope::writeScope() is a pure static function.
	 *
	 * @spec openspec/specs/structured-data-discoverability/spec.md
	 */
	private function catalogScopeQuery(array $catalog): ?array {
		$registers = $this->normaliseIdList(raw: ($catalog['registers'] ?? []));
		$schemas = $this->normaliseIdList(raw: ($catalog['schemas'] ?? []));
		if ($registers === [] || $schemas === []) {
			return null;
		}

		return CallerScope::writeScope(query: ['_limit' => self::MAX_PER_PAGE], registers: $registers, schemas: $schemas);
	}//end catalogScopeQuery()

	/**
	 * The `{@id}` dataset refs of the public publications found, skipping any without a
	 * uuid or id.
	 *
	 * Results that are not a list are refused with a warning: no refs. Without the
	 * publication query service no publication counts as public: no refs.
	 *
	 * @param mixed $publications The search results.
	 * @param string $catalogSlug The catalog slug.
	 *
	 * @return array<int, array<string, string>>
	 *
	 * @spec openspec/specs/structured-data-discoverability/spec.md
	 */
	private function datasetRefs(mixed $publications, string $catalogSlug): array {
		if (is_iterable($publications) === false) {
			$this->logger->warning('[SchemaOrgService] Catalog dataset listing returned no list', ['catalog' => $catalogSlug]);
			return [];
		}

		$visibility = $this->publicationVisibility();
		if ($visibility === null) {
			return [];
		}

		$refs = [];
		foreach ($publications as $publication) {
			$publication = $this->toArray(item: $publication);
			$uuid = (string)($publication['@self']['uuid'] ?? $publication['id'] ?? '');
			if ($uuid !== '' && $visibility->isObjectPublic(objectData: $publication) === true) {
				$refs[] = ['@id' => $this->dcatService->datasetIri($catalogSlug, $uuid)];
			}
		}

		return $refs;
	}//end datasetRefs()

	/**
	 * The publication query service that decides the publication window, or null when unavailable.
	 *
	 * @return PublicationQueryServiceInterface|null The service, or null.
	 *
	 * @spec exclude Lazy DI accessor for the publication window rule; pure plumbing.
	 */
	private function publicationVisibility(): ?PublicationQueryServiceInterface {
		try {
			$service = $this->container->get(PublicationQueryServiceInterface::class);
		} catch (\Throwable $e) {
			$this->logger->warning('[SchemaOrgService] Publication query service unavailable', ['error' => $e->getMessage()]);
			return null;
		}

		if ($service instanceof PublicationQueryServiceInterface === false) {
			return null;
		}

		return $service;
	}//end publicationVisibility()

	/**
	 * Normalise a catalog register/schema list (array or JSON string) to integer IDs.
	 *
	 * @param array<mixed>|string|null $raw The catalog field value.
	 *
	 * @return array<int, int> The integer ID list.
	 *
	 * @spec exclude ID-list normalisation plumbing.
	 */
	private function normaliseIdList(array|string|null $raw): array {
		if (is_string($raw) === true) {
			$raw = json_decode($raw, true);
		}

		if (is_array($raw) === false) {
			return [];
		}

		return array_map('intval', array_values(array_filter($raw, 'is_numeric')));
	}//end normaliseIdList()

	/**
	 * Format a date value as an ISO-8601 string.
	 *
	 * @param string $value The raw date value.
	 *
	 * @return string The ISO-8601 date, or the original value when unparseable.
	 *
	 * @spec exclude Date-format plumbing.
	 */
	private function isoDate(string $value): string {
		$timestamp = strtotime($value);
		if ($timestamp === false) {
			return $value;
		}

		return gmdate('c', $timestamp);
	}//end isoDate()
}//end class
