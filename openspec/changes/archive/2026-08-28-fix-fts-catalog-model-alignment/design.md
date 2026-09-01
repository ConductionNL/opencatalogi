## Context

`PublicationQueryService::assemblePublicSearchResults()` was introduced in WOO-506/WOO-517 to power the public full-text search endpoint `/apps/opencatalogi/api/search`. Its architecture hardcodes scope to three app-config values (`publication_register`, `publication_schema`, `document_schema`) and enforces publication-window visibility via a PHP post-filter `isObjectPublic()`. Robert Zondervan diagnosed this as architecturally broken in WOO-536 (2026-08-12): the two-schema straitjacket is a direct consequence of the PHP filter — because visibility had to be reconstructed in PHP using known field names, scope could never widen to schemas with different field layouts.

Phase 1 discovery (WOO-536 plan, 2026-08-24) confirmed:
- **D1 (falsified):** Robert's claim that all schemas already carry the two-rule `depublicatiedatum` shape is NOT true on either `main` or `development`. `publication` and `document` both have only the single-rule `publicatiedatum $lte $now` shape — the `depublicatiedatum` branch is absent. A Path A repair migration is required.
- **D2 (falsified partially):** `_relations_contains` is supported in the single-schema path (`buildFilteredQuery`) but NOT in the UNION-arm (`buildWhereConditionsSql`). Stap 5a (per-document single-schema lookup) is chosen over Stap 5b (UNION with relations filter).
- **D3 (new gap):** `MagicRbacHandler::buildRbacConditionsSql()` resolves user-groups unconditionally from `$this->userSession`. No escape hatch existed for "force anonymous context". The OR precursor PR (`rbac-as-public-toggle`) adds the `_rbac_as_public: true` query flag to address this (B-1 primitive).

## Goals / Non-Goals

**Goals:**

- Derive `/api/search` scope from the catalog model: accept `_catalog` (single) / `_catalogi[]` (array) params; default = union of `listed: true` + published catalogs; reuse `buildCatalogSearchQuery()` + `resolveSchemaAndRegisterObjects()`.
- Move visibility enforcement from PHP post-filter to SQL WHERE via OpenRegister's RBAC engine, consuming the B-1 primitive (`_rbac_as_public: true`) from OR PR #2855.
- Make `@self.schema` dynamic for every schema in scope (not just the two hardcoded slugs).
- Restore OR's `total` and `facets`/`facetable` for anonymous callers; remove the PHP workarounds that undercount and strip them.
- Fix `resolveDocumentPublicationSummary()` to use `_relations_contains` (single-schema path, Stap 5a) instead of the denormalised slug lookup.
- Backfill the `depublicatiedatum` two-rule shape into `publication` and `document` schemas on existing installs via a repair migration (Q5 Path A).
- Fix three broken docblock refs in `PublicationQueryService.php` that point to the archived `add-public-fulltext-search` change.

**Non-Goals:**

- Changing OpenRegister beyond PR #2855 (B-1). The UNION-arm `_relations_contains` gap is not addressed in this PR (Stap 5b was rejected; Stap 5a is sufficient).
- Per-schema archetype clean-up for `catalog`, `listing`, `organization`, `page`, `theme`, `menu`, `glossary` — deferred to a follow-up ticket unless Fase 7 smoke tests reveal a breakage.
- `_schema`/`_registers`/`fq` client-scope widening — the existing `unset()` on these params is preserved (Q7 Interpretation A).
- MariaDB `::text ILIKE` portability (tracked as WOO-544, separate ticket).
- Changing the `/api/search` envelope shape (N2): flat `results[]` with `@self.schema` discriminator + embedded `publication: {id, slug, title}` on document rows — unchanged.
- Federation search (`development` branch only; N3): will be retested when WOO-536 is ported to `development` post-main-merge.
- Exposing `_rbac_as_public` as a documented HTTP parameter for clients — it is set programmatically by the app, not forwarded from HTTP requests.

## Decisions

### D-SCOPE: Catalog-derived scope over app-config (Stap 2)

