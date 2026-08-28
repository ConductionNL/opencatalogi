---
status: done
or_dep: outbound-webhook-policy
audit_ref: .claude/audit-2026-05-03/04-hardcoded.md
---

# Federation

> **OR webhook retry policy citation (Phase 8):** This spec is updated
> as part of `opencatalogi-adopt-or-abstractions` (Phase 8). The
> app-local retry constants (`BroadcastService::MAX_RETRIES = 3`,
> `REQUEST_TIMEOUT = 30`) are promoted to admin-config keys (see
> [admin-settings/spec.md](../admin-settings/spec.md)) and the retry/backoff
> schedule is delegated to OR's outbound webhook policy. opencatalogi MUST
> NOT re-derive backoff maths. See the REMOVED section and Breaking Changes.
>
> Upstream dependency: OR outbound webhook retry policy.

## Purpose

@e2e exclude pure API/backend spec — all scenarios test server-side federation
aggregation logic (async Guzzle HTTP, facet merging, result sorting) with no
browser-observable UI surface; covered by Newman API tests instead.

Federation enables opencatalogi to aggregate publications from both local
catalogs and external (federated) OpenCatalogi instances into a unified search
interface. After Phase 8:

- Retry attempts, timeout, and dead-letter behaviour for outbound federation
  broadcasts MUST follow OR's outbound webhook retry policy. opencatalogi
  MUST NOT hard-code retry counts, timeout values, jitter, or backoff
  intervals. These are read from admin-config keys
  (`broadcast_max_retries`, `broadcast_request_timeout`) per
  [admin-settings/spec.md](../admin-settings/spec.md).
- The search aggregation (fanning out `zoeken-filteren` calls per catalog
  context, merging results and facets) remains a legitimate in-app
  orchestration with no OR leaf equivalent.

## ADDED Requirements

### Requirement: federation outbound retry follows OR webhook retry policy (FED-OR-001)

When an outbound federation broadcast (e.g. broadcasting this instance's
directory to remote OpenCatalogi instances) fails transiently, the retry
schedule — attempt count, initial delay, backoff multiplier, jitter, and
dead-letter threshold — MUST conform to OR's outbound webhook retry policy.

opencatalogi reads the operator-tunable parameters via admin-config:
- `broadcast_max_retries` (default: 3) — maximum retry attempts
- `broadcast_request_timeout` (default: 30 s) — HTTP request timeout per attempt

These values MUST be read from `IAppConfig`, NOT from PHP class constants.
See [admin-settings/spec.md](../admin-settings/spec.md) for the full inventory.

#### Scenario: federation outbound calls follow OR's retry policy

- **GIVEN** a federation push fails transiently,
- **WHEN** the retry behaviour fires,
- **THEN** the attempt count is bounded by `broadcast_max_retries` (read
  from `IAppConfig`),
- **AND** opencatalogi does NOT carry app-local retry constants,
- **AND** the retry schedule (delay, jitter, dead-letter) matches the OR
  outbound webhook policy.

#### Scenario: admin tunes retry parameters

- **GIVEN** an admin sets `broadcast_max_retries = 5`,
- **WHEN** a broadcast fails,
- **THEN** the service attempts up to 5 retries,
- **AND** does NOT rely on a PHP class constant for the attempt ceiling.

### Requirement: dead-letter behaviour for permanently failing federation pushes (FED-OR-002)

When a federation push exceeds `broadcast_max_retries` consecutive failures,
the push MUST be moved to a dead-letter state per OR's outbound webhook policy.
opencatalogi MUST NOT define its own dead-letter logic.

#### Scenario: dead-letter threshold reached

- **GIVEN** a federation push has failed `broadcast_max_retries` times,
- **WHEN** the next retry fires,
- **THEN** the push is marked dead-letter per OR's policy,
- **AND** opencatalogi does NOT apply a different or longer retry sequence.

## Requirements

### Requirement: List all publications from local and federated sources (FED-001)

