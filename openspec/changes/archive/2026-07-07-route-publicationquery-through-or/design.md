# Design: route-publicationquery-through-or

## Context

Verified at HEAD `4d8b395` (abstraction audit):

- `lib/Service/PublicationQueryService.php`
  - `:79` injects `IDBConnection`.
  - `:172-230` builds a raw `UNION ALL` across
    `oc_openregister_table_{register}_{schema}` (concrete table names at
    `:200-207`).
  - `:247-270` `magicTableExists()` probes `information_schema.tables` for the
    magic table before querying.
  - `:124` `isObjectPublic()` is a post-query defensive visibility guard
    (`publicatiedatum`/`depublicatiedatum`) used by the public per-catalog
    relation endpoints (`/uses`, `/used`).
- `lib/Observability/OpenCatalogiMetricsProvider.php:63,187,220,246,283` — raw
  `IDBConnection` query-builder counts.

Everything else in opencatalogi already consumes `ObjectService` (no `lib/Db/`,
no QBMapper); these two files are the exception.

## Why the raw SQL exists (and why it can now go)

The in-code note ties the `UNION ALL` to OR issue `#734` — at that time OR's
`ObjectService` did not offer a single call that searches across several magic
tables (several register/schema pairs) and merges the rows. The active
`add-public-fulltext-search` change now drives `zoeken-filteren` to search
**across multiple schemas in one call** (publication + document). That is the
same multi-magic-table search `PublicationQueryService` hand-rolls. Once that OR
surface is available, the raw SQL is redundant.

## Decisions

### D1 — Consume OR cross-schema search, keep the visibility guard
Replace the `UNION ALL` + `magicTableExists` probe with a single OR search call
over the catalog's configured register/schema pairs, then keep the existing
`isObjectPublic()` post-filter untouched. The visibility semantics do not
change; only the retrieval mechanism does. If a configured magic table does not
yet exist, the OR search surface — not an `information_schema` probe in
opencatalogi — is responsible for tolerating it.

### D2 — Metrics via OR aggregation
Replace the metrics provider's raw query builders with OR object-count
aggregation (the same aggregation surface `UsageCounterService`/`StatsController`
use for analytics roll-ups). Metric values MUST be identical.

### D3 — Dependency, not redefinition
If OR needs to expose (or has already exposed, via `add-public-fulltext-search`)
a cross-schema search entry point, that is an OpenRegister capability. This
change consumes it and flags the ordering in tasks; it does not respecify OR.

## Delta

Two ADDED requirements on `opencatalogi-adopt-or-abstractions`:

1. opencatalogi MUST NOT issue raw SQL against OpenRegister storage internals
   (`oc_openregister_table_*` / `information_schema`); cross-schema publication
   search MUST go through an OR search API.
2. opencatalogi observability metrics MUST be sourced from OR object aggregation,
   not raw query builders against OR tables.

## Testing

PHPUnit: assert `PublicationQueryService` no longer injects `IDBConnection` and
that `/uses` + `/used` return the same rows as before against a seeded catalog;
assert the metrics provider returns identical counts via OR aggregation. Grep
gate: no `oc_openregister_table_` string and no `information_schema` reference
remain in `lib/`. `@e2e exclude` — internal query-mechanism refactor; the public
relation endpoints are covered by the publications spec's real-UI tests.
