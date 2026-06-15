<?php
/**
 * OpenCatalogi DCAT-AP-NL harvest service.
 *
 * Read-only rendering layer (hydra ADR-022): no new storage, no new visibility
 * rules, no new query layer. It selects publicly visible publications via the
 * same OpenRegister object-search path the public publications API uses
 * (RBAC-governed `publicatiedatum <= now` visibility), maps them with
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
class DcatService
{

    /**
     * Page-size ceiling — identical to the sitemap ceiling (WOO-005).
     *
     * @var integer
     */
    public const MAX_PER_PAGE = 1000;

    /**
     * DcatService constructor.
     *
     * @param ContainerInterface $container      Server container for OR service resolution.
     * @param IAppManager        $appManager     App manager for OpenRegister availability checks.
     * @param DcatMappingService $mappingService Pure publication → DCAT mapping.
     * @param DcatSerializer     $serializer     Pure graph → JSON-LD/Turtle/RDF-XML serializer.
     * @param IURLGenerator      $urlGenerator   Nextcloud URL generator (absolute IRIs).
     * @param IAppConfig         $appConfig      App config (publisher defaults).
     * @param LoggerInterface    $logger         PSR-3 logger.
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
    ) {

    }//end __construct()

    /**
     * Resolve the OpenRegister ObjectService from the container.
     *
     * @return \OCA\OpenRegister\Service\ObjectService The OpenRegister ObjectService.
     *
     * @throws RuntimeException When OpenRegister is not installed.
     */
    private function getObjectService(): \OCA\OpenRegister\Service\ObjectService
    {
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
    private function getFileService(): \OCA\OpenRegister\Service\FileService
    {
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
    private function getSchemaMapper(): \OCA\OpenRegister\Db\SchemaMapper
    {
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
     * @spec openspec/changes/dcat-ap-harvest/specs/dcat-ap-harvest/spec.md#requirement-admin-configuration-and-feed-validation-dcat-010
     */
    public function isDcatEnabled(array $catalog): bool
    {
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
     * @spec openspec/changes/dcat-ap-harvest/specs/dcat-ap-harvest/spec.md#requirement-dcat-ap-nl-mandatory-property-completion-dcat-005
     */
    public function resolveDefaults(array $catalog): array
    {
        $organisation = ($catalog['organisation'] ?? $catalog['organization'] ?? null);
        if (is_array($organisation) === false) {
            $organisation = null;
        }

        $defaultLicense = 'http://creativecommons.org/publicdomain/zero/1.0/';

        return [
            'publisherName' => ($catalog['dcatPublisherName'] ?? $this->appConfig->getValueString('opencatalogi', 'dcat_publisher_name', '')),
            'publisherUri'  => ($catalog['dcatPublisherUri'] ?? $this->appConfig->getValueString('opencatalogi', 'dcat_publisher_uri', '')),
            'license'       => ($catalog['dcatLicense'] ?? $this->appConfig->getValueString('opencatalogi', 'dcat_default_license', $defaultLicense)),
            'contactPoint'  => ($catalog['dcatContactPoint'] ?? $this->appConfig->getValueString('opencatalogi', 'dcat_contact_point', '')),
            'organisation'  => $organisation,
        ];

    }//end resolveDefaults()

    /**
     * Build the absolute per-catalog DCAT endpoint URL.
     *
     * @param string $catalogSlug The catalog slug.
     *
     * @return string The absolute DCAT endpoint URL.
     *
     * @spec openspec/changes/dcat-ap-harvest/specs/dcat-ap-harvest/spec.md#requirement-federation-directory-advertises-the-dcat-endpoint-dcat-009
     */
    public function catalogEndpointUrl(string $catalogSlug): string
    {
        $base = rtrim($this->urlGenerator->getBaseUrl(), '/');
        return "$base/apps/opencatalogi/api/catalogs/$catalogSlug/dcat";

    }//end catalogEndpointUrl()

    /**
     * Build the canonical public dataset IRI for a publication (PUB-002 URL).
     *
     * @param string $catalogSlug The catalog slug.
     * @param string $uuid        The publication UUID.
     *
     * @return string The stable dataset IRI.
     *
     * @spec openspec/changes/dcat-ap-harvest/specs/dcat-ap-harvest/spec.md#requirement-attachments-rendered-as-distributions-with-stable-iris-dcat-006
     */
    public function datasetIri(string $catalogSlug, string $uuid): string
    {
        $base = rtrim($this->urlGenerator->getBaseUrl(), '/');
        return "$base/apps/opencatalogi/api/$catalogSlug/$uuid";

    }//end datasetIri()

    /**
     * Build the per-catalog DCAT-AP-NL document for one page of datasets.
     *
     * Datasets are selected via OR object search scoped to the catalog's
     * registers/schemas with `_rbac: true` — byte-for-byte the PUB-001/WOO-001
     * visibility rule (only publicly visible objects appear). Opted-out schemas
     * (`"x-dcat": false`) are skipped.
     *
     * @param array<string, mixed> $catalog     The catalog object.
     * @param string               $catalogSlug The catalog slug.
     * @param integer              $page        The 1-based page number.
     *
     * @return array<string, mixed> The JSON-LD document plus `_meta` (lastModified, etag, count).
     *
     * @SuppressWarnings(PHPMD.CyclomaticComplexity)
     * @SuppressWarnings(PHPMD.NPathComplexity)
     * @SuppressWarnings(PHPMD.ExcessiveMethodLength)
     *
     * @spec openspec/changes/dcat-ap-harvest/specs/dcat-ap-harvest/spec.md#requirement-per-catalog-dcat-ap-nl-document-endpoint-dcat-001
     * @spec openspec/changes/dcat-ap-harvest/specs/dcat-ap-harvest/spec.md#requirement-only-publicly-visible-objects-appear-in-the-feed-dcat-003
     * @spec openspec/changes/dcat-ap-harvest/specs/dcat-ap-harvest/spec.md#requirement-harvester-grade-pagination-and-caching-dcat-008
     */
    public function buildCatalogDocument(array $catalog, string $catalogSlug, int $page=1): array
    {
        $page = max(1, $page);

        $registers = $this->idList(($catalog['registers'] ?? []));
        $schemas   = $this->idList(($catalog['schemas'] ?? []));
        $defaults  = $this->resolveDefaults($catalog);

        $schemaMappings = $this->resolveSchemaMappings($schemas);

        $searchQuery = ['_limit' => self::MAX_PER_PAGE, '_page' => $page];
        $searchQuery['@self']['register'] = $this->scalarOrList($registers);
        $searchQuery['@self']['schema']   = $this->scalarOrList($schemas);
        $searchQuery['_order']['updated'] = 'desc';

        $objectService = $this->getObjectService();
        // RBAC governs visibility (PUB-001 / WOO-001): anonymous callers receive only
        // publicly visible (published, not depublished) objects. No DCAT-local filtering.
        $result = $objectService->searchObjectsPaginated(
            query: $searchQuery,
            _rbac: true,
            _multitenancy: false,
            deleted: false
        );

        $publications = ($result['results'] ?? []);
        $hasNext      = (($result['next'] ?? null) !== null);

        $fileService = $this->getFileService();

        $catalogIri  = $this->catalogEndpointUrl($catalogSlug);
        $datasets    = [];
        $datasetRefs = [];
        $maxModified = 0;
        foreach ($publications as $publication) {
            $publication = $this->toArray($publication);

            $schemaId = (int) ($publication['@self']['schema'] ?? 0);
            // Skip schemas that opted out of DCAT entirely.
            if (array_key_exists($schemaId, $schemaMappings) === true && $schemaMappings[$schemaId] === null) {
                continue;
            }

            $mapping = ($schemaMappings[$schemaId] ?? DcatMappingService::DEFAULT_MAPPING);

            $uuid       = (string) ($publication['@self']['uuid'] ?? $publication['id'] ?? '');
            $datasetIri = $this->datasetIri($catalogSlug, $uuid);

            // Fetch published attachments (searchObjectsPaginated omits files), mirroring SitemapService.
            $files = [];
            try {
                $files = ($fileService->formatFiles($fileService->getFiles(object: ($publication['id'] ?? $uuid)))['results'] ?? []);
            } catch (\Throwable $e) {
                $this->logger->debug('[DcatService] Could not load files for publication', ['uuid' => $uuid, 'error' => $e->getMessage()]);
            }

            $datasets[]    = $this->mappingService->mapDataset(
                publication: $publication,
                mapping: $mapping,
                files: $files,
                datasetIri: $datasetIri,
                defaults: $defaults
            );
            $datasetRefs[] = ['@id' => $datasetIri];

            $modified = strtotime((string) ($publication['@self']['updated'] ?? $publication['publicatiedatum'] ?? ''));
            if ($modified !== false && $modified > $maxModified) {
                $maxModified = $modified;
            }
        }//end foreach

        $catalogNode = [
            '@id'          => $catalogIri,
            '@type'        => 'dcat:Catalog',
            'dct:title'    => ($catalog['title'] ?? $catalogSlug),
            'dcat:dataset' => $datasetRefs,
        ];

        if (($catalog['description'] ?? '') !== '') {
            $catalogNode['dct:description'] = $catalog['description'];
        }

        $publisher = $this->mappingService->buildPublisher(organisation: ($defaults['organisation'] ?? null), defaults: $defaults);
        if ($publisher !== null) {
            $catalogNode['dct:publisher'] = $publisher;
        }

        $graph = array_merge([$catalogNode], $datasets);

        // Hydra paging.
        if ($page > 1 || $hasNext === true) {
            $paged = [
                '@id'   => "$catalogIri?page=$page",
                '@type' => 'hydra:PagedCollection',
            ];
            if ($hasNext === true) {
                $paged['hydra:next'] = ['@id' => "$catalogIri?page=".($page + 1)];
            }

            if ($page > 1) {
                $paged['hydra:previous'] = ['@id' => "$catalogIri?page=".($page - 1)];
            }

            $graph[] = $paged;
        }

        $document = [
            '@context' => $this->mappingService->context(),
            '@graph'   => $graph,
        ];

        $lastModified = gmdate('D, d M Y H:i:s').' GMT';
        if ($maxModified > 0) {
            $lastModified = gmdate('D, d M Y H:i:s', $maxModified).' GMT';
        }

        $etag = '"'.md5(($catalog['id'] ?? $catalogSlug).':'.count($datasets).':'.$maxModified.':'.$page).'"';

        $document['_meta'] = [
            'lastModified' => $lastModified,
            'etag'         => $etag,
            'count'        => count($datasets),
            'hasNext'      => $hasNext,
        ];

        return $document;

    }//end buildCatalogDocument()

    /**
     * Build the instance-level DCAT document listing every DCAT-enabled catalog.
     *
     * @return array<string, mixed> The JSON-LD document plus `_meta`.
     *
     * @spec openspec/changes/dcat-ap-harvest/specs/dcat-ap-harvest/spec.md#requirement-instance-level-dcat-catalog-document-dcat-002
     */
    public function buildInstanceDocument(): array
    {
        $catalogs = $this->getDcatEnabledCatalogs();

        $base        = rtrim($this->urlGenerator->getBaseUrl(), '/');
        $instanceIri = "$base/apps/opencatalogi/api/dcat";

        $graph = [];
        foreach ($catalogs as $catalog) {
            $slug = (string) ($catalog['slug'] ?? '');
            if ($slug === '') {
                continue;
            }

            $graph[] = [
                '@id'           => $this->catalogEndpointUrl($slug),
                '@type'         => 'dcat:Catalog',
                'dct:title'     => ($catalog['title'] ?? $slug),
                'foaf:homepage' => ['@id' => $this->catalogEndpointUrl($slug)],
            ];
        }

        // Instance-level publisher from the configured owning Organisation.
        $defaults  = [
            'publisherName' => $this->appConfig->getValueString('opencatalogi', 'dcat_publisher_name', ''),
            'publisherUri'  => $this->appConfig->getValueString('opencatalogi', 'dcat_publisher_uri', ''),
        ];
        $publisher = $this->mappingService->buildPublisher(null, $defaults);

        $rootNode = [
            '@id'          => $instanceIri,
            '@type'        => 'dcat:Catalog',
            'dct:title'    => $this->appConfig->getValueString('opencatalogi', 'dcat_instance_title', 'OpenCatalogi'),
            'dcat:catalog' => array_map(static fn($node) => ['@id' => $node['@id']], $graph),
        ];
        if ($publisher !== null) {
            $rootNode['dct:publisher'] = $publisher;
        }

        $document = [
            '@context' => $this->mappingService->context(),
            '@graph'   => array_merge([$rootNode], $graph),
        ];

        $etag = '"'.md5('instance:'.count($graph)).'"';
        $document['_meta'] = [
            'lastModified' => gmdate('D, d M Y H:i:s').' GMT',
            'etag'         => $etag,
            'count'        => count($graph),
            'hasNext'      => false,
        ];

        return $document;

    }//end buildInstanceDocument()

    /**
     * Validate a catalog's feed against the DCAT-AP-NL mandatory-property checklist.
     *
     * Advisory only — never gates serving. Reports the dataset IRIs that lack any
     * resolvable mandatory property (title, publisher).
     *
     * @param array<string, mixed> $catalog     The catalog object.
     * @param string               $catalogSlug The catalog slug.
     *
     * @return array<int, array<string, mixed>> One entry per violating dataset.
     *
     * @spec openspec/changes/dcat-ap-harvest/specs/dcat-ap-harvest/spec.md#requirement-admin-configuration-and-feed-validation-dcat-010
     */
    public function validateCatalog(array $catalog, string $catalogSlug): array
    {
        $document   = $this->buildCatalogDocument(catalog: $catalog, catalogSlug: $catalogSlug, page: 1);
        $violations = [];
        foreach ($this->serializer->graphNodes($document) as $node) {
            if (($node['@type'] ?? '') !== 'dcat:Dataset') {
                continue;
            }

            $missing = $this->mandatoryViolations($node);
            if (empty($missing) === false) {
                $violations[] = [
                    'iri'     => ($node['@id'] ?? ''),
                    'missing' => $missing,
                ];
            }
        }

        return $violations;

    }//end validateCatalog()

    /**
     * Determine the DCAT-AP-NL mandatory properties missing from a dataset node.
     *
     * @param array<string, mixed> $node The dataset node.
     *
     * @return array<int, string> The missing mandatory property CURIEs.
     *
     * @spec openspec/changes/dcat-ap-harvest/specs/dcat-ap-harvest/spec.md#requirement-admin-configuration-and-feed-validation-dcat-010
     */
    public function mandatoryViolations(array $node): array
    {
        $required = ['dct:title', 'dct:publisher', 'dcat:landingPage'];
        $missing  = [];
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
     * @spec openspec/changes/dcat-ap-harvest/specs/dcat-ap-harvest/spec.md#requirement-instance-level-dcat-catalog-document-dcat-002
     */
    public function getDcatEnabledCatalogs(): array
    {
        $register = $this->appConfig->getValueString('opencatalogi', 'catalog_register', '');
        $schema   = $this->appConfig->getValueString('opencatalogi', 'catalog_schema', '');
        if ($register === '' || $schema === '') {
            return [];
        }

        $query = [
            '@self'   => ['register' => $register, 'schema' => $schema],
            'hasDcat' => true,
            '_limit'  => self::MAX_PER_PAGE,
        ];

        $result = $this->getObjectService()->searchObjectsPaginated(
            query: $query,
            _rbac: true,
            _multitenancy: false,
            deleted: false
        );

        $catalogs = [];
        foreach (($result['results'] ?? []) as $catalog) {
            $catalogs[] = $this->toArray($catalog);
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
     * @spec openspec/changes/dcat-ap-harvest/specs/dcat-ap-harvest/spec.md#requirement-schema-driven-dcat-mapping-via-x-dcat-annotation-dcat-004
     */
    private function resolveSchemaMappings(array $schemaIds): array
    {
        $mappings     = [];
        $schemaMapper = $this->getSchemaMapper();
        foreach ($schemaIds as $schemaId) {
            try {
                $schema     = $schemaMapper->find((int) $schemaId);
                $schemaData = $schema->jsonSerialize();
                $mappings[(int) $schemaId] = $this->mappingService->resolveMapping($schemaData);
            } catch (\Throwable $e) {
                $this->logger->debug('[DcatService] Could not resolve schema mapping', ['schema' => $schemaId, 'error' => $e->getMessage()]);
                $mappings[(int) $schemaId] = DcatMappingService::DEFAULT_MAPPING;
            }
        }

        return $mappings;

    }//end resolveSchemaMappings()

    /**
     * Normalise a catalog register/schema list (array or JSON string) to integer IDs.
     *
     * @param array<mixed>|string|null $raw The catalog field value.
     *
     * @return array<int, int> The integer ID list.
     */
    private function idList(array | string | null $raw): array
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

    }//end idList()

    /**
     * Normalise an OpenRegister entity or array result to an array.
     *
     * @param mixed $item An ObjectEntity (jsonSerialize) or a plain array.
     *
     * @return array<string, mixed> The array representation.
     */
    private function toArray(mixed $item): array
    {
        if (is_array($item) === true) {
            return $item;
        }

        if (is_object($item) === true && method_exists($item, 'jsonSerialize') === true) {
            return $item->jsonSerialize();
        }

        return [];

    }//end toArray()

    /**
     * Return a scalar when the ID list has exactly one entry, else the list.
     *
     * Mirrors CatalogiService::index — a scalar register/schema avoids unnecessary
     * magic-mapper overhead in OpenRegister object search.
     *
     * @param array<int, int> $ids The integer ID list.
     *
     * @return int|array<int, int> A scalar ID or the list.
     */
    private function scalarOrList(array $ids): int | array
    {
        if (count($ids) === 1) {
            return $ids[0];
        }

        return $ids;

    }//end scalarOrList()
}//end class