The system MUST list all publications from local and federated sources with
merged pagination. Federated results are obtained by calling OR's
`zoeken-filteren` per catalog context (see [search/spec.md](../search/spec.md)).

**Priority:** Must **Status:** Implemented

#### Scenario: list publications across sources

- **GIVEN** local catalogs and one or more federated instances are configured,
- **WHEN** a client requests the publication list,
- **THEN** the response MUST include publications from local and federated sources with merged pagination.

### Requirement: Retrieve a single publication by ID from local or federated sources (FED-002)

The system MUST retrieve a single publication by ID from either a local catalog or a federated source.

**Priority:** Must **Status:** Implemented

#### Scenario: retrieve a single publication

- **GIVEN** a publication ID that exists locally or on a federated instance,
- **WHEN** a client requests that publication,
- **THEN** the system MUST return the publication regardless of which source holds it.

### Requirement: Retrieve outgoing relations (uses) with federation support (FED-003)

The system MUST retrieve a publication's outgoing relations (uses), resolving related publications across local and federated sources.

**Priority:** Must **Status:** Implemented

#### Scenario: retrieve outgoing relations

- **GIVEN** a publication that references other publications,
- **WHEN** a client requests its outgoing relations,
- **THEN** the system MUST return the used publications, including those on federated instances.

### Requirement: Retrieve incoming relations (used-by) with federation support (FED-004)

The system MUST retrieve a publication's incoming relations (used-by), resolving referencing publications across local and federated sources.

**Priority:** Must **Status:** Implemented

#### Scenario: retrieve incoming relations

- **GIVEN** a publication referenced by other publications,
- **WHEN** a client requests its incoming relations,
- **THEN** the system MUST return the referencing publications, including those on federated instances.

### Requirement: Retrieve publication attachments from local or federated sources (FED-005)

The system MUST retrieve a publication's attachments from either a local catalog or a federated source.

**Priority:** Must **Status:** Implemented

#### Scenario: retrieve attachments

- **GIVEN** a publication with attachments held locally or on a federated instance,
- **WHEN** a client requests its attachments,
- **THEN** the system MUST return the attachment list from the holding source.

### Requirement: Download publication files from local or federated sources (FED-006)

The system MUST allow downloading a publication's files from either a local catalog or a federated source.

**Priority:** Must **Status:** Implemented

#### Scenario: download a federated file

- **GIVEN** a publication file held on a local or federated source,
- **WHEN** a client requests the download,
- **THEN** the system MUST stream the file from the holding source.

### Requirement: All federation endpoints must be public (FED-007)

All federation endpoints MUST be public (`@PublicPage`, `@NoCSRFRequired`,
`@NoAdminRequired`).

**Priority:** Must **Status:** Implemented

#### Scenario: anonymous access to federation endpoints

- **GIVEN** an unauthenticated client,
- **WHEN** it calls any federation endpoint,
- **THEN** the request MUST be served (endpoints declare `@PublicPage`, `@NoCSRFRequired`, `@NoAdminRequired`).

### Requirement: Federation aggregation uses async HTTP requests to remote directories (FED-008)

Federation aggregation SHOULD use async HTTP requests (GuzzleHttp promises) to remote directories, and MUST aggregate the responses into a single result set.

**Priority:** Should **Status:** Implemented

#### Scenario: parallel aggregation across directories

- **GIVEN** multiple remote directories are configured,
- **WHEN** a federated query runs,
- **THEN** the aggregation MUST issue the remote calls in parallel (async GuzzleHttp promises) and merge their responses.

### Requirement: Directory listings provide the directory URLs for remote instances (FED-009)

Directory listings MUST provide the directory URLs used to reach remote OpenCatalogi instances.

**Priority:** Must **Status:** Implemented

#### Scenario: directory listing exposes remote URLs

- **GIVEN** remote instances are registered in the directory,
- **WHEN** a client reads a directory listing,
- **THEN** each entry MUST expose the directory URL for the remote instance.

