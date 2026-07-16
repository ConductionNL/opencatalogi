<?php
/**
 * OpenCatalogi OOAPI 5.0 catalog-publication service.
 *
 * Read-only rendering layer (hydra ADR-022): no new storage beyond the
 * OpenCatalogi-owned course/program/offering schemas themselves, no new
 * visibility rule, no bespoke query layer. `organization` is rendered live
 * from OpenCatalogi's existing Organisation object (OOAPI-002); `course`/
 * `program`/`offering` are materialized OR objects — written by an
 * OpenConnector `ooapi-catalog` Synchronization target (a sibling change, not
 * built here) — queried via the same OR object-search path every other
 * OpenCatalogi public surface uses (OOAPI-003). The architectural twin of
 * DcatService, minus content negotiation/RDF (OOAPI is JSON-only) and HVD.
 *
 * @category Service
 * @package  OCA\OpenCatalogi\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenCatalogi.nl
 *
 * @spec openspec/changes/ooapi-catalog-publication/tasks.md#task-3-public-endpoints-serializer
 */

namespace OCA\OpenCatalogi\Service;

use OCP\App\IAppManager;
use OCP\IAppConfig;
use OCP\IURLGenerator;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Builds OOAPI 5.0 resources from OpenCatalogi's Organisation object and its
 * OpenConnector-materialized course/program/offering objects.
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 * @SuppressWarnings(PHPMD.ExcessiveClassComplexity)
 *
 * @spec openspec/changes/ooapi-catalog-publication/tasks.md#task-3-public-endpoints-serializer
 */
class OoapiService
{

    /**
     * OOAPI 5.0 list-endpoint page-size ceiling (OOAPI-007).
     *
     * @var integer
     */
    public const MAX_PAGE_SIZE = 250;

    /**
     * Default page size when the caller does not specify one.
     *
     * @var integer
     */
    public const DEFAULT_PAGE_SIZE = 50;

