---
status: reviewed
---

# Federation

## Purpose

Federation enables OpenCatalogi to aggregate publications from both local catalogs and external (federated) OpenCatalogi instances into a unified search interface. The federation endpoints mirror the publication API but include aggregation logic that queries remote directories, merges results, and provides a single response to the frontend. This is the backbone of the decentralized catalog network where multiple government organizations can share and discover each other's publications.

## Context

In the Dutch government landscape, WOO (Wet open overheid) mandates transparency and public access to government information. Each municipality, ministry, or agency may operate its own OpenCatalogi instance. Without federation, citizens and administrators must know which organization runs which instance and visit each separately. The federation layer solves this by providing a single search entry point that queries all known OpenCatalogi instances in parallel and returns a unified result set.

**Relation to existing OpenCatalogi infrastructure:**
- `FederationController.php` — six public GET endpoints, all annotated `@PublicPage`, `@NoCSRFRequired`, `@NoAdminRequired`
- `PublicationService` — central service handling aggregation, remote HTTP calls, facet merging, and result sorting
- `DirectoryService` — provides listing data including remote instance URLs
- Listing objects with `integrationLevel: "search"` determine which remote sources participate in federation
- GuzzleHttp async HTTP client enables parallel requests to multiple remote directories

**Relation to other specs:**
- `publications` spec — federation endpoints mirror the publications API structure
- `catalogs` spec — local catalogs are the source of locally-served publications

## Requirements

### Requirement: FED-001 — List all publications from local and federated sources with merged pagination

The `GET /api/federation/publications` endpoint MUST return a merged, paginated list of publications from all local catalogs and all remote OpenCatalogi instances configured with `integrationLevel: "search"`.

#### Scenario: REQ-FED-001 — Federated publication list aggregates local and remote results
- GIVEN local catalogs contain 50 publications
- AND a remote directory at "https://catalogus.rotterdam.nl" is configured as a Listing with `integrationLevel: "search"` and `default: true`
- WHEN a GET request is made to `/api/federation/publications?_search=opendata`
- THEN `PublicationService.getAggregatedPublications()` is called
- AND local results are fetched from all configured catalogs via ObjectService
- AND remote results are fetched via async HTTP GET to the remote directory's publication endpoint
- AND results from all sources are merged into a single array
- AND the response includes `results`, `total`, `page`, `pages`, `limit`, `offset`, and `facets` fields
- AND a unified JSON response is returned with status 200

#### Scenario: REQ-FED-001b — Facets from all sources are merged
- GIVEN local catalogs return facets with `theme: [{_id: "bestuur", count: 12}]`
- AND a remote directory returns facets with `theme: [{_id: "bestuur", count: 8}, {_id: "zorg", count: 5}]`
- WHEN results are merged
- THEN the merged `facets.theme` MUST contain `{_id: "bestuur", count: 20}` (counts summed)
- AND `{_id: "zorg", count: 5}` MUST be included (new bucket from remote source)

#### Scenario: REQ-FED-001c — Local instance is not queried as remote
- GIVEN the directory contains a Listing pointing to the local instance's own URL
- WHEN `getAggregatedPublications()` is called
- THEN the Listing pointing to the local instance MUST be skipped
- AND no HTTP request MUST be made to the local instance URL

#### Scenario: REQ-FED-001d — Listings without integrationLevel search are excluded
- GIVEN a Listing exists with `integrationLevel: "none"` or without the field
- WHEN federated search is performed
- THEN that Listing's remote endpoint MUST NOT be queried
- AND only listings with `integrationLevel: "search"` MUST be included

### Requirement: FED-002 — Retrieve a single publication by ID from local or federated sources

The `GET /api/federation/publications/{id}` endpoint MUST retrieve a publication by its UUID from either the local catalog or remote federated sources.

#### Scenario: REQ-FED-002 — Publication found locally
- GIVEN a publication with UUID "pub-local-001" exists in a local catalog
- WHEN a GET request is made to `/api/federation/publications/pub-local-001`
- THEN `PublicationService.getFederatedPublication("pub-local-001")` is called
- AND the local catalog is searched first
- AND the publication data is returned with status 200

#### Scenario: REQ-FED-002b — Publication found on remote instance
- GIVEN a publication UUID "pub-ams-042" does not exist in any local catalog
- AND the publication exists on a remote directory at "https://opencatalogi.amsterdam.nl"
- WHEN a GET request is made to `/api/federation/publications/pub-ams-042`
- THEN `PublicationService.getFederatedPublication("pub-ams-042")` searches local catalogs first
- AND upon not finding it locally, queries remote directories
- AND the publication from the remote source is returned with status 200

#### Scenario: REQ-FED-002c — Publication not found in any source returns 404
- GIVEN a publication UUID "pub-unknown-999" does not exist locally or on any remote
- WHEN a GET request is made to `/api/federation/publications/pub-unknown-999`
- THEN the response MUST have status 404
- AND the body MUST indicate the publication was not found

### Requirement: FED-003 — Retrieve outgoing relations (uses) with federation support

The `GET /api/federation/publications/{id}/uses` endpoint MUST return all objects that the specified publication references, with federation support.