### Requirement: Listings with `integrationLevel: "search"` included in federated search (FED-010)

Listings whose `integrationLevel` is `"search"` MUST be included in the federated search fan-out.

**Priority:** Should **Status:** Implemented

#### Scenario: search-level listing participates in federated search

- **GIVEN** a listing with `integrationLevel: "search"`,
- **WHEN** a federated search runs,
- **THEN** that listing MUST be queried as part of the search fan-out.

### Requirement: Sort merged results by relevance score (`_score`) (FED-011)

Merged federated results MUST be sorted by relevance score (`_score`) in descending order.

**Priority:** Should **Status:** Implemented

#### Scenario: merged results ordered by score

- **GIVEN** results returned from multiple sources with `_score` values,
- **WHEN** the results are merged,
- **THEN** they MUST be ordered by descending `_score`.

### Requirement: All federation publication endpoints have corresponding routes (FED-012)

All federation publication endpoints MUST have corresponding routes in
`appinfo/routes.php`.

**Priority:** Must **Status:** Implemented

#### Scenario: federation endpoints are routed

- **GIVEN** the documented federation publication endpoints,
- **WHEN** the app's routes are loaded,
- **THEN** each endpoint MUST have a corresponding entry in `appinfo/routes.php`.

## REMOVED Requirements

| ID | Title | Reason removed |
|----|-------|----------------|
| (BroadcastService class constants) | `BroadcastService::MAX_RETRIES = 3` and `REQUEST_TIMEOUT = 30` as hardcoded PHP constants | REMOVED — re-derives retry/timeout constants that the OR outbound webhook policy owns. Promoted to admin-config keys `broadcast_max_retries` / `broadcast_request_timeout` per ADR-022 and `.claude/audit-2026-05-03/04-hardcoded.md`. |
| (app-local backoff maths) | Any PHP code in BroadcastService computing delay, jitter, or backoff interval | REMOVED — re-implements OR's outbound webhook retry schedule; consume OR per ADR-022. |

## Breaking Changes

| Breaking change | Old behaviour | New behaviour |
|---|---|---|
| `BroadcastService::MAX_RETRIES` constant removed | PHP class constant `3` | Read from `IAppConfig::getValueInt('broadcast_max_retries', 3)`; code reading the constant directly will throw |
| `BroadcastService::REQUEST_TIMEOUT` constant removed | PHP class constant `30` | Read from `IAppConfig::getValueInt('broadcast_request_timeout', 30)`; code reading the constant directly will throw |
| Backoff maths removed | App-local retry delay computation | Delegated to OR outbound webhook policy; delay/jitter/dead-letter managed by OR |

## API Endpoints

| Method | Endpoint | Description |
|--------|----------|-------------|
| GET | `/api/federation/publications` | List publications from all sources (local + federated) — delegates to `zoeken-filteren` |
| GET | `/api/federation/publications/{id}` | Get single publication from any source |
| GET | `/api/federation/publications/{id}/uses` | Get outgoing relations with federation |
| GET | `/api/federation/publications/{id}/used` | Get incoming relations with federation |
| GET | `/api/federation/publications/{id}/attachments` | Get attachments from any source |
| GET | `/api/federation/publications/{id}/download` | Download files from any source |

All six endpoints use `@PublicPage`, `@NoCSRFRequired`, `@NoAdminRequired` annotations.

## References

- OR outbound webhook retry policy (upstream dependency)
- `.claude/audit-2026-05-03/04-hardcoded.md` (Stream 4 — hardcoded constants rationale)
- `openspec/changes/opencatalogi-adopt-or-abstractions/` (Phase 8 implementation change)
- `openspec/specs/admin-settings/spec.md` (broadcast_max_retries / broadcast_request_timeout inventory)
- `openspec/specs/search/spec.md` (zoeken-filteren delegation for federated search)
- ADR-022 — Apps consume OR abstractions
