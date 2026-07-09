<?php
/**
 * Service for publication query building and response shaping.
 *
 * Encapsulates the query-building, object-location, and response-shaping logic
 * extracted from PublicationsController to keep the controller thin.
 *
 * @category Service
 * @package  OCA\OpenCatalogi\Service
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
 */

namespace OCA\OpenCatalogi\Service;

use OCP\AppFramework\Db\DoesNotExistException;
use OCP\IAppConfig;
use OCP\IUserSession;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Query-building and response-shaping helpers for publications.
 *
 * All methods in this service are pure-logic helpers with no side-effects on
 * routing, authentication, or HTTP response codes. They exist solely to reduce
 * the size of PublicationsController.
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 * @SuppressWarnings(PHPMD.ExcessiveClassComplexity)
 */
class PublicationQueryService
{

    /**
     * Order fields that exist in every magic-mapper table.
     *
     * Used when filtering _order for multi-register searches.
     *
     * @var array<string>
     */
    private const UNIVERSAL_ORDER_FIELDS = [
        '_uuid',
        '_created',
        '_updated',
        '_name',
        '_description',
        '_summary',
        '_relevance',
    ];

    /**
     * Constructor.
     *
     * @param ContainerInterface   $container   DI container
     * @param IUserSession|null    $userSession User session for anonymity checks (auto-wired at runtime)
     * @param IAppConfig|null      $config      App config, resolves the publication/document register+schema ids
     * @param LoggerInterface|null $logger      Logger — surfaces the fail-closed empty-envelope branch so silent
     *                                          configuration drift is observable in production (SCH-PFTS-005).
     */
    public function __construct(
        private readonly ContainerInterface $container,
        private readonly ?IUserSession $userSession=null,
        private readonly ?IAppConfig $config=null,
        private readonly ?LoggerInterface $logger=null,
    ) {

    }//end __construct()

    /**
     * Determine whether the current request is made by an anonymous (logged-out) caller.
     *
     * Used by the published-predicate guard on the public per-catalog relation endpoints
     * (PublicationsController::uses/used) so an anonymous caller cannot enumerate the
     * relation graph of an unpublished object by guessing its UUID.
     *
     * @return boolean True when there is no authenticated user on the session.
     *
     * @spec exclude Visibility helper for the public-endpoint published-predicate guard.
     */
    public function isAnonymous(): bool
    {
        if ($this->userSession === null) {
            // Fail closed: when the session is unavailable, treat the caller as anonymous
            // so the published-predicate guard applies the stricter visibility rule.
            return true;
        }

        return $this->userSession->getUser() === null;

    }//end isAnonymous()

    /**
     * Determine whether an object is publicly visible (published and not depublished).
     *
     * Mirrors the live OpenRegister RBAC visibility model (APB-006), the same rule
     * the public publications API and the frontend `publicationStatus` helpers use:
     * an object is public when its own `publicatiedatum` field is set and is at or
     * before "now", and either carries no `depublicatiedatum` or one still in the
     * future. The removed object-level `@self.published` predicate is not consulted.
     *
     * @param array $objectData The serialized object data (own fields + `@self` envelope).
     *
     * @return boolean True when the object is currently published.
     *
     * @spec openspec/specs/auto-publishing/spec.md#APB-006
     */
    public function isObjectPublic(array $objectData): bool
    {
        $publicatiedatum   = ($objectData['publicatiedatum'] ?? null);
        $depublicatiedatum = ($objectData['depublicatiedatum'] ?? null);

        if ($publicatiedatum === null || $publicatiedatum === '') {
            return false;
        }

        $now           = time();
        $publishedTime = strtotime((string) $publicatiedatum);
        if ($publishedTime === false || $publishedTime > $now) {
            return false;
        }

        if ($depublicatiedatum === null || $depublicatiedatum === '') {
            return true;
        }

        $depublishedTime = strtotime((string) $depublicatiedatum);
        return ($depublishedTime === false || $depublishedTime > $now);

    }//end isObjectPublic()

