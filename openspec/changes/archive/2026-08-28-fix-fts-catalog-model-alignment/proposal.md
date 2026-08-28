---
kind: code
depends_on: [rbac-as-public-toggle]
---

## Why

`PublicationQueryService::assemblePublicSearchResults()` derives its search scope from three hardcoded app-config values (`publication_register`, `publication_schema`, `document_schema`), limiting `/api/search` to exactly one register and two schemas. This directly contradicts the OpenCatalogi data model: CAT-010 mandates multi-schema/multi-register catalogs; PUB-003 and PUB-004 mandate catalog-derived scope with UNION-based search. On WOO installations that include schemas beyond `publication` and `document` (e.g. `besluit`, `verzoek`, `dataset`), the public search returns an empty result set with HTTP 200 and no error — a silent, incorrect failure. Additionally, the PHP post-filter `isObjectPublic()` that enforces publication-window visibility hardcodes field names specific to the publication schema, which is why scope could never widen to arbitrary schemas (Robert Zondervan, WOO-536, 2026-08-12).

This change fixes the architectural misalignment documented by Robert in WOO-536 by moving visibility enforcement into SQL via OpenRegister's schema-level RBAC (consuming the `_rbac_as_public` primitive from the OR precursor PR, per ADR-022) and deriving search scope from the catalog model instead of app-config.

## What Changes

- **MODIFIED:** `PublicationQueryService::assemblePublicSearchResults()` — accept optional `_catalog` / `_catalogi[]` params; resolve scope from `buildCatalogSearchQuery()` + `resolveSchemaAndRegisterObjects()` instead of three `resolveConfiguredId()` calls; call `searchObjectsPaginated()` with `_rbac: true` **and** `_rbac_as_public: true` (B-1 primitive, OR PR #2855); strip `isObjectPublic()` loop; restore OR's `total` and `facets`/`facetable` for anonymous callers; preserve `PUBLIC_LIMIT_MAX` DoS cap; preserve `_content` → `_content_search` forwarding (unchanged).
- **MODIFIED:** `PublicationQueryService::resolveDocumentPublicationSummary()` — replace the denormalised `publication.slug` lookup with a per-document `_relations_contains` call on the single-schema path (Stap 5a), generalising from document→publication to related-object→source-object. N4a edge case: silent-drop + warning log for documents with 0 relations. N4b edge case: first-hit-wins for documents with multiple related publications (backward-compatible).
- **MODIFIED:** `PublicationQueryService` — replace the two-element `$schemaSlugById` map with a `SchemaMapper` lookup cached per request, so `@self.schema` carries the real slug for every schema in scope (Stap 3).
- **NEW:** `lib/Migration/Version1Date202608250900WOO536Repair.php` — repair migration (Q5 Path A) that backfills the two-rule `depublicatiedatum` read-shape into `publication` and `document` schemas on existing installations, idempotent and safe to re-run.
- **MODIFIED:** `lib/Settings/publication_register.json` — update `publication` and `document` schema seeds to the two-rule read-shape (PR seed update matching the migration target state).
- **MODIFIED:** `PublicationQueryService` docblocks — fix three broken refs to `openspec/changes/add-public-fulltext-search/` (archived 2026-07-16) by pointing at `openspec/changes/fix-fts-catalog-model-alignment/` or the updated spec IDs in `openspec/specs/search/spec.md`.
- **NEW:** `openspec/specs/search/spec.md` — add requirements SCH-PFTS-CAT-001 / SCH-PFTS-CAT-002 / SCH-PFTS-CAT-003 and amend SCH-PFTS-001 (mechanism changes from PHP post-filter to SQL RBAC via B-1, intent unchanged).

## Capabilities

### New Capabilities

(none — this change refactors an existing capability)

### Modified Capabilities

- `search`: The public search endpoint now derives scope from the catalog model (every schema in every catalog the caller may see) instead of two hardcoded schemas. Visibility enforcement moves from PHP post-filter to SQL WHERE via OpenRegister's RBAC engine. New `_catalog` / `_catalogi[]` query params allow scope narrowing. `total` and `facets`/`facetable` are restored for anonymous callers. `@self.schema` is now populated for every schema in scope, not just the two hardcoded ones.

## Impact

- **Code (opencatalogi):**
  - `lib/Service/PublicationQueryService.php` — centre of mass; Stap 1-6 refactor
  - `lib/Migration/Version1Date202608250900WOO536Repair.php` — new repair migration (Q5 Path A)
  - `lib/Settings/publication_register.json` — seed update for `publication` and `document` schemas
  - `tests/Unit/Service/PublicationQueryServiceTest.php` — new/extended unit tests
  - `tests/Unit/Service/RepairMigrationTest.php` — migration idempotency tests
- **Depends on (upstream):** OR PR #2855 (`rbac-as-public-toggle`) — must be merged to OR `main` before this OC PR is merged; the `_rbac_as_public` primitive is the mechanism for Q1 Option B (uniform public-endpoint visibility regardless of caller session).
- **API contract:** New query params `_catalog` (single UUID or slug) and `_catalogi[]` (array of UUIDs or slugs) are additive and optional. Default behaviour (no param) changes from "two-schema app-config scope" to "union of listed+published catalogs". The `_search` envelope shape is preserved (N2): flat `results[]` with `@self.schema` discriminator and embedded `publication: {id, slug, title}` on document rows.
- **Seed update (existing installs):** The repair migration handles backfill of the `depublicatiedatum` two-rule shape into `publication` and `document` schemas on existing installations (Q5 Path A). Seed change covers new installs.
- **ADR references:**
  - **ADR-022** (apps-consume-or-abstractions): this change consumes OR's `_rbac_as_public` primitive and `MagicRbacHandler` RBAC rather than re-implementing visibility in PHP.
  - **ADR-023** (action-authorization): `_rbac_as_public: true` is a per-request scope override that forces anonymous context on the RBAC computation — the action-authorization layer of the public endpoint.
  - **ADR-005** (security): public endpoints must return uniform results regardless of caller session; the B-1 primitive enforces this (Q1 Option B, overrules admin-bypass and owner OR-in for the `/api/search` surface).
  - **ADR-032** (spec-sizing): `kind: code` — centre of mass is `PublicationQueryService` refactor + tests; seed + migration edits are incidental JSON permitted within `kind: code`.