Replace the three `resolveConfiguredId()` calls with `buildCatalogSearchQuery()` + `resolveSchemaAndRegisterObjects()`, already implemented in the same class (`PublicationQueryService`). These methods correctly resolve the union of `catalog.registers` × `catalog.schemas` for a given set of catalogs.

**New params:** `_catalog` (single UUID or slug) and `_catalogi[]` (array of UUIDs or slugs), following OR's doubled singular/plural convention (`_register`/`_registers`, `_schema`/`_schemas`). Default (no param) = union over all catalogs where `listed: true` and the catalog object itself is published. The old `catalogSlug` query-string param was already `unset()` deliberately — it is not revived.

**Client scope discipline (Q7 Interpretation A):** The existing `unset($searchQuery['_schema'], $searchQuery['_registers'], $searchQuery['fq'])` is preserved. Clients may narrow to a specific catalog via `_catalog`/`_catalogi[]` but may not inject raw schema/register/fq overrides.

### D-B1: Consume `_rbac_as_public` primitive (Stap 1 + Stap 6)

`assemblePublicSearchResults()` passes `_rbac: true` AND `_rbac_as_public: true` on every `searchObjectsPaginated()` call. The B-1 primitive (OR PR #2855, `rbac-as-public-toggle`) implements Q1 Option B: regardless of the caller's session (admin, authenticated owner, anonymous), the search evaluates RBAC using only the `public` group's matching rules — skipping the admin-group bypass and suppressing the `_owner = <userId>` OR-in condition. This enforces uniform visibility on the public endpoint (SCH-PFTS-001) via SQL, making the PHP post-filter redundant.

**Why B-1 over a synthetic anonymous session:** Constructing a fake `IUser` and injecting it for the duration of the call was considered. Rejected: too invasive, touches more Nextcloud interfaces, and the B-1 flag is simpler and equally safe. Method-parameter threading (`$_rbacAsPublic = true`) is the chosen mechanism in OR (see OR `rbac-as-public-toggle` design.md).

**Security property (D6 in OR design):** `_rbac_as_public: true` narrows, never widens, the result set. It removes the owner OR-in and admin bypass — both of which add rows that schema `read` rules would otherwise exclude. An authenticated caller sees a strict subset of what they'd see without the flag. The flag MUST NOT be passed through from HTTP client requests; OR's `ObjectService` strips client-supplied `_rbac_as_public` and only accepts it as a method parameter.

**Schemas without explicit `read` rules:** `MagicRbacHandler::buildRbacConditionsSql()` returns `['bypass' => true]` for schemas with no `read` action configured. Under B-1, a schema with no `read` config becomes fully open (bypass = all rows returned). Guard: `resolveSchemaAndRegisterObjects()` + catalog scope already limits results to schemas that are part of a listed+published catalog. An additional guard should log a warning when a schema with `bypass: true` is included in the public search scope, so operators are alerted to add explicit rules. (Robert's "wagenwijd open" warning.)

### D-MAPPER: Dynamic SchemaMapper over hardcoded two-element map (Stap 3)

Replace the two-entry `$schemaSlugById` (`[publicationSchemaId => 'publication', documentSchemaId => 'document']`) with a `SchemaMapper` lookup cached per request. This ensures `@self.schema` carries the real slug for every schema in scope, not just the two hardcoded ones. Cache-per-request avoids repeated DB lookups in the page-result loop; the `SchemaMapper` service is already available via DI.

### D-FILTER-REMOVAL: Drop `isObjectPublic()` and its workarounds (Stap 4)

Remove the `isObjectPublic()` post-filter loop and the two anonymous-caller workarounds it necessitated:
1. **Per-page `total` undercount** — `total` was adjusted downward to subtract non-public rows filtered out in PHP. Under RBAC, OR's `total` counts only rows that pass the SQL WHERE, so it is correct without PHP adjustment.
2. **Stripping `facets`/`facetable` for anonymous callers** — Facets were stripped because the PHP filter could not be applied to facet counts. Under RBAC, `buildWhereConditionsSql()` applies the same RBAC conditions to both the UNION-arm result rows AND the facet aggregations (verified in Phase 1: `MagicFacetHandler` uses the same WHERE clause). Facets are now correct without PHP adjustment.

### D-STAP5A: Per-document `_relations_contains` lookup (Stap 5)

The current `resolveDocumentPublicationSummary()` resolves the linked publication by looking up the `publication.slug` property — a denormalised field that breaks when a publication's slug changes. Replace with `_relations_contains`: for each document in the result page, query the `publication` schema's register for objects whose `_relations` include the document's UUID.

**Why Stap 5a (single-schema path) over Stap 5b (UNION-arm with relations filter):**
Phase 1 D2 confirmed that `_relations_contains` is NOT handled in the UNION-arm `buildWhereConditionsSql()` on either `main` or `development`. Stap 5a stays on the single-schema path (`buildFilteredQuery`) — this is the existing path for the `resolveDocumentPublicationSummary` call, so no architectural regression.

**Edge case N4a — Document with 0 related publications:**
Decision: silent-drop + `$this->logger->warning()`. The document row appears in the results (it passed RBAC) but `publication: null` is set on the envelope row and a warning is logged with the document UUID for observability. Backward-compatible (current slug-lookup also silent-drops for null slug).

**Edge case N4b — Document with multiple related publications:**
Decision: first-hit-wins (backward-compatible with the implicit first-hit of the slug-lookup). `_relations_contains` returns results ordered by `@self.created`; the first result is selected. This is arbitrary but deterministic. Option 4 (most-recent publication) is preferred long-term but requires a `_order` param on the `_relations_contains` query; deferred to a follow-up to keep this PR focused on WOO-536 scope.

**Generalisation:** The helper is extracted as a private method that takes the document's `_relations` array and issues a filtered lookup on the referenced object's schema — not limited to document→publication. This makes it reusable for other relation traversals in future.

**`_rbac_as_public` on the per-document lookup:** The per-document refinement lookup inside `resolveDocumentPublicationSummary()` also passes `$_rbacAsPublic = true` to `ObjectService::find()` (extended in OR PR #2855 to accept this parameter). This prevents the PermissionHandler PHP-side check from leaking a linked publication that anonymous callers cannot see (OR `design.md` D-scope, Q2 decision).

### D-MIGRATION: Two-rule read-shape repair migration (Q5 Path A)

`lib/Settings/publication_register.json` seeds the `publication` and `document` schemas with `"read": [{"group": "public", "match": {"publicatiedatum": {"$lte": "$now"}}}, "authenticated"]`. The `depublicatiedatum` branch is missing: under `_rbac_as_public`, an object with `depublicatiedatum` in the past would be returned (no rule excludes it). Robert's target shape (WOO-536 body) uses two top-level OR rules:

```json
"read": [
  {"group": "public", "match": {"publicatiedatum": {"$lte": "$now"}, "depublicatiedatum": {"$gte": "$now"}}},
  {"group": "public", "match": {"publicatiedatum": {"$lte": "$now"}, "depublicatiedatum": {"$exists": false}}},
  "authenticated"
]
```

Why two rules (not one combined rule): `buildComparisonOperatorConditionSql()` short-circuits within a single match block — `$exists: false` combined with `$gte: $now` in one block would only match objects where both conditions are simultaneously met (i.e., a field that both doesn't exist AND is >= now — impossible). Two separate top-level match blocks produce an OR of the two conditions at the schema-RBAC level.

**Migration discipline:**
- Only touches `publication` and `document` schemas (G3 per-schema scope decision).
- Detection: only injects the two-rule shape when the existing `read` block has the single-rule shape with exactly the `publicatiedatum $lte $now` condition (missing `depublicatiedatum`). If an admin has already customised the `read` block, the migration leaves it alone.
- Idempotent: safe to re-run on already-migrated installations (double-detection guard).
- Preserves the `"authenticated"` string element as the third array entry.
- Precedent: WOO-517 commit `469cbdf7` used this exact shape for the `document`-schema auto-attach.

**Column casing (Robert's Aandachtspunten §2):** `publicatiedatum` and `depublicatiedatum` are all-lowercase on `main`. `propertyToColumnName()` leaves all-lowercase strings unchanged. No column-mapping risk.

**Scope of the seed/migration (schema-config scope):** Only `publication` and `document` get the two-rule fix. The other seven seed schemas (`catalog`, `listing`, `organization`, `page`, `theme`, `menu`, `glossary`) are left at their current read-block shape — their existing rules are valid RBAC config and do not have `depublicatiedatum` semantics. The B-1 primitive makes the search endpoint schema-agnostic; these schemas will work correctly under `_rbac_as_public` with their current rules.

### D-DOCBLOCKS: Fix broken docblock refs (Stap 7)

The current `PublicationQueryService.php` docblocks reference `openspec/changes/add-public-fulltext-search/tasks.md`, `design.md`, and `openspec/specs/.../spec.md#SCH-PFTS-002`. All three point to the archived change (`openspec/changes/archive/2026-07-16-add-public-fulltext-search/` on Codeberg, but the archive is not present on GitHub `main`). Fix by replacing these refs with:
- `openspec/changes/fix-fts-catalog-model-alignment/tasks.md` for task references
- `openspec/specs/search/spec.md#SCH-PFTS-CAT-001` (or the relevant new SCH-PFTS-* heading) for spec references

## Risks / Trade-offs

- **[Schema with `bypass: true` included in public scope]** A schema added to a listed catalog without any `read` authorization config will be fully open under `_rbac_as_public`. Mitigation: log a warning (per the guard in D-SCOPE), document the requirement in SCH-PFTS-CAT-002, and link it to Robert's "wagenwijd open" caution in WOO-536. Per-schema archetype clean-up deferred; admin operator must be informed.
- **[`_rbac_as_public` not propagated to `ContentSearchHandler`]** Verified in Phase 1 G2: `$_rbac` propagates through `ContentSearchHandler` into `MagicMapper::find()`. The `_rbac_as_public` flag rides in the `$searchQuery` dict which is dict-copied to every sub-query including the content-search path. No additional threading needed.
- **[N4b first-hit-wins is arbitrary]** For documents linked to multiple publications, the selected publication summary is the first by created date. Edge case, but deterministic. A follow-up ticket can introduce most-recent-wins if product requires it.
- **[OR `_relations_contains` is NOT handled in the UNION-arm]** The per-document refinement lookup (Stap 5a) is on the single-schema path. If the publication schema spans multiple registers (unusual for WOO installs), the lookup might miss publications in non-primary registers. Acceptable for the WOO baseline; documented as a known limitation.
- **[Repair migration touches live DB schema config]** The migration reads and writes the `authorization` field of existing schema objects via `ObjectService`. Test on staging before production upgrade. Idempotency guard prevents double-injection.

## Migration Plan

1. Merge OR PR #2855 (`rbac-as-public-toggle`) to OR `main`. OC's `assemblePublicSearchResults()` will set `_rbac_as_public: true`; this is a no-op until OR PR is merged.
2. Merge this OC PR. On `occ upgrade` / `occ maintenance:repair`, the repair migration runs and backfills the two-rule shape into `publication` and `document` schemas on existing installations.
3. New installations: seed already carries the two-rule shape.
4. **Rollback:** Remove `_rbac: true` + `_rbac_as_public: true` from `assemblePublicSearchResults()` and restore the `isObjectPublic()` loop. Migration cannot easily be rolled back (schema config change) but is idempotent, so re-running is safe.

## Open Questions

All questions from WOO-536 clarification rounds are decided (see plan file). Remaining items for Fase 5 checkpoint:

- N4a (0-relations): confirmed decision is silent-drop + warning log (this design).
- N4b (multiple-relations): confirmed decision is first-hit-wins (this design); follow-up ticket for most-recent if needed.