    /**
     * Assemble the public full-text search result envelope (SCH-PFTS-002/006/007).
     *
     * Delegates entirely to OR's zoeken-filteren (`ObjectService::searchObjectsPaginated`)
     * across the `publication` and `document` schemas of the publication register, merges
     * the candidate rows into a single flat array discriminated by `@self.schema`
     * (SCH-PFTS-002), embeds the linked publication summary on every document row
     * (SCH-PFTS-003), and applies the anonymous visibility filter AFTER scoring/merge
     * (SCH-PFTS-004) so ranking is computed on the full candidate set before visibility
     * is enforced. Authenticated callers are not filtered — this endpoint absorbs the
     * previous admin-only search and authenticated callers keep seeing every match
     * (SCH-OR-003).
     *
     * The scope (register + schemas) is fixed by this method and never taken from the
     * caller-supplied query parameters, so a caller cannot widen the search beyond the
     * publication/document schemas by passing its own `_register`/`_schema(s)` (mirrors
     * the constrained-scope discipline in {@see findObjectLocation()}).
     *
     * Dual-path (design.md "Dual-path design"): this ships Path B — matches are
     * driven by OR's `zoeken-filteren` against schema properties and `@self` metadata
     * only, no document-content extraction. Document-content indexing (Path A) is
     * tracked separately as WOO-517 (assigned Ruben, in Refinement) and does not gate
     * this change; when it lands, content indexing is wired via OR's
     * TextExtractionService + FileHandler + Solr-pipeline (SCH-PFTS-006) — OpenCatalogi
     * MUST NOT add a parallel extraction pipeline for this.
     *
     * @param array  $queryParams   Raw request query parameters from IRequest::getParams().
     * @param object $objectService OpenRegister ObjectService instance (already resolved from container).
     *
     * @return array{results: array<int, array>, total: int} Flat mixed-type result envelope.
     *
     * @spec openspec/changes/add-public-fulltext-search/tasks.md#task-5
     *
     * @SuppressWarnings(PHPMD.CyclomaticComplexity)
     * @SuppressWarnings(PHPMD.NPathComplexity)
     */
    public function assemblePublicSearchResults(array $queryParams, object $objectService): array
    {
        $registerId          = $this->resolveConfiguredId('publication_register');
        $publicationSchemaId = $this->resolveConfiguredId('publication_schema');
        $documentSchemaId    = $this->resolveConfiguredId('document_schema');

        if ($registerId === null || $publicationSchemaId === null || $documentSchemaId === null) {
            // Configuration not (yet) loaded — fail closed to an empty envelope rather
            // than falling back to an unscoped, platform-wide search. Emit a warning so
            // the failure is observable in production (silent empty is indistinguishable
            // from "no matches" in the response envelope; operators need a signal that
            // the deploy is misconfigured).
            $this->logger?->warning(
                'PublicationQueryService::assemblePublicSearchResults returning empty envelope — register/schema config unresolved',
                [
                    'publication_register' => $registerId === null ? 'MISSING' : 'set',
                    'publication_schema'   => $publicationSchemaId === null ? 'MISSING' : 'set',
                    'document_schema'      => $documentSchemaId === null ? 'MISSING' : 'set',
                ]
            );
            return [
                'results' => [],
                'total'   => 0,
            ];
        }

        $schemaSlugById = [
            $publicationSchemaId => 'publication',
            $documentSchemaId    => 'document',
        ];

        $searchQuery = $objectService->buildSearchQuery($queryParams);
        // The scope is fixed above — strip any caller-supplied scope keys so a request
        // parameter can never widen the search outside the publication/document schemas.
        unset($searchQuery['_schema'], $searchQuery['_registers'], $searchQuery['catalogSlug'], $searchQuery['fq']);
        $searchQuery['_register']       = $registerId;
        $searchQuery['_schemas']        = [$publicationSchemaId, $documentSchemaId];
        $searchQuery['_includeDeleted'] = false;

        // _rbac: false — visibility is enforced below via isObjectPublic() AFTER
        // scoring/merge (SCH-PFTS-004); folding it into the OR query would bias ranking
        // against rows the corpus considers relevant.
        $candidateResult = $objectService->searchObjectsPaginated(
            query: $searchQuery,
            _rbac: false,
            _multitenancy: false
        );

        // Visibility filter runs unconditionally — the endpoint is public per
        // SCH-PFTS-001, so it MUST NOT surface draft/depublished content to ANY
        // caller (anonymous, authenticated non-admin, or admin). The prior
        // `$isAnonymous === true &&` guard let any logged-in user enumerate the
        // whole register because `_rbac: false` disables OR's schema authorization —
        // classic broken-authorisation (OWASP A01:2021). Admins who need to see
        // drafts use the admin `/publications` endpoint, not this public surface.
        $publicationCache = [];
        $rows = [];

        foreach (($candidateResult['results'] ?? []) as $candidate) {
            $rowArray = $candidate;
            if (is_array($rowArray) === false) {
                $rowArray = $rowArray->jsonSerialize();
            }

            $schemaId   = $this->extractSchemaId($rowArray);
            $schemaSlug = ($schemaSlugById[$schemaId] ?? null);
            if ($schemaSlug === null) {
                continue;
            }

            $rowArray['@self']['schema'] = $schemaSlug;

            if ($schemaSlug === 'document') {
                $publicationSummary = $this->resolveDocumentPublicationSummary(
                    documentRow: $rowArray,
                    objectService: $objectService,
                    registerId: $registerId,
                    publicationSchemaId: $publicationSchemaId,
                    cache: $publicationCache
                );

                if ($publicationSummary === null) {
                    // No linked publication — MUST NOT appear (SCH-PFTS-003).
                    continue;
                }

                if ($publicationSummary['public'] !== true) {
                    // Transitive visibility (SCH-PFTS-004): linked publication is not
                    // public — drop the document row regardless of caller identity.
                    continue;
                }

                $rowArray['publication'] = $publicationSummary['summary'];
            }//end if

            if ($this->isObjectPublic($rowArray) === false) {
                continue;
            }

            $rows[] = $rowArray;
        }//end foreach

        return [
            'results' => $rows,
            'total'   => count($rows),
        ];

    }//end assemblePublicSearchResults()

