# Tasks: woo-category-mapping-intake

## 1. Schema + register fragment

- [ ] 1.1 Add the `WooCategoryMapping` schema (`app`, `objectType`, `informatiecategorie`,
      `informatiecategorieLabel`, `active`, `notes`) in a new `lib/Settings/register.d/*.json` fragment
      (ADR-037 pattern), pattern-validate `informatiecategorie` against the TOOI URI prefix, and attach the
      schema to the `publication` register (`magicMapping: true`, `autoCreateTable: true`)
- [ ] 1.2 Seed the 8 migrated decidesk rows in the same fragment using the resolved URIs/labels from
      `design.md`'s Seed Data table — never the `c_PLACEHOLDER_...` values decidesk shipped

## 2. SitemapService consumption fallback

- [ ] 2.1 Extend `SitemapService::mapDiwooDocument()` (`lib/Service/SitemapService.php:546-565`) with a
      type-level default lookup against an active `WooCategoryMapping` row for
      `(app: "opencatalogi", objectType: <publication's schema slug>)`, used only when the publication
      declares no `category`/`tooiCategorieUri`/`tooiCategorieNaam` of its own, before the axis is reported
      as an unresolved violation
- [ ] 2.2 Add `@spec openspec/changes/woo-category-mapping-intake/specs/woo-compliance/spec.md` PHPDoc tag to
      the modified method

## 3. Unit tests

- [ ] 3.1 Test: publication with no declared category + an active mapping for its schema slug →
      `diwoo:informatiecategorie` resolves to the mapping's URI/label
- [ ] 3.2 Test: publication with no declared category + no mapping for its schema slug → axis omitted,
      violation reported (existing behavior unchanged)
- [ ] 3.3 Test: publication with its own declared category + an active mapping also present → the
      publication's own category wins; the mapping is not consulted
- [ ] 3.4 Test: a `WooCategoryMapping` row whose `informatiecategorie` does not resolve to a value-list
      member is never used as a fallback (WOO-CAT-002)

## 4. Seed data verification

- [ ] 4.1 Confirm all 8 migrated rows resolve against `TooiVocabularyService::informatiecategorieList()` with
      zero `c_PLACEHOLDER_...` segments remaining
- [ ] 4.2 Confirm `(app, objectType)` uniqueness across the 8 seeded rows

## 5. Deduplication + integration verification

- [ ] 5.1 Search opencatalogi `lib/Service/` and openregister `lib/Service/` for any existing app-scoped
      category-mapping capability that overlaps with this schema; document "no overlap found" or the overlap
      found
- [ ] 5.2 Verify `ObjectService::searchObjectsPaginated()` (the same abstraction
      `WooReadinessService::getWooEnabledCatalogs()` already uses, `lib/Service/WooReadinessService.php:243-257`)
      is the only query path used for the new fallback, and confirm no new controller/REST endpoint was added
      to serve this lookup to other apps (D4)

## 6. Documentation

- [ ] 6.1 Document the `WooCategoryMapping` schema, its `(app, objectType)` key, and the cross-app OR-query
      read pattern in `docs/features/woo-compliance.md` (create the section if absent)

## 7. Spec sync

- [ ] 7.1 After verification, sync both delta specs (`woo-category-mapping` new capability, `woo-compliance`
      MODIFIED `WOO-TOOI-001`) back to `openspec/specs/` per the project's sync step
