---
kind: mixed
depends_on: []
---

# Proposal: woo-category-mapping-intake

## Why

OpenCatalogi already owns the canonical, TOOI-sourced Woo informatiecategorie vocabulary
(`TooiVocabularyService::INFORMATIECATEGORIEEN`, `lib/Service/TooiVocabularyService.php:62-104`) and the
publication-layer DiWoo mapping pipeline that resolves against it (`SitemapService::mapDiwooDocument()`,
`lib/Service/SitemapService.php:508-583`). decidesk currently owns a *duplicate* schema-slug →
informatiecategorie mapping — `WooCategorieMapping`
(`decidesk/lib/Settings/register.d/58-woo-diwoo-publication.json:87-181`) — with 8 seed rows. Every one of
those 8 rows still carries an OBVIOUS SHAPE-ONLY PLACEHOLDER TOOI URI
(`https://identifier.overheid.nl/tooi/def/thes/kern/c_PLACEHOLDER_...`), because the mapping lives inside a
*consuming* app that has no access to the canonical vocabulary — nobody has had the real value list in reach
at the moment the seed data was authored. Per the decidesk "Back to Six" programme decision (2026-08-19),
decidesk keeps only `WooBestuursorgaan` (which binds a decidesk-owned `GovernanceBody` entity); the category
mapping is pure publication-layer configuration with no decidesk-owned entity to bind, and belongs beside the
TOOI vocabulary it draws its values from — not duplicated into every app that publishes Woo documents.

## What Changes

- New opencatalogi-owned OpenRegister schema `WooCategoryMapping`: generalises decidesk's
  `WooCategorieMapping` beyond a single app. `objectType` becomes a free-text schema slug (was a
  decidesk-only closed enum of 8 values) and a new required `app` field names the owning app, so the pair
  `(app, objectType)` is the mapping key across every Conduction app that publishes Woo documents, not just
  decidesk.
- Migration/intake: the 8 decidesk seed rows are re-seeded here with their placeholder TOOI URIs **resolved**
  to the real canonical URIs already present in `TooiVocabularyService::informatiecategorieList()`
  (`lib/Service/TooiVocabularyService.php:217-224`) — this is a genuine data-quality fix, not a mechanical
  copy: the placeholder problem only exists because the mapping was authored somewhere that never had the
  real value list in reach.
- Consumption path: `SitemapService::mapDiwooDocument()` gains a type-level default fallback for the
  `diwoo:informatiecategorie` axis — when a publication carries no per-object `category` /
  `tooiCategorieUri` override (the existing WOO-TOOI-001 axis is unchanged), the mapper looks up an active
  `WooCategoryMapping` row for `(app: "opencatalogi", objectType: <publication's schema slug>)` before the
  axis is treated as unresolved. Other publishing apps (decidesk's own future imperative
  `DiWooMetadataService`, or any later app) read the same mapping the same way any app reads OR-owned shared
  config today — a direct `ObjectService::searchObjectsPaginated()` query scoped to the `woo-category-mapping`
  schema slug, exactly the pattern `WooReadinessService::getWooEnabledCatalogs()` already uses for its own
  register (`lib/Service/WooReadinessService.php:224-263`). No new bespoke controller/API is introduced —
  per ADR-022 (apps consume OpenRegister abstractions), the OR object API *is* the consumption path.
- **BREAKING** (companion change, named but not authored here — `decidesk-retire-woo-category-mapping`):
  once this change ships, decidesk's `WooCategorieMapping` schema and its 8 seed rows are retired, and any
  future decidesk Woo-decoration service is pointed at opencatalogi's mapping instead. That companion change
  must declare `depends_on: [woo-category-mapping-intake]`.

## Capabilities

### New Capabilities
- `woo-category-mapping`: opencatalogi-owned, cross-app schema-slug → TOOI informatiecategorie mapping;
  intake of decidesk's 8 seed rows with resolved (non-placeholder) URIs; read contract for publishing apps.

### Modified Capabilities
- `woo-compliance`: `SitemapService::mapDiwooDocument()`'s `diwoo:informatiecategorie` axis (WOO-TOOI-001)
  gains a type-level default fallback sourced from `woo-category-mapping` before an unresolved category is
  reported as a violation.

## Impact

- `lib/Settings/register.d/` — new fragment (ADR-037 pattern, mirrors
  `lib/Settings/register.d/fix-woo-capability-provisioning.json`) adding the `WooCategoryMapping` schema,
  attaching it to the `publication` register, and seeding the 8 migrated rows.
- `lib/Service/SitemapService.php` — `mapDiwooDocument()` (`lib/Service/SitemapService.php:508-583`) gains
  the type-level fallback lookup ahead of the existing violation-reporting branch (lines 546-565).
- `lib/Service/TooiVocabularyService.php` — unchanged. Consumed as the single source of truth for
  URI/label pairs, both at seed-authoring time here and at request time inside the new fallback.
- decidesk (companion change, **not** touched by this change): `lib/Settings/register.d/58-woo-diwoo-publication.json`'s
  `WooCategorieMapping` schema definition and its 8 `woo-categorie-mapping` seed objects are slated for
  retirement.

## Mixed-spec rationale (ADR-032)

This proposal bundles a config change (new schema + seed data) with a code change
(`SitemapService::mapDiwooDocument()` fallback + a migration/backfill path for the 8 rows) rather than
chaining `{slug}-schema-declaration` → `{slug}-consumer-rewrite` per ADR-032's default guidance, because the
task that produced this proposal explicitly scoped it as one change covering schema + migration + consumption
path, with the decidesk-side retirement already carved out as a separate, named companion change. This is
larger than the thin-glue exception (>20 LOC, >2 files), so it is flagged here for human confirmation rather
than silently accepted — see `DEFERRED_QUESTIONS` in the authoring session. If the reviewing human prefers
strict ADR-032 compliance, split at `tasks.md`'s existing task boundaries: Task 1-2 (schema + seed) become
`woo-category-mapping-schema-declaration` (kind: config), Task 3 (`SitemapService` fallback) becomes
`woo-category-mapping-sitemap-consumption` (kind: code, `depends_on: [woo-category-mapping-schema-declaration]`).