    /**
     * Resolve a configured register/schema id from app config.
     *
     * @param string $configKey The app-config key (e.g. `publication_register`).
     *
     * @return integer|null The configured id, or null when unconfigured/non-numeric.
     *
     * @spec exclude Configuration-lookup plumbing; no domain behavior of its own.
     */
    private function resolveConfiguredId(string $configKey): ?int
    {
        if ($this->config === null) {
            return null;
        }

        $value = $this->config->getValueString('opencatalogi', $configKey, '');
        if ($value === '' || is_numeric($value) === false) {
            return null;
        }

        return (int) $value;

    }//end resolveConfiguredId()

    /**
     * Extract the numeric schema id from a serialized object row's `@self.schema`.
     *
     * @param array $rowArray The serialized object row.
     *
     * @return integer|null The schema id, or null when absent/non-numeric.
     *
     * @spec exclude Row-shape plumbing; no domain behavior of its own.
     */
    private function extractSchemaId(array $rowArray): ?int
    {
        $schema = ($rowArray['@self']['schema'] ?? ($rowArray['schema'] ?? null));
        if (is_array($schema) === true) {
            $schema = ($schema['id'] ?? null);
        }

        if ($schema === null || is_numeric($schema) === false) {
            return null;
        }

        return (int) $schema;

    }//end extractSchemaId()

