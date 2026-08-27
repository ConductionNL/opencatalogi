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
     * Hard cap on `_limit` for the anonymous public FTS surface.
     *
     * Review #147 🟡 (unauthenticated DoS): without a cap, an anonymous
     * `?_limit=1000000&_content=true` fanned out unbounded into OR's
     * chunk-search path, giving a public CPU/memory amplifier. 100 rows
     * per page matches typical faceted-search UI needs; callers with a
     * legitimate higher-throughput need MUST paginate.
     *
     * @var integer
     */
    public const PUBLIC_LIMIT_MAX = 100;

    /**
     * Constructor.
     *
     * @param ContainerInterface   $container   DI container
     * @param IUserSession|null    $userSession User session for anonymity checks (auto-wired at runtime)
     * @param IAppConfig|null      $config      App config, resolves the publication/document register+schema ids
     * @param LoggerInterface|null $logger      Logger — surfaces the fail-closed empty-envelope branch so silent
     *                                          catalog-scope resolution failure is observable in production (WOO-536).
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
     * an object is public when its `status` is not a terminal-hidden state (e.g.
     * `archived`, RET-006), its own `publicatiedatum` field is set and is at or
     * before "now", and it either carries no `depublicatiedatum` or one still in
     * the future. The removed object-level `@self.published` predicate is not
     * consulted.
     *
     * @param array $objectData The serialized object data (own fields + `@self` envelope).
     *
     * @return boolean True when the object is currently published.
     *
     * @spec openspec/specs/auto-publishing/spec.md#APB-006
     */
    public function isObjectPublic(array $objectData): bool
    {
        // RET-006: `archived` is a terminal-hidden state that must never appear
        // on a public surface, regardless of publish/depublish dates. Enforced
        // here as belt-and-braces alongside the OR schema authorization contract.
        if (($objectData['status'] ?? null) === 'archived') {
            return false;
        }

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

        // Fail closed on an unparseable `depublicatiedatum` — a withdrawn object
        // whose depublish date does not round-trip through strtotime() MUST NOT
        // stay publicly visible on the strength of a parse failure (review #147
        // 🟡 fail-open). Return false instead of the previous
        // `false || > now` shape.
        $depublishedTime = strtotime((string) $depublicatiedatum);
        if ($depublishedTime === false) {
            return false;
        }

        return ($depublishedTime > $now);

    }//end isObjectPublic()

    /**
     * Assemble the public full-text search result envelope (SCH-PFTS-001,
     * SCH-PFTS-CAT-001..003).
     *
     * Delegates entirely to OR's `ObjectService::searchObjectsPaginated` across the
     * (register × schema) set derived from the catalog scope. Matches from every
     * schema in every catalog the caller may see are merged into a single flat array
     * discriminated by `@self.schema` slug, resolved dynamically via SchemaMapper. A
     * document row's linked publication is embedded via a per-document
     * `_relations_contains` refinement; documents whose linked publication is not
     * publicly visible are dropped (transitive visibility).
     *
     * Visibility is enforced in SQL by OR's schema-level RBAC under the
     * `_rbac_as_public: true` primitive (openregister PR #2855, RBA-PUBLIC-001..006):
     * admin bypass is skipped, `_owner` OR-in is suppressed, admin and anonymous
     * callers see the exact same result set — the uniform-visibility contract of
     * SCH-PFTS-001, previously enforced by a PHP post-filter, now lives in SQL.
     *
     * Scope resolution:
     *   1. `_catalog=<slug>` — that catalog's registers + schemas (SCH-PFTS-CAT-001).
     *   2. `_catalogi[]=…` — union across the resolved catalogs.
     *   3. no param — union of `listed: true` + `published` catalogs (SCH-PFTS-CAT-002).
     * A caller's own `_schema`/`_registers`/`fq`/`catalogSlug` cannot widen scope past
     * the catalog boundary — those are stripped before forwarding to OR.
     *
     * Content-search: this ships metadata search across schema properties + `@self`
     * metadata. When `_content=true` is set, OR's `_content_search` flag is forwarded
     * to widen matching to document body text (WOO-517 add-on). Extraction is owned
     * by OR's TextExtractionService + ChunkMapper.
     *
     * @param array  $queryParams   Raw request query parameters from IRequest::getParams().
     *                              Recognised keys: `_content` (opt-in body-text search),
     *                              `_catalog` (single catalog slug), `_catalogi[]` (multi
     *                              catalog union).
     * @param object $objectService OpenRegister ObjectService instance (already resolved from container).
     *
     * @return array{results: array<int, array>, total: int} Flat mixed-type result envelope.
     *
     * @spec openspec/specs/search/spec.md#SCH-PFTS-001
     * @spec openspec/changes/fix-fts-catalog-model-alignment/specs/search/spec.md
     *
     * @SuppressWarnings(PHPMD.CyclomaticComplexity)
     * @SuppressWarnings(PHPMD.NPathComplexity)
     */
    public function assemblePublicSearchResults(array $queryParams, object $objectService): array
    {
        // Stap 2 — Catalog-derived scope (SCH-PFTS-CAT-001..003).
        // Replaces the pre-WOO-536 app-config-derived scope (publication_register /
        // publication_schema / document_schema) with a catalog-model union so
        // /api/search covers every (register × schema) the caller may see.
        $scope = $this->resolveCatalogScope(queryParams: $queryParams);
        if (empty($scope['schemas']) === true) {
            // Fail-closed: no schemas in scope — either an explicit _catalog / _catalogi[]
            // resolved to nothing, or the deployment has no listed+published catalogs.
            $this->logger?->warning(
                'PublicationQueryService::assemblePublicSearchResults returning empty envelope — no schemas in resolved catalog scope',
                [
                    '_catalog'  => ($queryParams['_catalog']  ?? null),
                    '_catalogi' => ($queryParams['_catalogi'] ?? null),
                ]
            );
            return [
                'results' => [],
                'total'   => 0,
            ];
        }

        // Opt-in content-search (WOO-517): widen matching to include
        // OR-extracted document body text. Default false — omitted/false is
        // byte-identical to the WOO-506 baseline, so existing consumers see zero
        // drift. Read before buildSearchQuery() so the raw `_content` key can be
        // stripped from the forwarded query below (it is OC's own flag name, not
        // OR's — OR's equivalent is `_content_search`).
        $contentSearchRequested = filter_var(
            value: ($queryParams['_content'] ?? false),
            filter: FILTER_VALIDATE_BOOLEAN
        );

        $searchQuery = $objectService->buildSearchQuery($queryParams);
        // Q7 Interpretation A: strip any client-supplied scope-widening params so a
        // request cannot bypass the catalog-derived scope. This preserves the
        // pre-WOO-536 discipline that clients cannot inject their own register/schema
        // set past the resolved catalog boundary.
        unset($searchQuery['_schema'], $searchQuery['_registers'], $searchQuery['catalogSlug'], $searchQuery['fq']);
        unset($searchQuery['_content']);
        // OC-level params consumed by resolveCatalogScope — strip before forwarding to OR.
        unset($searchQuery['_catalog'], $searchQuery['_catalogi']);

        // Apply the resolved scope. Single-register: use `_register` for the magic-mapper
        // fast path. Multi-register: pass `_registers[]` and explicitly nullify `_register`
        // to prevent ObjectService from auto-setting it.
        $searchQuery['_schemas'] = $scope['schemas'];
        if (count($scope['registers']) === 1) {
            $searchQuery['_register'] = $scope['registers'][0];
        } else if (empty($scope['registers']) === false) {
            $searchQuery['_registers'] = $scope['registers'];
            $searchQuery['_register']  = null;
        }
        $searchQuery['_includeDeleted'] = false;

        // Clamp `_limit` to a hard maximum (review #147 🟡 unauthenticated DoS —
        // no cap allowed anonymous `?_limit=1000000&_content=true` to fan out
        // unbounded into OR's chunk-search path). The cap is enforced HERE, not
        // in the controller, so every entry point that reaches this assembler
        // is covered (`SearchController::index()` is the only one today, but
        // any future entrypoint automatically inherits the cap). 100 mirrors
        // typical faceted-search page sizes; callers wanting more MUST paginate.
        $requestedLimit = ($searchQuery['_limit'] ?? null);
        if (is_numeric($requestedLimit) === true && (int) $requestedLimit > self::PUBLIC_LIMIT_MAX) {
            $searchQuery['_limit'] = self::PUBLIC_LIMIT_MAX;
        }

        if ($contentSearchRequested === true) {
            // Forward to OR's opt-in chunk-search fan-out (expose-content-search-in
            // -object-service). OR already dedupes its own metadata-match + chunk-match
            // union on object id before returning; the `@self.id` dedup below is an
            // additional guarantee at the OC assembly layer.
            $searchQuery['_content_search'] = true;
        }

        // Stap 1 — Enable OR's schema-level RBAC with the endpoint-level anon-context
        // primitive (RBA-PUBLIC-001..006 from openregister PR #2855 / SCH-PFTS-001).
        // `_rbac_as_public: true` forces $userId=null, $userGroups=[], skips admin bypass
        // and the `_owner` OR-in — every caller sees the same public-group-eligible
        // result set. Multitenancy auto-bypasses under this flag (RBA-PUBLIC-002).
        $candidateResult = $objectService->searchObjectsPaginated(
            query: $searchQuery,
            _rbac: true,
            _multitenancy: false,
            _rbacAsPublic: true
        );

        // Stap 3 — Dynamic schema-discriminator via SchemaMapper. Replaces the pre-WOO-536
        // two-element hardcoded map; per-request cache so multi-schema search doesn't hit
        // the DB per row.
        $schemaMapper = $this->container->get('OCA\\OpenRegister\\Db\\SchemaMapper');
        $schemaSlugById = [];

        // Track the publication schema for document→publication refinement (Stap 5a).
        $publicationSchemaId = $this->resolvePublicationSchemaId(schemaMapper: $schemaMapper, scopeSchemas: $scope['schemas']);
        // Track a single register for per-document refinement queries. Falls back to null
        // when the scope spans multiple registers — refinement then goes through the
        // multi-schema path via the query dict.
        $refinementRegisterId = (count($scope['registers']) === 1) ? $scope['registers'][0] : null;

        // Stap 4 — Removed the isObjectPublic() PHP post-filter. Visibility is now
        // enforced in SQL by OR's schema authorization (RBA-PUBLIC-001..004). OR's
        // `total` and `facets` therefore reflect visible-only counts by construction —
        // no undercount workaround, no facet stripping.
        $publicationCache = [];
        $seenObjectIds    = [];
        $rows = [];

        foreach (($candidateResult['results'] ?? []) as $candidate) {
            $rowArray = $candidate;
            if (is_array($rowArray) === false) {
                $rowArray = $rowArray->jsonSerialize();
            }

            // Dedup on @self.id: a document matching on BOTH metadata and body text must
            // appear exactly once. Rows without a resolvable id (defensive — should not
            // occur) are never deduped. Marker is stamped AFTER per-row validation so a
            // first candidate that fails validation cannot silently suppress a later
            // same-id candidate that would have passed.
            $objectId = ($rowArray['@self']['id'] ?? ($rowArray['id'] ?? null));
            if ($objectId !== null && isset($seenObjectIds[$objectId]) === true) {
                continue;
            }

            $schemaId = $this->extractSchemaId($rowArray);
            if ($schemaId === null) {
                continue;
            }

            // Stap 3 — resolve slug via SchemaMapper, cached per-request.
            if (isset($schemaSlugById[$schemaId]) === false) {
                try {
                    $schemaSlugById[$schemaId] = $schemaMapper->find($schemaId)->getSlug();
                } catch (\Throwable $e) {
                    $this->logger?->warning('WOO-536: schema slug lookup failed', ['schemaId' => $schemaId, 'error' => $e->getMessage()]);
                    $schemaSlugById[$schemaId] = null;
                }
            }
            $schemaSlug = $schemaSlugById[$schemaId];
            if ($schemaSlug === null) {
                continue;
            }

            $rowArray['@self']['schema'] = $schemaSlug;

            // Strip any raw chunk-search fields OR might have attached to the row.
            // Defence-in-depth: OR already resolves each chunk hit to its owning
            // ObjectEntity, so these fields should never be present.
            unset(
                $rowArray['_snippet'],
                $rowArray['snippet'],
                $rowArray['chunk'],
                $rowArray['chunk_id'],
                $rowArray['chunkId'],
                $rowArray['score'],
                $rowArray['_score']
            );

            if ($schemaSlug === 'document' && $publicationSchemaId !== null) {
                $publicationSummary = $this->resolveDocumentPublicationSummary(
                    documentRow: $rowArray,
                    objectService: $objectService,
                    registerId: $refinementRegisterId,
                    publicationSchemaId: $publicationSchemaId,
                    cache: $publicationCache
                );

                if ($publicationSummary === null || $publicationSummary['public'] !== true) {
                    // No linked publication OR linked publication is not publicly
                    // visible under _rbac_as_public — drop the document row
                    // (transitive visibility; RBA-PUBLIC-006 propagates the anon
                    // context to the per-document refinement).
                    continue;
                }

                $rowArray['publication'] = $publicationSummary['summary'];
            }

            if ($objectId !== null) {
                $seenObjectIds[$objectId] = true;
            }

            $rows[] = $rowArray;
        }//end foreach

        // Stap 4 — Total + facets pass through unmodified. Under _rbac_as_public, OR's
        // `total` already reflects visible-only count and OR's facets aggregate over
        // visible-only rows. No caller-identity branching needed anymore.
        $envelope = [
            'results' => $rows,
            'total'   => (int) ($candidateResult['total'] ?? count($rows)),
        ];

        if (isset($candidateResult['facets']) === true) {
            $envelope['facets'] = $candidateResult['facets'];
        }
        if (isset($candidateResult['facetable']) === true) {
            $envelope['facetable'] = $candidateResult['facetable'];
        }
        return $envelope;

    }//end assemblePublicSearchResults()

    /**
     * Resolve the register + schema union that /api/search covers for this request.
     *
     * Central discriminator for SCH-PFTS-CAT-001..003:
     *   1. Explicit `_catalog` (single slug) → that catalog's registers + schemas.
     *   2. Explicit `_catalogi[]` (array of slugs) → union across the resolved catalogs.
     *   3. No param → union across every catalog where `listed: true` AND `published`
     *      is in the past. This is the SCH-PFTS-CAT-002 default that mirrors the
     *      pre-WOO-536 anon-visible scope discipline (#733 cross-catalog leak,
     *      #734 anonymous DoS — both stay closed under this rule).
     *
     * Unknown slugs are silently dropped from the union so a typo does not fail the
     * whole request. The caller MUST inspect the returned `schemas` array and
     * fail-closed if empty (empty envelope).
     *
     * @param array $queryParams Raw request query dict.
     *
     * @return array{registers: int[], schemas: int[]}
     */
    private function resolveCatalogScope(array $queryParams): array
    {
        $catalogiService = null;
        try {
            $catalogiService = $this->container->get('OCA\\OpenCatalogi\\Service\\CatalogiService');
        } catch (\Throwable $e) {
            $this->logger?->warning('WOO-536: CatalogiService unavailable', ['error' => $e->getMessage()]);
            return ['registers' => [], 'schemas' => []];
        }

        $singleCatalog = ($queryParams['_catalog'] ?? null);
        $multiCatalog  = ($queryParams['_catalogi'] ?? []);

        // 1. Explicit single-catalog scope.
        if (is_string($singleCatalog) === true && $singleCatalog !== '') {
            $c = $catalogiService->getCatalogBySlug($singleCatalog);
            if ($c === null) {
                return ['registers' => [], 'schemas' => []];
            }
            return [
                'registers' => $this->normalizeIds(value: ($c['registers'] ?? [])),
                'schemas'   => $this->normalizeIds(value: ($c['schemas']   ?? [])),
            ];
        }

        // 2. Explicit multi-catalog union.
        if (is_array($multiCatalog) === true && empty($multiCatalog) === false) {
            $regs = [];
            $schemas = [];
            foreach ($multiCatalog as $slug) {
                if (is_string($slug) === false || $slug === '') {
                    continue;
                }
                $c = $catalogiService->getCatalogBySlug($slug);
                if ($c === null) {
                    continue;
                }
                $regs    = array_merge($regs,    $this->normalizeIds(value: ($c['registers'] ?? [])));
                $schemas = array_merge($schemas, $this->normalizeIds(value: ($c['schemas']   ?? [])));
            }
            return [
                'registers' => array_values(array_unique($regs)),
                'schemas'   => array_values(array_unique($schemas)),
            ];
        }

        // 3. Default — union of listed+published catalogs.
        $allCatalogs = $this->listListedPublishedCatalogs(catalogiService: $catalogiService);
        $regs = [];
        $schemas = [];
        foreach ($allCatalogs as $c) {
            $regs    = array_merge($regs,    $this->normalizeIds(value: ($c['registers'] ?? [])));
            $schemas = array_merge($schemas, $this->normalizeIds(value: ($c['schemas']   ?? [])));
        }
        return [
            'registers' => array_values(array_unique($regs)),
            'schemas'   => array_values(array_unique($schemas)),
        ];

    }//end resolveCatalogScope()

    /**
     * Enumerate catalogs eligible for the anonymous default scope: `listed: true`
     * AND `published` is set to a datetime in the past (or absent — treated as
     * "not yet published" and excluded).
     *
     * Uses `CatalogiService::getObjectService()` to hit the catalog register directly
     * with `_rbac: false` — the catalog's OWN authorization determines its visibility
     * via the `listed` + `published` predicate here, not RBAC on the catalog register.
     *
     * @param object $catalogiService The OC CatalogiService (resolved via container).
     *
     * @return array<int, array> List of catalog jsonSerialize() arrays.
     */
    private function listListedPublishedCatalogs(object $catalogiService): array
    {
        try {
            $objectService = $catalogiService->getObjectService();
            if ($objectService === null) {
                return [];
            }
            $catalogRegister = ($this->config?->getValueString('opencatalogi', 'catalog_register', '') ?? '');
            $catalogSchema   = ($this->config?->getValueString('opencatalogi', 'catalog_schema', '') ?? '');
            if ($catalogRegister === '' || $catalogSchema === '') {
                return [];
            }

            $catalogs = $objectService->searchObjects(
                query: [
                    '@self' => [
                        'register' => $catalogRegister,
                        'schema'   => $catalogSchema,
                    ],
                    'listed' => true,
                ],
                _rbac: false,
                _multitenancy: false,
            );
        } catch (\Throwable $e) {
            $this->logger?->warning('WOO-536: enumerating listed+published catalogs failed', ['error' => $e->getMessage()]);
            return [];
        }

        $out = [];
        $now = new \DateTimeImmutable('now');
        foreach ($catalogs as $catalogEntity) {
            try {
                $c = is_array($catalogEntity) === true ? $catalogEntity : $catalogEntity->jsonSerialize();
            } catch (\Throwable $e) {
                continue;
            }
            // Published predicate: published must exist AND be in the past.
            $published = ($c['published'] ?? null);
            if (is_string($published) === false || $published === '') {
                continue;
            }
            try {
                if (new \DateTimeImmutable($published) > $now) {
                    continue;
                }
            } catch (\Throwable $e) {
                continue;
            }
            $out[] = $c;
        }
        return $out;

    }//end listListedPublishedCatalogs()

    /**
     * Resolve an id-array from either a JSON-string or a native array. Every element
     * is cast to int (schema/register ids in OC/OR are integers).
     *
     * @param mixed $value Raw value from catalog metadata.
     *
     * @return int[]
     */
    private function normalizeIds(mixed $value): array
    {
        if (is_string($value) === true) {
            $decoded = json_decode($value, true);
            $value = is_array($decoded) === true ? $decoded : [];
        }
        if (is_array($value) === false) {
            return [];
        }
        return array_values(array_map('intval', $value));

    }//end normalizeIds()

    /**
     * Find the `publication` schema id inside the resolved scope so document rows can
     * be enriched with their linked publication summary. When the scope does not
     * include the publication schema (e.g. a catalog-scoped search across only
     * `besluit` + `verzoek`), returns null and document→publication refinement is
     * simply skipped for that request.
     *
     * @param object $schemaMapper  OR SchemaMapper.
     * @param int[]  $scopeSchemas  Schema ids in the resolved scope.
     *
     * @return int|null Publication schema id when present in scope, null otherwise.
     */
    private function resolvePublicationSchemaId(object $schemaMapper, array $scopeSchemas): ?int
    {
        foreach ($scopeSchemas as $sid) {
            try {
                if ($schemaMapper->find($sid)->getSlug() === 'publication') {
                    return (int) $sid;
                }
            } catch (\Throwable $e) {
                continue;
            }
        }
        return null;

    }//end resolvePublicationSchemaId()

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
     * Stap 5a (WOO-536): replaces the denormalised `publication.slug` string lookup
     * with a per-document `_relations_contains` query on the publication schema. The
     * OR-side relation graph is authoritative, so a slug rename no longer detaches
     * documents from the envelope.
     *
     * The query runs under `_rbac_as_public: true` so admin sessions on the public
     * endpoint see the SAME linked-publication as anonymous callers — no linked-object
     * leak (RBA-PUBLIC-006). If OR returns nothing, the linked publication either does
     * not exist, is not public under the caller's effective context, or the document
     * is genuinely unlinked — all three collapse to "drop the row" (transitive
     * visibility).
     *
     * N4b (WOO-536 plan): a document related to multiple publications resolves to the
     * OLDEST-by-created linked publication (most stable link — does not change as new
     * publications are added). Documented as a known approximation.
     *
     * @param array               $documentRow         The document row (post `@self.schema` rewrite).
     * @param object              $objectService       OpenRegister ObjectService instance.
     * @param integer|null        $registerId          The publication register id, or null when the
     *                                                 outer scope spans multiple registers (query
     *                                                 falls back to the multi-schema search path).
     * @param integer             $publicationSchemaId The publication schema id in scope.
     * @param array<string,mixed> $cache               Per-request document-uuid → summary cache
     *                                                 (by reference).
     *
     * @return array{summary: array{id:string,slug:string,title:string}, public: bool}|null
     *
     * @spec openspec/changes/fix-fts-catalog-model-alignment/tasks.md
     */
    private function resolveDocumentPublicationSummary(
        array $documentRow,
        object $objectService,
        ?int $registerId,
        int $publicationSchemaId,
        array &$cache
    ): ?array {
        $documentUuid = ($documentRow['@self']['id'] ?? ($documentRow['id'] ?? null));
        if (is_string($documentUuid) === false || $documentUuid === '') {
            return null;
        }

        if (array_key_exists($documentUuid, $cache) === true) {
            return $cache[$documentUuid];
        }

        // Build the per-document refinement query. Under _rbac_as_public: true, OR only
        // returns publications that would be visible to an anonymous caller — so a
        // non-empty result guarantees the linked pub is public.
        $refinementQuery = [
            '_schema'             => $publicationSchemaId,
            '_relations_contains' => $documentUuid,
            '_limit'              => 2,
            '_order'              => ['@self.created' => 'asc'],
        ];
        if ($registerId !== null) {
            $refinementQuery['_register'] = $registerId;
        }

        try {
            $matches = $objectService->searchObjectsPaginated(
                query: $refinementQuery,
                _rbac: true,
                _multitenancy: false,
                _rbacAsPublic: true
            );
        } catch (\Throwable $e) {
            $this->logger?->warning(
                'WOO-536: document→publication refinement failed',
                ['documentUuid' => $documentUuid, 'error' => $e->getMessage()]
            );
            $cache[$documentUuid] = null;
            return null;
        }

        $rows = ($matches['results'] ?? []);
        if (empty($rows) === true) {
            // N4a: no publicly-visible linked publication → drop the document.
            $this->logger?->info(
                'WOO-536: document has no publicly-visible linked publication',
                ['documentUuid' => $documentUuid]
            );
            $cache[$documentUuid] = null;
            return null;
        }

        if (count($rows) > 1) {
            // N4b: multi-linked document — the oldest-by-created wins (documented
            // approximation, most stable link).
            $this->logger?->info(
                'WOO-536: document linked to multiple publications; using oldest-by-created',
                ['documentUuid' => $documentUuid, 'count' => count($rows)]
            );
        }

        $publication = $rows[0];
        if (is_array($publication) === false) {
            $publication = $publication->jsonSerialize();
        }

        $summary = [
            'summary' => [
                'id'    => (string) ($publication['@self']['id'] ?? ($publication['id'] ?? '')),
                'slug'  => (string) ($publication['@self']['slug'] ?? ($publication['slug'] ?? '')),
                'title' => (string) ($publication['title'] ?? ''),
            ],
            'public'  => true,
        ];

        $cache[$documentUuid] = $summary;
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