#### Scenario: REQ-FED-003 — Outgoing relations retrieved from local source
- GIVEN publication "pub-local-001" references 3 other publications via its `uses` field
- WHEN a GET request is made to `/api/federation/publications/pub-local-001/uses`
- THEN `PublicationService.getFederatedUses("pub-local-001")` is called
- AND the 3 referenced publications are returned in the response with status 200

#### Scenario: REQ-FED-003b — Publication with no outgoing relations returns empty list
- GIVEN publication "pub-local-002" has no `uses` references
- WHEN a GET request is made to `/api/federation/publications/pub-local-002/uses`
- THEN the response body MUST contain an empty `results` array
- AND the response MUST have status 200

### Requirement: FED-004 — Retrieve incoming relations (used-by) with federation support

The `GET /api/federation/publications/{id}/used` endpoint MUST return all objects that reference the specified publication, with federation support.

#### Scenario: REQ-FED-004 — Incoming relations retrieved
- GIVEN publication "pub-local-001" is referenced by 2 other publications via their `uses` field
- WHEN a GET request is made to `/api/federation/publications/pub-local-001/used`
- THEN `PublicationService.getFederatedUsed("pub-local-001")` is called
- AND the 2 referencing publications are returned in the response with status 200

#### Scenario: REQ-FED-004b — Publication with no incoming relations returns empty list
- GIVEN publication "pub-local-003" is not referenced by any other publication
- WHEN a GET request is made to `/api/federation/publications/pub-local-003/used`
- THEN the response body MUST contain an empty `results` array
- AND the response MUST have status 200

### Requirement: FED-005 — Retrieve publication attachments from local sources

The `GET /api/federation/publications/{id}/attachments` endpoint MUST return file metadata for all attachments of the specified publication.

#### Scenario: REQ-FED-005 — Attachments returned for local publication
- GIVEN publication "pub-local-001" exists locally with 3 attached files
- WHEN a GET request is made to `/api/federation/publications/pub-local-001/attachments`
- THEN `PublicationService.attachments("pub-local-001")` is called
- AND file metadata for all 3 attachments is returned with status 200
- AND no federated search for attachments is performed (local-only by design)

#### Scenario: REQ-FED-005b — Attachments endpoint is public
- GIVEN no authentication credentials are provided
- WHEN a GET request is made to `/api/federation/publications/pub-local-001/attachments`
- THEN the request MUST succeed (not return 401 or 403)
- AND the attachment metadata MUST be returned

### Requirement: FED-006 — Download publication files from local sources

The `GET /api/federation/publications/{id}/download` endpoint MUST return download information or stream the files for the specified publication.

#### Scenario: REQ-FED-006 — Download available for local publication
- GIVEN publication "pub-local-001" exists locally with downloadable files
- WHEN a GET request is made to `/api/federation/publications/pub-local-001/download`
- THEN `PublicationService.download("pub-local-001")` is called
- AND the response provides download access to the publication's files
- AND no federated source searching is performed (local-only by design)

#### Scenario: REQ-FED-006b — Download endpoint is public
- GIVEN no authentication credentials are provided
- WHEN a GET request is made to `/api/federation/publications/pub-local-001/download`
- THEN the request MUST succeed (not return 401 or 403)
- AND the download response MUST be returned

### Requirement: FED-007 — All federation endpoints must be public (no auth required)

All six federation endpoints MUST be accessible without any authentication. They MUST carry `@PublicPage`, `@NoCSRFRequired`, and `@NoAdminRequired` annotations.

#### Scenario: REQ-FED-007 — Unauthenticated access to all federation endpoints succeeds
- GIVEN no authentication is provided
- WHEN any of the six federation endpoints is called
- THEN the request MUST succeed (HTTP 2xx or 404 if not found, never 401 or 403)
- AND the response MUST include appropriate data or error messages

#### Scenario: REQ-FED-007b — CSRF token not required
- GIVEN a client makes a cross-site request without a CSRF token
- WHEN any federation endpoint is called
- THEN the request MUST NOT be rejected due to missing CSRF token
- AND `@NoCSRFRequired` annotation MUST be present on all six methods in FederationController

### Requirement: FED-008 — Federation aggregation uses async HTTP requests to remote directories

Remote directory endpoints MUST be queried in parallel using async HTTP to minimize latency. Individual remote failures MUST NOT block the overall response.

#### Scenario: REQ-FED-008 — Multiple remote directories queried in parallel
- GIVEN 3 remote directory Listings are configured with `integrationLevel: "search"`
- WHEN `getAggregatedPublications()` is called
- THEN all 3 remote endpoints MUST be queried simultaneously via `GuzzleHttp\Promise\Utils::settle()`
- AND the total federation request time MUST NOT be the sum of individual request times

#### Scenario: REQ-FED-008b — Remote failure does not block response
- GIVEN 3 remote directories are configured and 1 returns an HTTP error or times out
- WHEN `getAggregatedPublications()` is called
- THEN results from the 2 successful remotes MUST still be included in the response
- AND the failed remote MUST NOT cause a 500 error on the federation endpoint
- AND `settle()` (not `all()`) MUST be used so partial failures are handled gracefully