    /**
     * Resolve the linked publication's `{id, slug, title}` summary for a document row.
     *
     * Looks the linked publication up by slug (denormalised on the document's own
     * `publication.slug` property) so the response can carry the publication's real
     * UUID even though the authored document payload only carries `slug` + `title`
     * (design.md "Seed publications" — the UUID does not exist until import). Results
     * are cached per request so a page of documents linking the same publication only
     * issues one lookup per unique slug.
     *
     * @param array               $documentRow         The document row (post `@self.schema` rewrite).
     * @param object              $objectService       OpenRegister ObjectService instance.
     * @param integer             $registerId          The publication register id.
     * @param integer             $publicationSchemaId The publication schema id.
     * @param array<string,mixed> $cache               Per-request slug → summary cache (by
     *                                                 reference).
     *
     * @return array{summary: array{id:string,slug:string,title:string}, public: bool}|null
     *
     * @spec openspec/changes/add-public-fulltext-search/tasks.md#task-6
     */
    private function resolveDocumentPublicationSummary(
        array $documentRow,
        object $objectService,
        int $registerId,
        int $publicationSchemaId,
        array &$cache
    ): ?array {
        $linked = ($documentRow['publication'] ?? null);
        $slug   = null;
        if (is_array($linked) === true) {
            $slug = ($linked['slug'] ?? null);
        } else if (is_string($linked) === true && $linked !== '') {
            $slug = $linked;
        }

        if ($slug === null || $slug === '') {
            return null;
        }

        if (array_key_exists($slug, $cache) === true) {
            return $cache[$slug];
        }

        $matches = $objectService->searchObjects(
            query: [
                '_register' => $registerId,
                '_schema'   => $publicationSchemaId,
                'slug'      => $slug,
                '_limit'    => 1,
            ],
            _rbac: false,
            _multitenancy: false
        );

        if (empty($matches) === true) {
            $cache[$slug] = null;
            return null;
        }

        $publication = $matches[0];
        if (is_array($publication) === false) {
            $publication = $publication->jsonSerialize();
        }

        $summary = [
            'summary' => [
                'id'    => (string) ($publication['id'] ?? ''),
                'slug'  => (string) ($publication['@self']['slug'] ?? $slug),
                'title' => (string) ($publication['title'] ?? ''),
            ],
            'public'  => $this->isObjectPublic($publication),
        ];

        $cache[$slug] = $summary;
        return $summary;

    }//end resolveDocumentPublicationSummary()

