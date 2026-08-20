# Migration: add-document-content-search

## Current State

No OpenCatalogi database schema state is relevant to this change. The chunk store the feature reads from — `openregister_chunks` (with `text_content` column + PostgreSQL `tsvector` GIN index) — already exists in OpenRegister via the merged [`hybrid-document-search`](https://codeberg.org/Conduction/openregister/src/branch/development/openspec/changes/hybrid-document-search) change (migration `Version1Date20260706101000`). No prior OpenCatalogi table, column, or index is touched by this feature.

## Target State

Same as current state on the OpenCatalogi side. The feature is a query-time capability layered on top of an existing OpenRegister index — no new OC-side persistence.

## Migration Class

**None required on the OpenCatalogi side.** No `lib/Migration/Version*.php` file is added by this change.

The prerequisite OpenRegister-side change (expose `_content_search` flag on `ObjectService::searchObjectsPaginated()`) is a service-layer patch, not a schema migration; it also carries no `lib/Migration/` file.

## Migration Steps

Not applicable. Change is spec + service-layer only, no persistence transitions.

## Data Impact

- **Records affected on install/deploy:** 0 in OpenCatalogi's own tables.
- **OpenRegister side:** the `openregister_chunks` table is written to lazily by pre-existing background jobs (`FileTextExtractionJob`, `CronFileTextExtractionJob`) as file objects are uploaded — that population began when the `hybrid-document-search` change landed and continues normally. This change consumes it; it does not cause additional writes.
- **Data loss / transformation:** none.
- **Live-data safe:** yes — deploy is a plain code push. No downtime.

## Rollback Procedure

Revert the `PublicationQueryService::assemblePublicSearchResults()` change (single method, single file). Endpoint returns to WOO-506 baseline behaviour. Chunk-store data is left in place (owned by OpenRegister, still valid, no cleanup needed).

## Validation

- **Post-deploy smoke:**
    ```
    curl "https://<host>/apps/opencatalogi/api/search?_search=<known-phrase>&_content=true"
    curl "https://<host>/apps/opencatalogi/api/search?_search=<known-phrase>"
    ```
    First call returns any documents whose extracted body text matches; second call returns byte-identical WOO-506 baseline (metadata-only matches). If the two responses are identical when the phrase is body-only, the `_content` wire is not live.
- **Chunk-store readiness (OR-side):** confirm `SELECT COUNT(*) FROM openregister_chunks WHERE text_content IS NOT NULL` is non-zero on the target env — proves the extraction pipeline is populating before the feature is exercised.
- **Visibility gate:** anonymous request against a depublished document with a body-text match MUST return zero rows. Regression-tested via `testContentMatchOnDepublishedDocumentIsDropped` (see `test-plan.md`).