### Requirement: FED-009 — Directory listings provide the directory URLs for remote instances

Listing objects stored in the local OpenCatalogi instance MUST provide the URLs used to query remote OpenCatalogi directories during federation.

#### Scenario: REQ-FED-009 — DirectoryService provides remote listing URLs
- GIVEN a Listing with `url: "https://catalogus.rotterdam.nl"` is stored locally
- WHEN `getAggregatedPublications()` fetches remote sources via DirectoryService
- THEN the Listing's URL is used to construct the remote publication endpoint
- AND the remote endpoint is queried for publications

#### Scenario: REQ-FED-009b — Empty directory returns only local results
- GIVEN no Listings are configured in the local directory
- WHEN `getAggregatedPublications()` is called
- THEN only local catalog publications MUST be returned
- AND no remote HTTP requests MUST be made

### Requirement: FED-010 — Listings with `integrationLevel: "search"` are included in federated search

Only Listing objects explicitly configured with `integrationLevel: "search"` MUST be included in federation aggregation.

#### Scenario: REQ-FED-010 — Only search-level listings are queried
- GIVEN Listing A has `integrationLevel: "search"` and `default: true`
- AND Listing B has `integrationLevel: "none"`
- AND Listing C has `integrationLevel: "search"` but `default: false`
- WHEN `getAggregatedPublications()` is called
- THEN only Listing A MUST be queried as a remote source
- AND Listing B and C MUST be excluded from the federation query

### Requirement: FED-011 — Sort merged results by relevance score (`_score`)

After merging results from all sources, the combined result array MUST be sorted by `_score` (relevance score) in descending order.

#### Scenario: REQ-FED-011 — Merged results sorted by score
- GIVEN local search returns 10 results with `_score` values between 0.9 and 0.5
- AND a remote directory returns 5 results with `_score` values between 0.8 and 0.3
- WHEN results are merged
- THEN all 15 results MUST be combined into a single array
- AND the array MUST be sorted by `_score` descending using `usort()`
- AND the top results MAY interleave local and remote items based on score

#### Scenario: REQ-FED-011b — Results without score are handled
- GIVEN some publications from remote sources do not include a `_score` field
- WHEN results are sorted
- THEN publications without `_score` MUST be sorted to the end (treated as score 0)
- AND no PHP error MUST occur during sorting

### Requirement: FED-012 — All federation publication endpoints have corresponding routes in routes.php

All six federation endpoint methods MUST have corresponding route entries in `appinfo/routes.php` using the `federation#` naming convention.

#### Scenario: REQ-FED-012 — All six routes registered
- GIVEN the `appinfo/routes.php` file
- WHEN the routes array is inspected
- THEN `federation#publications` MUST be registered at `GET /api/federation/publications`
- AND `federation#publication` MUST be registered at `GET /api/federation/publications/{id}`
- AND `federation#publicationUses` MUST be registered at `GET /api/federation/publications/{id}/uses`
- AND `federation#publicationUsed` MUST be registered at `GET /api/federation/publications/{id}/used`
- AND `federation#publicationAttachments` MUST be registered at `GET /api/federation/publications/{id}/attachments`
- AND `federation#publicationDownload` MUST be registered at `GET /api/federation/publications/{id}/download`

## MODIFIED Requirements

_None — this is a new capability._

## REMOVED Requirements

_None._

## Current Implementation Status

- **Fully implemented**: All 12 requirements (FED-001 through FED-012) are implemented.
- **What exists in FederationController**:
  - All 6 endpoint methods with correct Nextcloud annotations (`@PublicPage`, `@NoCSRFRequired`, `@NoAdminRequired`)
  - Error handling with `try/catch` returning 500 on unexpected failures
  - Thin delegation to `PublicationService` for all business logic
- **What exists in PublicationService**:
  - `getAggregatedPublications()` — local + remote parallel aggregation, facet merging, `_score` sorting
  - `getFederatedPublication()` — local-first lookup with remote fallback
  - `getFederatedUses()` — outgoing relation lookup with federation support
  - `getFederatedUsed()` — incoming relation lookup with federation support
  - `attachments()` — local file metadata (no remote federation)
  - `download()` — local file download (no remote federation)
- **What exists in routes.php**: All 6 routes registered at lines 98–103.
- **Key implementation notes**:
  - `GuzzleHttp\Promise\Utils::settle()` used for async parallel HTTP
  - Local instance URLs are skipped to prevent self-referential loops
  - Facet bucket counts summed across sources for the same `_id`

## Dependencies

- **PublicationService** — `getAggregatedPublications()`, `getFederatedPublication()`, `getFederatedUses()`, `getFederatedUsed()`, `attachments()`, `download()`; handles all federation logic, async HTTP, facet merging, and result sorting
- **DirectoryService** — provides Listing data (URLs, integrationLevel) for remote instances
- **Listing objects** — objects with `integrationLevel: "search"` determine which remote sources are included in federation
- **GuzzleHttp** — async HTTP client (`GuzzleHttp\Promise\Utils::settle()`) for parallel requests to remote directories
- **ObjectService** (via OpenRegister) — local catalog queries for publications
