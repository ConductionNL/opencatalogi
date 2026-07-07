# Tasks: route-publicationquery-through-or

This change is `kind: code` (ADR-032): a query-mechanism refactor removing two
raw-SQL leaks. No new config, no schema change.

- [ ] Freeze the delta spec under
  `openspec/changes/route-publicationquery-through-or/specs/opencatalogi-adopt-or-abstractions/spec.md`
  (two ADDED requirements); confirm `openspec validate route-publicationquery-through-or --strict` is green
  - Spec ref: specs/opencatalogi-adopt-or-abstractions/spec.md (this change)
  - Acceptance: validator reports valid; existing adopt-or requirements untouched
- [ ] Confirm the OpenRegister cross-schema search entry point is available
  (the `zoeken-filteren` multi-schema surface exercised by
  `add-public-fulltext-search`); if not yet shipped, block on that OR capability
  - Spec ref: proposal "Non-Goals" (dependency, not redefinition)
  - Acceptance: an OR call exists that searches across a catalog's register/schema
    pairs and merges rows
- [ ] Replace the raw `UNION ALL` + `magicTableExists()` `information_schema`
  probe in `lib/Service/PublicationQueryService.php:172-270` with that OR search
  call over the catalog's configured register/schema pairs; keep the
  `isObjectPublic()` post-filter
  - Spec ref: "opencatalogi MUST NOT issue raw SQL against OpenRegister storage internals"
  - Acceptance: `/uses` and `/used` return identical rows for a seeded catalog;
    visibility filter still applied
- [ ] Remove the `IDBConnection` injection from `PublicationQueryService` and
  delete `magicTableExists()`
  - Spec ref: same requirement
  - Acceptance: constructor no longer takes `IDBConnection`; helper gone
- [ ] Replace the raw query builders in
  `lib/Observability/OpenCatalogiMetricsProvider.php:63,187,220,246,283` with OR
  object aggregation; verify metric values are unchanged
  - Spec ref: "opencatalogi observability metrics MUST be sourced from OR object aggregation"
  - Acceptance: scraped metric values identical to pre-change for the same data
- [ ] Add a grep guard (or extend the local abstraction lint) asserting no
  `oc_openregister_table_` string and no `information_schema` reference remain in
  `lib/`
  - Spec ref: "no raw OR-storage SQL remains"
  - Acceptance: grep gate green
