# Federation

## Problem

OpenCatalogi instances deployed by different government organizations are siloed — each organization's publications are only discoverable on their own instance. There is no mechanism for a user to search across the entire decentralized network of OpenCatalogi catalogs in a single query. This prevents the cross-organizational transparency and discoverability that WOO mandates: citizens and administrators must know which municipality runs which instance and visit each separately.

A federation layer is needed that aggregates publications from both local catalogs and external OpenCatalogi instances into a single unified search interface, forming the backbone of the decentralized catalog network.

## Proposed Solution

Implement the Federation feature following the detailed specification. Key requirements include:
- Requirement: List all publications from local and federated sources with merged pagination
- Requirement: Retrieve a single publication by ID from local or federated sources
- Requirement: Retrieve outgoing relations (uses) with federation support
- Requirement: Retrieve incoming relations (used-by) with federation support
- Requirement: Retrieve publication attachments from local or federated sources
- Requirement: Download publication files from local or federated sources
- Requirement: All federation endpoints must be public (no auth required)
- Requirement: Federation aggregation uses async HTTP requests to remote directories
- Requirement: Listings with `integrationLevel: "search"` are included in federated search
- Requirement: Sort merged results by relevance score (`_score`)

## Scope

This change covers all requirements defined in the federation specification (FED-001 through FED-012).

## Success Criteria

- Federated publication list returns merged results from local and all configured remote sources
- Single publication lookup searches remote directories when not found locally
- Outgoing and incoming relation endpoints aggregate across federation
- Attachments and download endpoints serve local publication files publicly
- All six federation endpoints accessible without authentication
- Async parallel HTTP used for remote directory queries (GuzzleHttp settle)
- Facets from all sources are merged with summed bucket counts
- Results sorted by `_score` descending across local and remote items