    /**
     * Find the register and schema IDs for an object UUID within a constrained scope.
     *
     * Locates which OpenRegister (register × schema) pair holds a given UUID, always
     * scoped to the caller-supplied register/schema lists. The lookup goes through
     * OpenRegister's `ObjectService` (ADR-022: consume OR abstractions) rather than
     * issuing raw SQL against OR's internal per-register/per-schema storage tables or
     * probing the DBMS catalog for their existence. OR remains free to change its
     * physical storage layout without breaking opencatalogi.
     *
     * The legacy platform-wide search across every magic table is gone (#734) — it was
     * an anonymous-reachable DoS vector and also leaked cross-catalog objects (#733).
     * Callers MUST pass non-empty $allowedRegisters and $allowedSchemas; otherwise the
     * method returns null without touching OpenRegister.
     *
     * @param string                 $uuid             The UUID of the object to find.
     * @param array<int|string>|null $allowedRegisters Register IDs the search may touch.
     * @param array<int|string>|null $allowedSchemas   Schema IDs the search may touch.
     *
     * @return array{register: int, schema: int}|null The register/schema IDs, or null.
     *
     * @SuppressWarnings(PHPMD.CyclomaticComplexity)
     *
     * @spec openspec/specs/opencatalogi-adopt-or-abstractions/spec.md
     */
    public function findObjectLocation(
        string $uuid,
        ?array $allowedRegisters=null,
        ?array $allowedSchemas=null
    ): ?array {
        if (empty($allowedRegisters) === true || empty($allowedSchemas) === true) {
            // Fail closed — without an explicit constraint we will NOT do a
            // platform-wide scan. This is the post-#734 behaviour.
            return null;
        }

        $objectService = $this->getObjectService();
        if ($objectService === null) {
            return null;
        }

        // Locate the object by asking OpenRegister to resolve it within each
        // constrained (register × schema) pair. The location lookup is visibility-
        // agnostic (_rbac: false) — it mirrors the previous behaviour of locating an
        // object's home pair; callers re-apply their own RBAC/visibility filter on the
        // subsequent read. No raw SQL and no knowledge of OR's table layout.
        foreach ($allowedRegisters as $register) {
            if (is_numeric($register) === false) {
                continue;
            }

            $registerId = (int) $register;
            foreach ($allowedSchemas as $schema) {
                if (is_numeric($schema) === false) {
                    continue;
                }

                $schemaId = (int) $schema;
                try {
                    $object = $objectService->find(
                        id: $uuid,
                        _extend: [],
                        files: false,
                        register: $registerId,
                        schema: $schemaId,
                        _rbac: false,
                        _multitenancy: false
                    );
                } catch (DoesNotExistException $e) {
                    continue;
                } catch (\Exception $e) {
                    continue;
                }

                if ($object !== null) {
                    return [
                        'register' => $registerId,
                        'schema'   => $schemaId,
                    ];
                }
            }//end foreach
        }//end foreach

        return null;

    }//end findObjectLocation()

    /**
     * Resolve the OpenRegister ObjectService from the container.
     *
     * @return object|null The OpenRegister ObjectService, or null when OR is unavailable.
     *
     * @spec exclude Lazy dependency-injection accessor for the OR ObjectService; pure
     *       framework plumbing, no domain behavior.
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
     * Build the ObjectService search query for a catalog index request.
     *
     * Merges the incoming request parameters with catalog-level schema/register filters,
     * handles multi-schema and multi-register cases, and strips non-universal _order fields
     * when searching across multiple registers.
     *
     * @param array  $catalog       Catalog data array (keys: schemas, registers).
     * @param array  $queryParams   Raw request query parameters from IRequest::getParams().
     * @param object $objectService ObjectService instance (already resolved from container).
     *
     * @return array The merged and sanitised search query ready for searchObjectsPaginated().
     *
     * @spec exclude Query-assembly plumbing extracted from PublicationsController; translates
     *       request params into an ObjectService search query, no domain behavior of its own.
     *
     * @SuppressWarnings(PHPMD.CyclomaticComplexity)
     * @SuppressWarnings(PHPMD.NPathComplexity)
     */
    public function buildCatalogSearchQuery(array $catalog, array $queryParams, object $objectService): array
    {
        // Use ObjectService centralized query builder which handles dot-to-underscore conversion.
        $searchQuery = array_merge(
            $objectService->buildSearchQuery($queryParams),
            ['_includeDeleted' => false]
        );

        // Clean up catalog-specific parameters.
        unset($searchQuery['catalogSlug'], $searchQuery['fq']);

        // Handle catalog filtering using _schemas for multi-schema search.
        if (empty($catalog['schemas']) === false) {
            $schemas = $catalog['schemas'];
            // Parse JSON string if needed.
            if (is_string($schemas) === true) {
                $schemas = json_decode($schemas, true) ?? [];
            }

            $schemas = array_map('intval', $schemas);
            // Pass all schemas for both search and faceting.
            $searchQuery['_schemas'] = $schemas;
            // Only set _schema for single-schema catalogs for magic mapper optimization.
            // Explicitly unset _schema for multi-schema search to prevent auto-setting.
            unset($searchQuery['_schema']);
            if (count($schemas) === 1) {
                $searchQuery['_schema'] = $schemas[0];
            }
        }//end if

        if (empty($catalog['registers']) === false) {
            $registers = $catalog['registers'];
            // Parse JSON string if needed.
            if (is_string($registers) === true) {
                $registers = json_decode($registers, true) ?? [];
            }

            $registers = array_map('intval', $registers);
            if (count($registers) === 1) {
                // Single register: use magic mapper optimization.
                $searchQuery['_register'] = $registers[0];
                return $searchQuery;
            }

            // Multi-register: pass all register IDs and prevent auto-setting.
            $searchQuery['_registers'] = $registers;
            $searchQuery['_register']  = null;

            // Multi-register search: strip _order on non-universal fields
            // since schemas may have different property names (e.g., 'name' vs 'naam').
            // Only allow metadata fields that exist in all magic mapper tables.
            if (empty($searchQuery['_order']) === false && is_array($searchQuery['_order']) === true) {
                foreach (array_keys($searchQuery['_order']) as $orderField) {
                    if (in_array($orderField, self::UNIVERSAL_ORDER_FIELDS, true) === false) {
                        unset($searchQuery['_order'][$orderField]);
                    }
                }

                if (empty($searchQuery['_order']) === true) {
                    unset($searchQuery['_order']);
                }
            }
        }//end if

        return $searchQuery;

    }//end buildCatalogSearchQuery()

