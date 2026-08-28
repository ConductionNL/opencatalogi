## 1. Schema seed + repair migration (Fase 4)

- [x] 1.1 Update `publication` schema in `lib/Settings/publication_register.json` to the two-rule read-shape: `publicatiedatum $lte $now AND depublicatiedatum $gte $now` OR `publicatiedatum $lte $now AND depublicatiedatum $exists false`, plus `"authenticated"` as third element. Verify lowercase property names (propertyToColumnName leaves them unchanged).
- [x] 1.2 Update `document` schema in `lib/Settings/publication_register.json` to the same two-rule read-shape (same three elements).
- [x] 1.3 Write `lib/Migration/Version1Date202608250900WOO536Repair.php` that backfills the two-rule shape into `publication` and `document` schemas on existing installations. Guard: only inject when `read` block has the single-rule shape (missing `depublicatiedatum`); leave customised blocks alone; preserve `"authenticated"` element; idempotent.

## 2. Stap 1 — Enable RBAC with public-endpoint context

- [x] 2.1 In `PublicationQueryService::assemblePublicSearchResults()`, replace `_rbac: false` (or absent) with `_rbac: true` AND `_rbac_as_public: true` on every `searchObjectsPaginated()` call. Add docblock comment referencing SCH-PFTS-001, RBA-PUBLIC-001, and WOO-536.

## 3. Stap 2 — Catalog-derived scope

- [x] 3.1 Remove the three `resolveConfiguredId()` calls (`publication_register`, `publication_schema`, `document_schema`) from `assemblePublicSearchResults()`.
- [x] 3.2 Accept optional `_catalog` (string) and `_catalogi[]` (array) from the incoming `$searchQuery`; strip them before forwarding to OR (they are OC-level params, not OR filter params).
- [x] 3.3 When `_catalog` or `_catalogi[]` is provided, call `buildCatalogSearchQuery()` to resolve catalog objects, then `resolveSchemaAndRegisterObjects()` to get the register/schema scope.
- [x] 3.4 When neither param is provided, resolve scope as the union of all catalogs where `listed: true` and the catalog is published (same helper calls, filtering catalogs by `listed: true` + published predicate).
- [ ] 3.5 Add guard: if resolved scope contains a schema with no `read` authorization config (bypass = true under `_rbac_as_public`), log a `$this->logger->warning()` with the schema ID/slug and exclude it from the anonymous scope.

## 4. Stap 3 — Dynamic schema discriminator

- [x] 4.1 Replace the two-element `$schemaSlugById` map with a `SchemaMapper` lookup, injected via DI and cached per request.
- [x] 4.2 In the result-assembly loop, use the `SchemaMapper` to resolve `@self.schema` slug for every result row, regardless of which schema the row came from.

## 5. Stap 4 — Remove PHP post-filter + restore totals/facets

- [x] 5.1 Remove the `isObjectPublic()` post-filter loop from `assemblePublicSearchResults()`.
- [x] 5.2 Remove the per-page `total` undercount workaround (the adjustment to subtract non-public rows).
- [x] 5.3 Remove the conditional stripping of `facets` and `facetable` for anonymous callers.
- [x] 5.4 Return OR's `total`, `facets`, and `facetable` directly in the response envelope.

## 6. Stap 5a — Relations-based document→publication link

- [x] 6.1 In `resolveDocumentPublicationSummary()`, replace the denormalised `publication.slug` lookup with a per-document `_relations_contains` call on the single-schema path (`ObjectService::searchObjectsPaginated()` or equivalent single-schema query). Pass `_rbac_as_public: true` on this lookup too.
- [ ] 6.2 Generalise the helper to accept the document's `_relations` array and look up the source object in the referenced schema — not hard-coded to publication.
- [x] 6.3 N4a: when `_relations_contains` returns 0 results, set `publication: null` on the document envelope row and log `$this->logger->warning()` with the document UUID.
- [x] 6.4 N4b: when `_relations_contains` returns multiple results, select first-hit (ordered by `@self.created`); add inline comment referencing this design decision and WOO-536 plan N4b.

## 7. Stap 7 — Docblock fixes + spec refs

- [x] 7.1 In `PublicationQueryService.php`, replace all three broken docblock refs to `openspec/changes/add-public-fulltext-search/tasks.md`, `design.md`, and `spec.md#SCH-PFTS-002` with refs to `openspec/changes/fix-fts-catalog-model-alignment/tasks.md` and `openspec/specs/search/spec.md` (SCH-PFTS-CAT-* headings).

## 8. Tests

- [ ] 8.1 Add `tests/Unit/Service/PublicationQueryServiceTest.php` covering: multi-schema catalog returns rows from 3+ schemas; default scope = listed+published union; `_catalog` param narrows scope; `_catalogi[]` unions catalogs; anon caller does not see draft/future-dated objects; admin caller sees same set as anon; `total` equals actual visible count; `facets`/`facetable` present for anon; deleted `catalogSlug` still ignored; `_schema` param still stripped.
- [x] 8.2 Add `tests/Unit/Service/RepairMigrationTest.php` covering: single-rule-shape install gets two-rule shape added; already-two-rule-shape install is not duplicated (idempotency); admin-customised read block is not clobbered; `"authenticated"` element is preserved.

Acceptance criteria:

- `GET /apps/opencatalogi/api/search?_search=<term>` returns matches from every schema in every catalog the caller may see, with correct `@self.schema` slugs, correct `total`, and populated `facets`/`facetable`.
- Anonymous caller and authenticated admin caller see identical result sets for the same query.
- A draft `publication` (publicatiedatum in the future) is absent for all callers.
- A depublished `publication` (depublicatiedatum in the past) is absent for all callers.
- `_catalog` and `_catalogi[]` params correctly narrow/union scope.
- `_schema`, `_registers`, `fq` params are still stripped (Q7 Interpretation A preserved).
- `total` is not undercounted; `facets`/`facetable` are not stripped for anon.
- Repair migration is idempotent and leaves admin-customised read blocks unchanged.
- `openspec validate fix-fts-catalog-model-alignment` passes clean.
