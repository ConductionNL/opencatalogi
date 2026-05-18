---
status: pr-created
---

# Design: deelnames-gebruik

## Architecture Overview

See specs/deelnames-gebruik/spec.md for detailed requirements and scenarios.

## Implementation

Two new PHP classes implement the deelnames gebruik feature:

### lib/Service/ViewService.php

Performs two-phase retrieval of gebruiksobjecten:

1. **Phase 1 — owned gebruik**: `searchObjectsPaginated()` with standard RBAC. Queries the
   voorzieningen register / gebruik schema (configurable via `gebruik_register` and `gebruik_schema`
   app config keys added to `SettingsService::$objectTypes`).

2. **Phase 2 — deelnames gebruik**: same ObjectService call but with `_rbac: false` and
   `_multitenancy: false`, filtered on `deelnemers` containing the organization UUID. Returns up
   to 1000 results (no default pagination limit).

Deduplication removes any deelnames entry whose `id` already appears in the owned set. Deelnames
nodes are annotated with `_type: "deelnames"`, `_sourceOrganization`, and `_sourceOrganizationId`.
Each phase is wrapped in a try/catch so a failure in one does not prevent the other from returning.

### lib/Controller/ViewEnrichmentController.php

Public (`@PublicPage @NoCSRFRequired`) GET endpoint at `/api/view-enrichment`.

Query parameters:
- `organization_id` (string, required) — UUID of the organization.
- `include_gebruik` (bool, default false) — include owned gebruiksobjecten.
- `include_deelnames_gebruik` (bool, default false) — include deelnames gebruiksobjecten.

Response:
```json
{
  "organization_id": "...",
  "owned": [...],
  "deelnames": [...],
  "warnings": [...]
}
```

CORS headers are added for cross-origin consumers (softwarecatalog GEMMA view frontend).

### appinfo/routes.php

Two new routes added:
- `OPTIONS /api/view-enrichment` (CORS preflight)
- `GET /api/view-enrichment`

### lib/Service/SettingsService.php

`"gebruik"` added to `objectTypes` in both `getSettings()` and `updateObjectTypeConfiguration()`,
making `gebruik_schema` and `gebruik_register` configurable via the admin settings UI.

## Declarative-vs-imperative decision

`ViewService` is a legitimate imperative service: it performs cross-register querying with
RBAC bypass logic, deduplication, and annotation that cannot be expressed as an
`x-openregister-*` schema extension. The two-phase RBAC pattern is a domain-specific runtime
concern, not a schema metadata concern.

## Test coverage

- `tests/Unit/Service/ViewServiceTest.php` — 15 scenarios covering two-phase retrieval,
  deduplication, annotation, flag guards, graceful error handling, and config reads.
- `tests/Unit/Controller/ViewEnrichmentControllerTest.php` — 9 scenarios covering CORS,
  parameter validation, success cases, and warning propagation.