    /**
     * Resolve schema and register objects from OpenRegister mappers for catalog enrichment.
     *
     * Returns an array with keys 'schemas' (id → {id, slug, title}) and
     * 'registers' (id → {id, slug, title}). Missing entries are silently skipped.
     *
     * @param array $catalog Catalog data array (keys: schemas, registers).
     *
     * @return array{schemas: array<int|string, array>, registers: array<int|string, array>}
     *
     * @spec exclude Metadata-resolution plumbing extracted from PublicationsController; looks up
     *       schema/register labels via OR mappers for response enrichment, no domain behavior.
     */
    public function resolveSchemaAndRegisterObjects(array $catalog): array
    {
        $resolvedSchemas   = [];
        $resolvedRegisters = [];

        try {
            $schemaMapper   = $this->container->get('OCA\OpenRegister\Db\SchemaMapper');
            $registerMapper = $this->container->get('OCA\OpenRegister\Db\RegisterMapper');

            $schemaIds = $catalog['schemas'] ?? [];
            if (is_string($schemaIds) === true) {
                $schemaIds = json_decode($schemaIds, true) ?? [];
            }

            foreach ($schemaIds as $schemaId) {
                try {
                    $schema = $schemaMapper->find((int) $schemaId);
                    $resolvedSchemas[$schemaId] = [
                        'id'    => $schema->getId(),
                        'slug'  => $schema->getSlug(),
                        'title' => $schema->getTitle(),
                    ];
                } catch (\Exception $e) {
                    // Schema not found, skip.
                }
            }

            $registerIds = $catalog['registers'] ?? [];
            if (is_string($registerIds) === true) {
                $registerIds = json_decode($registerIds, true) ?? [];
            }

            foreach ($registerIds as $registerId) {
                try {
                    $register = $registerMapper->find((int) $registerId);
                    $resolvedRegisters[$registerId] = [
                        'id'    => $register->getId(),
                        'slug'  => $register->getSlug(),
                        'title' => $register->getTitle(),
                    ];
                } catch (\Exception $e) {
                    // Register not found, skip.
                }
            }
        } catch (\Exception $e) {
            // OpenRegister not available, return empty sets.
        }//end try

        return [
            'schemas'   => $resolvedSchemas,
            'registers' => $resolvedRegisters,
        ];

    }//end resolveSchemaAndRegisterObjects()

