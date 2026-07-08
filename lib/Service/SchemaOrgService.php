<?php
/**
 * OpenCatalogi schema.org JSON-LD discoverability service.
 *
 * Read-only rendering layer (hydra ADR-022): no new storage and no new
 * visibility rule. It renders a schema.org JSON-LD representation of a
 * publication or catalog from the `x-schema-org` markers already declared on the
 * OpenRegister schemas (ADR-048/051), reusing the same OpenRegister object-search
 * path (RBAC-governed `publicatiedatum <= now` visibility) the public
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
class SchemaOrgService
{

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
     * @param ContainerInterface $container    Server container for OR service resolution.
     * @param DcatService        $dcatService  Reused for canonical IRIs and catalog defaults.
     * @param IURLGenerator      $urlGenerator Nextcloud URL generator (absolute IRIs).
     * @param LoggerInterface    $logger       PSR-3 logger.
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
    private function getObjectService(): ?object
    {
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
    private function getFileService(): ?object
    {
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
    private function getSchemaMapper(): ?object
    {
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
    private function toArray(mixed $item): array
    {
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
    public function markerTypeForSchema(int $schemaId): string
    {
        $schemaMapper = $this->getSchemaMapper();
        if ($schemaMapper === null) {
            return self::DEFAULT_TYPE;
        }

        try {
            $schema     = $schemaMapper->find($schemaId);
            $schemaData = $this->toArray($schema);
            $marker     = (string) ($schemaData['x-schema-org'] ?? '');
            if ($marker === '') {
                return self::DEFAULT_TYPE;
            }

            // Strip the `schema:` CURIE prefix, leaving the bare type.
            $type = preg_replace('/^schema:/', '', $marker);
            if ($type === null || $type === '') {
                return self::DEFAULT_TYPE;
            }

            return $type;
        } catch (\Throwable $e) {
            $this->logger->debug('[SchemaOrgService] Could not resolve schema marker', ['schema' => $schemaId, 'error' => $e->getMessage()]);
            return self::DEFAULT_TYPE;
        }//end try

    }//end markerTypeForSchema()

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
    public function isDatasetElected(array $catalog): bool
    {
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
    private function catalogNodeUrl(string $catalogSlug): string
    {
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
    private function buildPublisher(array $catalog): ?array
    {
        $defaults     = $this->dcatService->resolveDefaults($catalog);
        $organisation = ($defaults['organisation'] ?? null);
        $name         = ($organisation['title'] ?? $organisation['name'] ?? $defaults['publisherName'] ?? '');
        $uri          = ($organisation['oin'] ?? $organisation['uri'] ?? $defaults['publisherUri'] ?? '');

        if ($name === '' && $uri === '') {
            return null;
        }

        $publisher = ['@type' => 'Organization'];
        if ($name !== '') {
            $publisher['name'] = (string) $name;
        }

        if ($uri !== '') {
            $publisher['@id'] = (string) $uri;
        }

        return $publisher;

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
     * @SuppressWarnings(PHPMD.CyclomaticComplexity)
     * @SuppressWarnings(PHPMD.NPathComplexity)
     *
     * @spec openspec/specs/structured-data-discoverability/spec.md
     */
    private function buildDistributions(array $publication): array
    {
        $fileService = $this->getFileService();
        if ($fileService === null) {
            return [];
        }

        $objectId = ($publication['id'] ?? $publication['@self']['uuid'] ?? null);
        if ($objectId === null) {
            return [];
        }

        $files = [];
        try {
            $files = ($fileService->formatFiles($fileService->getFiles(object: $objectId))['results'] ?? []);
        } catch (\Throwable $e) {
            $this->logger->debug('[SchemaOrgService] Could not load files', ['id' => $objectId, 'error' => $e->getMessage()]);
            return [];
        }

        $distributions = [];
        foreach ($files as $file) {
            $downloadUrl = ($file['downloadUrl'] ?? $file['accessUrl'] ?? null);
            if (is_string($downloadUrl) === false || $downloadUrl === '') {
                continue;
            }

            $node = [
                '@type'      => 'DataDownload',
                'contentUrl' => $downloadUrl,
            ];

            $mimetype = ($file['mimetype'] ?? $file['mimeType'] ?? null);
            if (is_string($mimetype) === true && $mimetype !== '') {
                $node['encodingFormat'] = $mimetype;
            }

            $title = ($file['title'] ?? $file['name'] ?? null);
            if (is_string($title) === true && $title !== '') {
                $node['name'] = $title;
            }

            if (isset($file['size']) === true && is_numeric($file['size']) === true) {
                $node['contentSize'] = (string) ((int) $file['size']);
            }

            $distributions[] = $node;
        }//end foreach

        return $distributions;

    }//end buildDistributions()

    /**
     * Build the schema.org JSON-LD node for a single publication.
     *
     * The `@type` is the publication schema's `x-schema-org` marker, or `Dataset`
     * when the catalog elects it. When elected, the node carries `distribution`
     * (`DataDownload` per attachment) and an `includedInDataCatalog` backlink.
     *
     * @param array<string, mixed> $object      The rendered publication object.
     * @param array<string, mixed> $catalog     The owning catalog object.
     * @param string               $catalogSlug The catalog slug.
     *
     * @return array<string, mixed> The schema.org JSON-LD node (single document).
     *
     * @SuppressWarnings(PHPMD.CyclomaticComplexity)
     * @SuppressWarnings(PHPMD.NPathComplexity)
     *
     * @spec openspec/specs/structured-data-discoverability/spec.md
     */
    public function buildPublicationNode(array $object, array $catalog, string $catalogSlug): array
    {
        $uuid = (string) ($object['@self']['uuid'] ?? $object['id'] ?? '');
        $url  = $this->dcatService->datasetIri($catalogSlug, $uuid);

        $elected  = $this->isDatasetElected($catalog);
        $schemaId = (int) ($object['@self']['schema'] ?? 0);
        $type     = $this->markerTypeForSchema($schemaId);
        if ($elected === true) {
            $type = 'Dataset';
        }

        $node = [
            '@context' => self::CONTEXT,
            '@type'    => $type,
            '@id'      => $url,
            'url'      => $url,
        ];

        $name = ($object['title'] ?? $object['name'] ?? '');
        if ($name !== '') {
            $node['name'] = (string) $name;
        }

        $description = ($object['description'] ?? $object['summary'] ?? '');
        if ($description !== '') {
            $node['description'] = (string) $description;
        }

        $modified = ($object['@self']['updated'] ?? $object['publicatiedatum'] ?? null);
        if ($modified !== null && $modified !== '') {
            $node['dateModified'] = $this->isoDate((string) $modified);
        }

        $license = ($object['license'] ?? null);
        if (is_string($license) === true && $license !== '') {
            $node['license'] = $license;
        }

        $keywords = ($object['tags'] ?? null);
        if (is_array($keywords) === true && $keywords !== []) {
            $strings = [];
            foreach ($keywords as $keyword) {
                if (is_scalar($keyword) === true && (string) $keyword !== '') {
                    $strings[] = (string) $keyword;
                }
            }

            $node['keywords'] = array_values($strings);
        }

        $publisher = $this->buildPublisher($catalog);
        if ($publisher !== null) {
            $node['publisher'] = $publisher;
        }

        if ($elected === true) {
            $distributions = $this->buildDistributions($object);
            if ($distributions !== []) {
                $node['distribution'] = $distributions;
            }

            $node['includedInDataCatalog'] = [
                '@type' => 'DataCatalog',
                '@id'   => $this->catalogNodeUrl($catalogSlug),
                'name'  => (string) ($catalog['title'] ?? $catalogSlug),
            ];
        }

        return $node;

    }//end buildPublicationNode()

    /**
     * Build the schema.org `DataCatalog` node for a catalog, listing its publicly
     * visible publications as `dataset` references.
     *
     * @param array<string, mixed> $catalog     The catalog object.
     * @param string               $catalogSlug The catalog slug.
     *
     * @return array<string, mixed> The schema.org `DataCatalog` JSON-LD node.
     *
     * @spec openspec/specs/structured-data-discoverability/spec.md
     */
    public function buildCatalogNode(array $catalog, string $catalogSlug): array
    {
        $catalogUrl = $this->catalogNodeUrl($catalogSlug);

        $node = [
            '@context' => self::CONTEXT,
            '@type'    => 'DataCatalog',
            '@id'      => $catalogUrl,
            'url'      => $catalogUrl,
            'name'     => (string) ($catalog['title'] ?? $catalogSlug),
        ];

        if (($catalog['description'] ?? '') !== '') {
            $node['description'] = (string) $catalog['description'];
        }

        $publisher = $this->buildPublisher($catalog);
        if ($publisher !== null) {
            $node['publisher'] = $publisher;
        }

        $node['dataset'] = $this->listVisiblePublicationRefs($catalog, $catalogSlug);

        return $node;

    }//end buildCatalogNode()

    /**
     * List the catalog's publicly visible publications as `{@id}` dataset refs.
     *
     * Selection goes through the OR object-search path with `_rbac: true`, so only
     * publicly visible (published, not depublished) publications appear.
     *
     * @param array<string, mixed> $catalog     The catalog object.
     * @param string               $catalogSlug The catalog slug.
     *
     * @return array<int, array<string, string>> The dataset reference nodes.
     *
     * @spec openspec/specs/structured-data-discoverability/spec.md
     */
    private function listVisiblePublicationRefs(array $catalog, string $catalogSlug): array
    {
        $objectService = $this->getObjectService();
        if ($objectService === null) {
            return [];
        }

        $registers = $this->normaliseIdList(($catalog['registers'] ?? []));
        $schemas   = $this->normaliseIdList(($catalog['schemas'] ?? []));
        if ($registers === [] || $schemas === []) {
            return [];
        }

        $registerScope = $registers;
        if (count($registers) === 1) {
            $registerScope = $registers[0];
        }

        $schemaScope = $schemas;
        if (count($schemas) === 1) {
            $schemaScope = $schemas[0];
        }

        $searchQuery = [
            '_limit' => self::MAX_PER_PAGE,
            '@self'  => [
                'register' => $registerScope,
                'schema'   => $schemaScope,
            ],
        ];

        try {
            $result = $objectService->searchObjectsPaginated(
                query: $searchQuery,
                _rbac: true,
                _multitenancy: false,
                deleted: false
            );
        } catch (\Throwable $e) {
            $this->logger->debug('[SchemaOrgService] Catalog dataset listing failed', ['catalog' => $catalogSlug, 'error' => $e->getMessage()]);
            return [];
        }

        $refs = [];
        foreach (($result['results'] ?? []) as $publication) {
            $publication = $this->toArray($publication);
            $uuid        = (string) ($publication['@self']['uuid'] ?? $publication['id'] ?? '');
            if ($uuid === '') {
                continue;
            }

            $refs[] = ['@id' => $this->dcatService->datasetIri($catalogSlug, $uuid)];
        }

        return $refs;

    }//end listVisiblePublicationRefs()

    /**
     * Normalise a catalog register/schema list (array or JSON string) to integer IDs.
     *
     * @param array<mixed>|string|null $raw The catalog field value.
     *
     * @return array<int, int> The integer ID list.
     *
     * @spec exclude ID-list normalisation plumbing.
     */
    private function normaliseIdList(array | string | null $raw): array
    {
        if ($raw === null) {
            return [];
        }

        if (is_string($raw) === true) {
            $decoded = json_decode($raw, true);
            $raw     = [];
            if (is_array($decoded) === true) {
                $raw = $decoded;
            }
        }

        $ids = [];
        foreach ($raw as $value) {
            if (is_numeric($value) === true) {
                $ids[] = (int) $value;
            }
        }

        return $ids;

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
    private function isoDate(string $value): string
    {
        $timestamp = strtotime($value);
        if ($timestamp === false) {
            return $value;
        }

        return gmdate('c', $timestamp);

    }//end isoDate()
}//end class
