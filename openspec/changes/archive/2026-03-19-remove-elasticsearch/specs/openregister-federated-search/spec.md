## ADDED Requirements

### Requirement: Local search delegates to OpenRegister
The system SHALL perform local search by calling OpenRegister's `ObjectService::searchObjectsPaginated()` instead of maintaining its own search backend. OpenCatalogi SHALL NOT bundle or depend on Elasticsearch, Solr, or any other search engine library.

#### Scenario: Local search returns results from OpenRegister
- **WHEN** a search request is made to `/api/search` with query parameters
- **THEN** the system calls OpenRegister's `ObjectService::searchObjectsPaginated()` with the search parameters and returns the results in the standard response format `{results, facets, count, limit, page, pages, total}`

#### Scenario: Local search with filters
- **WHEN** a search request includes filter parameters (e.g., `_search=keyword`, `category=governance`)
- **THEN** the system passes these filters to OpenRegister's search API and returns only matching results

#### Scenario: Local search with pagination
- **WHEN** a search request includes `_limit` and `_page` parameters
- **THEN** the system passes pagination to OpenRegister and returns the correct page of results with accurate `pages` and `total` counts

### Requirement: Federated search combines local and remote results
The system SHALL perform federated search by combining local OpenRegister results with async HTTP queries to remote catalog directories. The federation logic SHALL merge results and facets from all sources.

#### Scenario: Federated search with remote directories
- **WHEN** a search request is made and the directory contains remote catalog entries with `default=true`
- **THEN** the system queries each remote catalog's search endpoint asynchronously, merges the remote results with local OpenRegister results, and returns the combined result set

#### Scenario: Federated search with no remote directories
- **WHEN** a search request is made and the directory is empty or contains no default remote catalogs
- **THEN** the system returns only local OpenRegister results without making any remote HTTP requests

#### Scenario: Remote directory request failure
- **WHEN** a remote catalog's search endpoint fails or times out during federated search
- **THEN** the system returns local results plus results from any successful remote queries, without failing the entire search operation

### Requirement: Facet merging across federated sources
The system SHALL merge facets (aggregations) from local OpenRegister results and remote directory results into a unified facet set. Counts for matching facet keys SHALL be summed.

#### Scenario: Facets merged from multiple sources
- **WHEN** local results return facets `{category: [{_id: "governance", count: 5}]}` and a remote source returns `{category: [{_id: "governance", count: 3}]}`
- **THEN** the merged facets contain `{category: [{_id: "governance", count: 8}]}`

#### Scenario: Unique facets from different sources
- **WHEN** local results return facets for `category` and a remote source returns facets for `theme` (not present locally)
- **THEN** the merged result includes both `category` and `theme` facets

### Requirement: No Elasticsearch dependency
The system SHALL NOT include `elasticsearch/elasticsearch` or any Elasticsearch client library in its composer dependencies. The system SHALL NOT contain any PHP classes that directly interact with Elasticsearch or Solr.

#### Scenario: Composer dependencies exclude Elasticsearch
- **WHEN** inspecting `composer.json`
- **THEN** no Elasticsearch, Solr, or search engine client libraries are listed in `require` or `require-dev`

#### Scenario: No ES service classes exist
- **WHEN** inspecting the `lib/Service/` directory
- **THEN** no files named `ElasticSearch*.php` or `Solr*.php` exist

### Requirement: No search backend configuration in frontend
The system SHALL NOT expose search backend configuration (Elasticsearch location, API key, index name, useElastic, useMongo) in the frontend application. Search backend configuration is an OpenRegister concern.

#### Scenario: Configuration entity has no search backend flags
- **WHEN** inspecting the frontend `Configuration` TypeScript entity
- **THEN** no properties for `useElastic`, `useMongo`, `elasticLocation`, `elasticKey`, or `elasticIndex` exist

#### Scenario: Settings UI has no search backend fields
- **WHEN** viewing the OpenCatalogi admin settings page
- **THEN** no input fields for Elasticsearch or Solr configuration are displayed

### Requirement: Search API response contract preserved
The `/api/search` endpoint SHALL return responses in the same format as before: `{results, facets, count, limit, page, pages, total}`. Consumers of this API SHALL NOT need to change their integration.

#### Scenario: API response format unchanged
- **WHEN** a client sends a GET request to `/api/search?_search=test`
- **THEN** the response body contains `results` (array), `facets` (object), `count` (integer), `limit` (integer), `page` (integer), `pages` (integer), and `total` (integer)

### Requirement: Self-exclusion from federated search
The system SHALL exclude its own search endpoint from federated queries to prevent infinite loops.

#### Scenario: Own endpoint excluded
- **WHEN** the directory contains an entry whose `search` URL matches the local instance's search endpoint
- **THEN** the system skips that directory entry and does not send an HTTP request to itself
