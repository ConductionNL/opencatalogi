---
status: reviewed
---

# Federation

## Purpose

Federation enables OpenCatalogi to aggregate publications from both local catalogs and external (federated) OpenCatalogi instances into a unified search interface. The federation endpoints mirror the publication API but include aggregation logic that queries remote directories, merges results, and provides a single response to the frontend. This is the backbone of the decentralized catalog network where multiple government organizations can share and discover each other's publications.

## Requirements

### Requirement: List all publications from local and federated sources with merged pagination (FED-001)
The system MUST list all publications from local and federated sources with merged pagination.

**Priority:** Must **Status:** Implemented

### Requirement: Retrieve a single publication by ID from local or federated sources (FED-002)
The system MUST retrieve a single publication by ID from local or federated sources.

**Priority:** Must **Status:** Implemented

### Requirement: Retrieve outgoing relations (uses) with federation support (FED-003)
The system MUST retrieve outgoing relations (uses) with federation support.

**Priority:** Must **Status:** Implemented

### Requirement: Retrieve incoming relations (used-by) with federation support (FED-004)
The system MUST retrieve incoming relations (used-by) with federation support.

**Priority:** Must **Status:** Implemented

### Requirement: Retrieve publication attachments from local or federated sources (FED-005)
The system MUST retrieve publication attachments from local or federated sources.

**Priority:** Must **Status:** Implemented

### Requirement: Download publication files from local or federated sources (FED-006)
The system MUST allow downloading publication files from local or federated sources.

**Priority:** Must **Status:** Implemented

### Requirement: All federation endpoints must be public (no auth required) (FED-007)
All federation endpoints MUST be public (no auth required).

**Priority:** Must **Status:** Implemented

### Requirement: Federation aggregation uses async HTTP requests to remote directories (FED-008)
Federation aggregation SHOULD use async HTTP requests to remote directories.

**Priority:** Should **Status:** Implemented

### Requirement: Directory listings provide the directory URLs for remote instances (FED-009)
Directory listings MUST provide the directory URLs for remote instances.

**Priority:** Must **Status:** Implemented

### Requirement: Listings with `integrationLevel: "search"` are included in federated search (FED-010)
Listings with `integrationLevel: "search"` SHOULD be included in federated search.

**Priority:** Should **Status:** Implemented

### Requirement: Sort merged results by relevance score (`_score`) (FED-011)
The system SHOULD sort merged results by relevance score (`_score`).

**Priority:** Should **Status:** Implemented

### Requirement: All federation publication endpoints have corresponding routes in routes.php (FED-012)
All federation publication endpoints MUST have corresponding routes in routes.php.

**Priority:** Must **Status:** Implemented

## Data Model

Federation does not have its own schema. It aggregates data from:
- Local catalogs (via PublicationService)
- Remote OpenCatalogi instances (via DirectoryService and HTTP calls)

The aggregation response follows the same structure as the publications API:

| Field | Type | Description |
|-------|------|-------------|
| results | array | Merged publication objects from local and remote sources |
| total | integer | Combined total count |
| page | integer | Current page number |
| pages | integer | Total pages |
| limit | integer | Items per page |
| offset | integer | Current offset |
| facets | object | Merged facets from all sources |

## API Endpoints

| Method | Endpoint | Description |
|--------|----------|-------------|
| GET | `/api/federation/publications` | List publications from all sources (local + federated) |
| GET | `/api/federation/publications/{id}` | Get single publication from any source |
| GET | `/api/federation/publications/{id}/uses` | Get outgoing relations with federation |
| GET | `/api/federation/publications/{id}/used` | Get incoming relations with federation |
| GET | `/api/federation/publications/{id}/attachments` | Get attachments from any source |
| GET | `/api/federation/publications/{id}/download` | Download files from any source |

All six endpoints are registered in `routes.php` and map to methods on `FederationController`. All endpoints use `@PublicPage`, `@NoCSRFRequired`, `@NoAdminRequired` annotations.

## Federation Implementation Details (Gap 18)

### Aggregation Architecture

The `FederationController` delegates all business logic to `PublicationService`:

| Endpoint | Controller Method | Service Method |
|----------|-------------------|----------------|
| `/api/federation/publications` | `publications()` | `getAggregatedPublications()` |
| `/api/federation/publications/{id}` | `publication()` | `getFederatedPublication()` |
| `/api/federation/publications/{id}/uses` | `publicationUses()` | `getFederatedUses()` |
| `/api/federation/publications/{id}/used` | `publicationUsed()` | `getFederatedUsed()` |
| `/api/federation/publications/{id}/attachments` | `publicationAttachments()` | `attachments()` |
| `/api/federation/publications/{id}/download` | `publicationDownload()` | `download()` |

### Search Flow

The federated publication list uses `PublicationService.getAggregatedPublications()` which:

1. Queries local catalogs for publications via ObjectService
2. When remote directory listings exist with `default: true`:
   - Listings pointing to the local instance are skipped
   - Remote search endpoints are queried via async HTTP (`GuzzleHttp\Promise\Utils::settle()`)
   - Results from all sources are merged and sorted by `_score` (relevance)
   - Facets/aggregations from all sources are merged

### Facet Merging

`PublicationService` merges facets from multiple sources:
- For each aggregation key (e.g., "theme"), bucket items are merged
- Items with the same `_id` have their `count` values summed
- New items from remote sources are added to the aggregation

### Result Sorting

Merged results are sorted by `_score` (relevance score) using `usort()`. This ensures that the most relevant results appear first regardless of which source they came from.

Note: There is no separate `SearchService` or `ElasticSearchService` in the OpenCatalogi codebase. All federation logic lives in `PublicationService`.

## Federation Endpoints for Attachments and Download (Gap 24)

The federation controller provides complete coverage of the publication sub-resources:

### `/api/federation/publications/{id}/attachments`
- Route: `federation#publicationAttachments`
- Calls `PublicationService::attachments($id)`
- Returns file metadata for all attachments of the publication
- Public endpoint (no auth required)

### `/api/federation/publications/{id}/download`
- Route: `federation#publicationDownload`
- Calls `PublicationService::download($id)`
- Returns download information for publication files
- Public endpoint (no auth required)

Both endpoints are fully registered in `routes.php` (lines 98-99) and delegate directly to PublicationService. Unlike the main federation list/detail endpoints, these do NOT include federated source searching -- they only serve local files. This is by design since file content cannot be meaningfully aggregated from remote sources.

## Scenarios

### Scenario: Federated publication list with aggregation
- GIVEN local catalogs contain 50 publications
- AND a remote directory at "https://remote.example.nl" is configured with `integrationLevel: "search"`
- WHEN a GET request is made to `/api/federation/publications?_search=opendata`
- THEN PublicationService.getAggregatedPublications() is called
- AND local results are fetched from all configured catalogs
- AND remote results are fetched via async HTTP GET to the remote directory's publication endpoint
- AND results are merged, deduplicated, and paginated
- AND facets from all sources are merged
- AND a unified response is returned

### Scenario: Get federated single publication
- GIVEN a publication UUID "abc-123" exists on a remote instance
- WHEN a GET request is made to `/api/federation/publications/abc-123`
- THEN PublicationService.getFederatedPublication() first searches local catalogs
- AND if not found locally, queries remote directories
- AND returns the publication from whichever source has it
- AND returns 404 if not found in any source

### Scenario: Federation endpoints are public
- GIVEN no authentication is provided
- WHEN any federation endpoint is called
- THEN the request succeeds (endpoints have @PublicPage, @NoCSRFRequired, @NoAdminRequired)
- AND the response includes appropriate data or error messages

### Scenario: Federated search with result merging
- GIVEN local Elasticsearch returns 10 results with scores 0.9-0.5
- AND a remote directory returns 5 results with scores 0.8-0.3
- WHEN results are merged
- THEN all 15 results are combined
- AND sorted by _score descending
- AND the top results may interleave local and remote items

### Scenario: Federation attachments (local-only)
- GIVEN a publication "abc-123" exists locally with 3 attachments
- WHEN GET `/api/federation/publications/abc-123/attachments` is called
- THEN PublicationService.attachments() is called with the publication ID
- AND local file metadata is returned (no federated search for attachments)

## Dependencies

- **PublicationService** - getAggregatedPublications(), getFederatedPublication(), getFederatedUses(), getFederatedUsed(), attachments(), download(); also handles federated search, async HTTP to remote directories, facet merging, and result sorting
- **DirectoryService** - Provides listing data for remote instances
- **Listings** - Listing objects with `integrationLevel` determine which remote sources to include
- **GuzzleHttp** - Async HTTP client for parallel requests to remote directories