    /**
     * OoapiService constructor.
     *
     * @param ContainerInterface  $container      Server container for OR service resolution.
     * @param IAppManager         $appManager     App manager for OpenRegister availability checks.
     * @param OoapiMappingService $mappingService Pure object → OOAPI 5.0 resource mapping.
     * @param IURLGenerator       $urlGenerator   Nextcloud URL generator (absolute endpoint URLs).
     * @param IAppConfig          $appConfig      App config (the OOAPI-008 consumer allowlist).
     * @param LoggerInterface     $logger         PSR-3 logger.
     */
    public function __construct(
        private readonly ContainerInterface $container,
        private readonly IAppManager $appManager,
        private readonly OoapiMappingService $mappingService,
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
     * Determine whether OOAPI 5.0 publication is enabled for a catalog.
     *
     * @param array<string, mixed> $catalog The catalog object (jsonSerialize shape).
     *
     * @return boolean True when the catalog's `hasOoapi` flag is set.
     *
     * @spec openspec/specs/ooapi-catalog-publication/spec.md#requirement-per-catalog-ooapi-5-0-resource-endpoints-ooapi-001
     */
    public function isOoapiEnabled(array $catalog): bool
    {
        return filter_var(($catalog['hasOoapi'] ?? false), FILTER_VALIDATE_BOOLEAN);

    }//end isOoapiEnabled()

    /**
     * Build the absolute per-catalog OOAPI 5.0 base endpoint URL.
     *
     * @param string $catalogSlug The catalog slug.
     *
     * @return string The absolute OOAPI v5 base URL.
     *
     * @spec openspec/specs/ooapi-catalog-publication/spec.md#requirement-federation-directory-advertises-the-ooapi-endpoint-ooapi-009
     */
    public function endpointUrl(string $catalogSlug): string
    {
        $base = rtrim($this->urlGenerator->getBaseUrl(), '/');
        return "$base/apps/opencatalogi/api/catalogs/$catalogSlug/ooapi/v5";

    }//end endpointUrl()

    /**
     * Determine whether an authenticated Nextcloud user is on the OOAPI-008
     * consumer allowlist.
     *
     * MVP consumer-credential gate (design.md D3): the caller MUST already be
     * an authenticated Nextcloud user (checked by the controller before this
     * method is reached — OOAPI-008's "not anonymous-public" core requirement).
     * When the `ooapi_consumers` config key is empty (the default) every
     * authenticated user is allowed. When it carries a comma-separated list of
     * Nextcloud usernames, only those accounts are allowed — this is the
     * "issuance/revocation" lever an admin uses (OOAPI-010): add/remove a
     * username to grant/revoke access, the underlying credential being that
     * account's own Nextcloud app-password (no bespoke token store, D3).
     *
     * Per-catalog credential scoping (design.md open question 3) is explicitly
     * NOT implemented — the allowlist is instance-wide, not per catalog.
     *
     * @param string $username The authenticated Nextcloud username.
     *
     * @return boolean True when the user is allowed to read OOAPI feeds.
     *
     * @spec openspec/specs/ooapi-catalog-publication/spec.md#requirement-consumer-credential-authenticated-access-ooapi-008
     */
    public function isConsumerAllowed(string $username): bool
    {
        $configured = trim($this->appConfig->getValueString('opencatalogi', 'ooapi_consumers', ''));
        if ($configured === '') {
            return true;
        }

        $allowlist = array_map('trim', explode(',', $configured));
        return in_array($username, $allowlist, true);

    }//end isConsumerAllowed()

    /**
     * Resolve the `x-ooapi` mapping declared on a schema by its numeric id.
     *
     * @param int|string $schemaId The OpenRegister schema id.
     *
     * @return array{mapping: array<string, string>|null, resource: string|null} The resolved mapping (null = identity) and declared resource type.
     */
    private function resolveSchemaMapping(int | string $schemaId): array
    {
        try {
            $schema     = $this->getSchemaMapper()->find((int) $schemaId);
            $schemaData = $schema->jsonSerialize();
            return [
                'mapping'  => $this->mappingService->resolveMapping($schemaData),
                'resource' => $this->mappingService->resolveResourceType($schemaData),
            ];
        } catch (\Throwable $e) {
            $this->logger->debug('[OoapiService] Could not resolve x-ooapi mapping', ['schema' => $schemaId, 'error' => $e->getMessage()]);
            return ['mapping' => null, 'resource' => null];
        }

    }//end resolveSchemaMapping()

    /**
     * Render the catalog's owning Organisation as an OOAPI 5.0 `organization`
     * resource, live, with no materialization step (OOAPI-002).
     *
     * @param array<string, mixed> $catalog             The catalog object.
     * @param string                $organizationRegister The resolved organisation register id.
     * @param string                $organizationSchema   The resolved organisation schema id.
     *
     * @return array<string, mixed>|null The rendered `organization` resource, or null when the catalog has none.
     *
     * @spec openspec/specs/ooapi-catalog-publication/spec.md#requirement-organization-resource-renders-the-existing-organisation-object-live-ooapi-002
     */
    public function renderOrganization(array $catalog, string $organizationRegister, string $organizationSchema): ?array
    {
        $organisationId = $catalog['organization'] ?? null;
        if (is_string($organisationId) === false || $organisationId === '') {
            return null;
        }

        return $this->getOrganization(organisationId: $organisationId, organizationRegister: $organizationRegister, organizationSchema: $organizationSchema);

    }//end renderOrganization()

    /**
     * Fetch and render a single `organization` resource by id, constrained to
     * the catalog's own owning Organisation (D1/D4 — no bespoke query layer,
     * reuse the existing single `catalog.organization` reference).
     *
     * @param array<string, mixed> $catalog              The catalog object.
     * @param string                $id                   The requested organization id.
     * @param string                $organizationRegister The resolved organisation register id.
     * @param string                $organizationSchema   The resolved organisation schema id.
     *
     * @return array<string, mixed>|null The rendered `organization` resource, or null when unresolvable.
     *
     * @spec openspec/specs/ooapi-catalog-publication/spec.md#requirement-organization-resource-renders-the-existing-organisation-object-live-ooapi-002
     */
    public function organizationById(array $catalog, string $id, string $organizationRegister, string $organizationSchema): ?array
    {
        $organisationId = $catalog['organization'] ?? null;
        if (is_string($organisationId) === false || $organisationId === '' || $organisationId !== $id) {
            return null;
        }

        return $this->getOrganization(organisationId: $organisationId, organizationRegister: $organizationRegister, organizationSchema: $organizationSchema);

    }//end organizationById()

    /**
     * Fetch a single Organisation object and render it as an OOAPI `organization` resource.
     *
     * @param string $organisationId       The Organisation object id/uuid.
     * @param string $organizationRegister The resolved organisation register id.
     * @param string $organizationSchema   The resolved organisation schema id.
     *
     * @return array<string, mixed>|null The rendered resource, or null when not found.
     */
    private function getOrganization(string $organisationId, string $organizationRegister, string $organizationSchema): ?array
    {
        try {
            $object = $this->getObjectService()->find(id: $organisationId, _extend: []);
        } catch (\Throwable $e) {
            $this->logger->debug('[OoapiService] Organisation not found', ['id' => $organisationId, 'error' => $e->getMessage()]);
            return null;
        }

        if ($object === null) {
            return null;
        }

        $organisation = $this->toArray($object);

        $mapping = $this->resolveSchemaMapping($organizationSchema);
        if ($mapping['resource'] === null) {
            // Unannotated schema — never offered as an OOAPI resource (OOAPI-004).
            return null;
        }

        return $this->mappingService->buildResource(object: $organisation, mapping: $mapping['mapping'], idField: 'organizationId');

    }//end getOrganization()

    /**
     * Search a paginated OOAPI resource collection scoped to the catalog
     * (`catalog` field, D6 addendum — the shared-register equivalent of
     * DCAT's `catalog.registers`/`catalog.schemas` scoping) and, optionally,
     * an extra equality filter (e.g. `courseId` for the offerings-under-course
     * view, OOAPI-006).
     *
     * @param string                $catalogId  The catalog's own id/uuid.
     * @param string                $register   The resolved register id.
     * @param string                $schema     The resolved schema id.
     * @param string                $idField    The OOAPI resource id field name.
     * @param int                    $page       The 1-based page number.
     * @param int                    $pageSize   The requested page size (capped at MAX_PAGE_SIZE).
     * @param array<string, mixed>  $extraQuery Additional equality filters (e.g. `['courseId' => '...']`).
     *
     * @return array{items: array<int, array<string, mixed>>, pageNumber: int, pageSize: int, hasNext: bool} The paginated OOAPI collection.
     *
     * @spec openspec/specs/ooapi-catalog-publication/spec.md#requirement-course-program-offering-resources-are-materialized-not-rendered-live-from-scholiq-ooapi-003
     * @spec openspec/specs/ooapi-catalog-publication/spec.md#requirement-ooapi-5-0-pagination-ooapi-007
     */
    private function searchCollection(
        string $catalogId,
        string $register,
        string $schema,
        string $idField,
        int $page,
        int $pageSize,
        array $extraQuery=[]
    ): array {
        $page     = max(1, $page);
        $pageSize = max(1, min($pageSize, self::MAX_PAGE_SIZE));

        $mapping = $this->resolveSchemaMapping($schema);
        if ($mapping['resource'] === null) {
            // Unannotated schema — never offered as an OOAPI resource (OOAPI-004).
            return ['items' => [], 'pageNumber' => $page, 'pageSize' => $pageSize, 'hasNext' => false];
        }

        $query = array_merge(
            $extraQuery,
            [
                '@self'   => ['register' => $register, 'schema' => $schema],
                'catalog' => $catalogId,
                '_limit'  => $pageSize,
                '_page'   => $page,
                '_order'  => ['@self.created' => 'asc'],
            ]
        );

        $result = $this->getObjectService()->searchObjectsPaginated(
            query: $query,
            _rbac: true,
            _multitenancy: false,
            deleted: false
        );

        $items = [];
        foreach (($result['results'] ?? []) as $object) {
            $items[] = $this->mappingService->buildResource(object: $this->toArray($object), mapping: $mapping['mapping'], idField: $idField);
        }

        return [
            'items'      => $items,
            'pageNumber' => $page,
            'pageSize'   => $pageSize,
            'hasNext'    => (($result['next'] ?? null) !== null),
        ];

    }//end searchCollection()

    /**
     * List the materialized `course` resources scoped to a catalog (OOAPI-001).
     *
     * @param array<string, mixed> $catalog  The catalog object.
     * @param string                $register The resolved `ooapi_courses` register id.
     * @param string                $schema   The resolved `ooapi_courses` schema id.
     * @param int                    $page     The 1-based page number.
     * @param int                    $pageSize The requested page size.
     *
     * @return array{items: array<int, array<string, mixed>>, pageNumber: int, pageSize: int, hasNext: bool} The paginated course collection.
     */
    public function listCourses(array $catalog, string $register, string $schema, int $page, int $pageSize): array
    {
        return $this->searchCollection(
            catalogId: (string) ($catalog['id'] ?? ''),
            register: $register,
            schema: $schema,
            idField: 'courseId',
            page: $page,
            pageSize: $pageSize
        );

    }//end listCourses()

    /**
     * List the materialized `program` resources scoped to a catalog (OOAPI-001).
     *
     * @param array<string, mixed> $catalog  The catalog object.
     * @param string                $register The resolved `ooapi_programs` register id.
     * @param string                $schema   The resolved `ooapi_programs` schema id.
     * @param int                    $page     The 1-based page number.
     * @param int                    $pageSize The requested page size.
     *
     * @return array{items: array<int, array<string, mixed>>, pageNumber: int, pageSize: int, hasNext: bool} The paginated program collection.
     */
    public function listPrograms(array $catalog, string $register, string $schema, int $page, int $pageSize): array
    {
        return $this->searchCollection(
            catalogId: (string) ($catalog['id'] ?? ''),
            register: $register,
            schema: $schema,
            idField: 'programId',
            page: $page,
            pageSize: $pageSize
        );

    }//end listPrograms()

    /**
     * List the materialized `offering` resources scoped to a catalog, optionally
     * filtered to a single parent course (OOAPI-006).
     *
     * @param array<string, mixed> $catalog  The catalog object.
     * @param string                $register The resolved `ooapi_offerings` register id.
     * @param string                $schema   The resolved `ooapi_offerings` schema id.
     * @param string|null            $courseId Filter to this parent course's offerings, or null for all.
     * @param int                    $page     The 1-based page number.
     * @param int                    $pageSize The requested page size.
     *
     * @return array{items: array<int, array<string, mixed>>, pageNumber: int, pageSize: int, hasNext: bool} The paginated offering collection.
     */
    public function listOfferings(array $catalog, string $register, string $schema, ?string $courseId, int $page, int $pageSize): array
    {
        $extraQuery = [];
        if ($courseId !== null && $courseId !== '') {
            $extraQuery['courseId'] = $courseId;
        }

        return $this->searchCollection(
            catalogId: (string) ($catalog['id'] ?? ''),
            register: $register,
            schema: $schema,
            idField: 'offeringId',
            page: $page,
            pageSize: $pageSize,
            extraQuery: $extraQuery
        );

    }//end listOfferings()

    /**
     * Fetch and render a single materialized resource (`course`/`program`/`offering`)
     * by id, scoped to the catalog. Returns null when the object does not exist, is
     * not scoped to this catalog, or its schema carries no `x-ooapi` annotation.
     *
     * @param array<string, mixed> $catalog  The catalog object.
     * @param string                $register The resolved register id.
     * @param string                $schema   The resolved schema id.
     * @param string                $id       The requested object id.
     * @param string                $idField  The OOAPI resource id field name.
     *
     * @return array<string, mixed>|null The rendered resource, or null.
     *
     * @spec openspec/specs/ooapi-catalog-publication/spec.md#requirement-course-program-offering-resources-are-materialized-not-rendered-live-from-scholiq-ooapi-003
     */
    public function getResource(array $catalog, string $register, string $schema, string $id, string $idField): ?array
    {
        try {
            $object = $this->getObjectService()->find(id: $id, _extend: []);
        } catch (\Throwable $e) {
            $this->logger->debug('[OoapiService] Resource not found', ['id' => $id, 'error' => $e->getMessage()]);
            return null;
        }

        if ($object === null) {
            return null;
        }

        $data = $this->toArray($object);

        // Scoped to this catalog (D6 addendum) — an archived/foreign-catalog object 404s.
        if (($data['catalog'] ?? null) !== ($catalog['id'] ?? null)) {
            return null;
        }

        $objectSchema = (string) ($data['@self']['schema'] ?? '');
        if ($objectSchema !== (string) $schema) {
            return null;
        }

        $mapping = $this->resolveSchemaMapping($schema);
        if ($mapping['resource'] === null) {
            return null;
        }

        return $this->mappingService->buildResource(object: $data, mapping: $mapping['mapping'], idField: $idField);

    }//end getResource()

    /**
     * Validate a catalog's OOAPI feed against a minimal mandatory-property
     * checklist. Advisory only — never gates serving (mirrors DCAT-010's
     * precedent, OOAPI-010).
     *
     * @param array<string, mixed> $catalog        The catalog object.
     * @param string                $courseRegister The resolved `ooapi_courses` register id.
     * @param string                $courseSchema   The resolved `ooapi_courses` schema id.
     *
     * @return array<int, array<string, mixed>> One entry per violating course resource.
     *
     * @spec openspec/specs/ooapi-catalog-publication/spec.md#requirement-admin-configuration-for-ooapi-publication-ooapi-010
     */
    public function validateCatalog(array $catalog, string $courseRegister, string $courseSchema): array
    {
        $violations = [];
        $page       = 1;
        do {
            $result = $this->listCourses($catalog, $courseRegister, $courseSchema, $page, self::MAX_PAGE_SIZE);
            foreach ($result['items'] as $course) {
                $missing = [];
                foreach (['courseId', 'code', 'name'] as $required) {
                    if (isset($course[$required]) === false || $course[$required] === '') {
                        $missing[] = $required;
                    }
                }

                if (empty($missing) === false) {
                    $violations[] = ['id' => ($course['courseId'] ?? ''), 'missing' => $missing];
                }
            }

            $page++;
        } while ($result['hasNext'] === true);

        return $violations;

    }//end validateCatalog()

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
}//end class