    /**
     * Find an object within a catalog's registers/schemas using ObjectService.
     *
     * Iterates over each (register, schema) combination in the catalog.
     * Returns the first matching object entity, or null if not found.
     *
     * @param array  $catalog       Catalog data array (keys: schemas, registers).
     * @param string $id            The UUID of the object to find.
     * @param object $objectService ObjectService instance (already resolved from container).
     *
     * @return object|null The found object entity, or null.
     *
     * @spec exclude Lookup plumbing extracted from PublicationsController; iterates a catalog's
     *       (register, schema) pairs and delegates the actual read to ObjectService::find().
     */
    public function findObjectInCatalog(array $catalog, string $id, object $objectService): ?object
    {
        $catalogRegisters = $catalog['registers'] ?? [];
        $catalogSchemas   = $catalog['schemas'] ?? [];

        // Parse JSON string if needed (catalog fields may be JSON-encoded).
        if (is_string($catalogRegisters) === true) {
            $catalogRegisters = json_decode($catalogRegisters, true) ?? [];
        }

        if (is_string($catalogSchemas) === true) {
            $catalogSchemas = json_decode($catalogSchemas, true) ?? [];
        }

        // WF4 / wave-12: iterate ALL (register × schema) pairs, not just $catalogRegisters[0].
        // Previously the code only tried the first register in the list, so objects in
        // register #2+ were unreachable via this path and returned spurious 404s.
        if (empty($catalogRegisters) === false) {
            $registersToTry = array_map('intval', $catalogRegisters);
        } else {
            $registersToTry = [null];
        }

        $schemasToTry = array_map('intval', $catalogSchemas);

        foreach ($registersToTry as $register) {
            foreach ($schemasToTry as $schemaId) {
                try {
                    $object = $objectService->find(
                        id: $id,
                        _extend: [],
                        files: false,
                        register: $register,
                        schema: $schemaId,
                        _rbac: true,
                        _multitenancy: false
                    );
                    if ($object !== null) {
                        return $object;
                    }
                } catch (DoesNotExistException $e) {
                    // Object not found in this (register, schema) pair — try next.
                    continue;
                }
            }//end foreach
        }//end foreach

        return null;

    }//end findObjectInCatalog()

    /**
     * Recursively strips empty values (null, empty string, empty array) from an array.
     *
     * Used to reduce API response payload by omitting properties that have no value.
     * Values of 0, false, and "0" are preserved as they are meaningful.
     *
     * @param array $data The data array to strip empty values from.
     *
     * @return array The data with empty values removed.
     *
     * @spec exclude Response-shaping plumbing extracted from PublicationsController; recursively
     *       prunes empty values to slim the payload, no domain behavior.
     *
     * @SuppressWarnings(PHPMD.CyclomaticComplexity)
     */
    public function stripEmptyValues(array $data): array
    {
        $result = [];
        foreach ($data as $key => $value) {
            if (is_array($value) === true) {
                $this->processArrayValue(result: $result, key: $key, value: $value);
                continue;
            }//end if

            if ($value === null || $value === '') {
                continue;
            }

            $result[$key] = $value;
        }//end foreach

        return $result;

    }//end stripEmptyValues()

    /**
     * Process a single array value during empty-value stripping.
     *
     * Handles both sequential (list) and associative arrays, recursing into nested arrays.
     *
     * @param array      $result Reference to the result array being built.
     * @param int|string $key    The key for this value.
     * @param array      $value  The array value to process.
     *
     * @return void
     */
    private function processArrayValue(array &$result, int|string $key, array $value): void
    {
        if (array_is_list($value) === true) {
            $stripped = [];
            foreach ($value as $item) {
                if (is_array($item) === false) {
                    $stripped[] = $item;
                    continue;
                }

                $stripped[] = $this->stripEmptyValues(data: $item);
            }

            if (empty($stripped) === false) {
                $result[$key] = $stripped;
            }

            return;
        }

        $stripped = $this->stripEmptyValues(data: $value);
        if (empty($stripped) === false) {
            $result[$key] = $stripped;
        }

    }//end processArrayValue()
}//end class
