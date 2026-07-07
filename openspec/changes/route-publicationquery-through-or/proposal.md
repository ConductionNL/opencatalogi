---
kind: code
depends_on: []
---

# Proposal: route-publicationquery-through-or

## Summary

Close the single clearest OR-abstraction leak in opencatalogi: two services
reach past OpenRegister's `ObjectService` and issue **raw SQL against OR's
internal storage layout** — the magic tables `oc_openregister_table_{register}_{schema}`
and `information_schema`. This change extends the `opencatalogi-adopt-or-abstractions`
capability with two requirements that forbid raw SQL against OR storage
internals and route the affected code through an OpenRegister search/aggregation
API instead, so OR remains free to change its physical layout without breaking
opencatalogi.

## Motivation

opencatalogi is otherwise a clean OR-consuming leaf (no `lib/Db/`, no QBMapper,
no Entity classes; publish/RBAC/audit/lifecycle/notifications all deferred to
OR). The abstraction audit at HEAD `4d8b395` found exactly two leaks:

- **`lib/Service/PublicationQueryService.php:172-270`** injects `IDBConnection`
  and builds a raw `UNION ALL` query directly against
  `oc_openregister_table_{register}_{schema}` (lines ~200-207), plus an
  `information_schema` existence probe (`magicTableExists`, ~247-270). This
  hard-codes OR's internal table-naming convention and bypasses `ObjectService`
  entirely. It is documented in-code as a post-`#734` hardening, but it is still
  reaching into OR's storage layout rather than an OR API surface.
- **`lib/Observability/OpenCatalogiMetricsProvider.php:63,187,220,246,283`**
  uses raw `IDBConnection` query builders for metric counts — the same
  bypass at lower stakes.

This directly contradicts the `opencatalogi-adopt-or-abstractions` contract
(`search consumes OR zoeken-filteren`; `MUST NOT re-implement` OR internals) and
the Hydra `hydra-gate-redundant-controller` / ADR-022 posture. If OR renames its
magic tables, changes the `oc_openregister_table_*` scheme, or moves to a
different storage backend (the OR SOLR path already fronts search), opencatalogi
silently breaks or returns wrong results. Routing these two call sites through
an OR API removes the coupling.

## Motivating precedent

The active `add-public-fulltext-search` change already pushes OR's
`zoeken-filteren` to search across **multiple schemas** (publication + document)
in one call — i.e. the cross-magic-table search that `PublicationQueryService`
currently hand-rolls in raw SQL is exactly the capability OR is being asked to
expose. This change makes opencatalogi consume that surface instead of
duplicating it.

## Goals

1. `PublicationQueryService` MUST obtain its cross-schema / multi-magic-table
   candidate rows via an OpenRegister search API (`ObjectService` /
   `zoeken-filteren` cross-schema search), not raw `UNION ALL` SQL, and MUST NOT
   probe `information_schema` for `oc_openregister_table_*` existence.
2. `OpenCatalogiMetricsProvider` MUST obtain its counts via OR object
   aggregation, not raw query builders against OR tables.
3. No behavioural regression: the public per-catalog relation endpoints and the
   metrics values MUST match their current outputs.

## Non-Goals

- **No change to visibility or publish semantics.** The
  `publicatiedatum <= now` RBAC predicate and `isObjectPublic()` defensive guard
  stay exactly as they are; only the *query mechanism* changes.
- **No new OR capability defined here.** If OR must expose a cross-schema search
  entry point, that capability lives in OpenRegister; this change consumes it.
  The task list flags the dependency rather than re-specifying OR.
- **No re-architecture of search.** `SearchController`/`PublicationService`
  already delegate to OR search; this change only removes the two raw-SQL leaks.

## High-Level Approach

Replace the raw `UNION ALL` + `information_schema` probe in
`PublicationQueryService` with a single OR cross-schema search call over the
catalog's configured registers/schemas (the same set the DCAT and publications
paths resolve), keeping the post-scoring `isObjectPublic()` filter. Replace the
metrics provider's raw query builders with OR object-count aggregation. Delete
the now-unused `IDBConnection` injections and the `magicTableExists` helper.
