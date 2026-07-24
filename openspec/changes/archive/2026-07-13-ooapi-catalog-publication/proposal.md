---
kind: mixed
depends_on: []
---

# Proposal: ooapi-catalog-publication

## Why

Add a public **OOAPI 5.0 (Open Onderwijs API)** catalog-publication channel to
OpenCatalogi: read-only `organizations` / `programs` / `courses` / `offerings`
resource endpoints, per catalog, so an OpenCatalogi instance can act as the
SURFeduhub-facing course-catalog surface for an educational institution. This
is the opencatalogi-side half of a cross-repo contract that is **already
written and valid** on the scholiq side —
`scholiq/openspec/changes/delegate-ooapi-to-opencatalogi/` — which resolved a
self-contradiction in scholiq's specs (`course-management` claimed Scholiq
itself would serve `/ooapi/v5/*`; `data-exchange` forbade exactly that) by
deciding: **scholiq supplies source objects and a `DataExchangeJob`
(`target: ooapi-catalog`); OpenConnector hosts the mapping/adapter; OpenCatalogi
owns the public endpoint.** This change builds OpenCatalogi's third of that
split. It follows the exact channel pattern already shipped for DCAT-AP-NL
(`dcat-ap-harvest`) and schema.org (`structured-data-discoverability`): a
thin, read-only rendering layer with schema-driven mapping, no bespoke query
logic, and federation-directory discovery — extended, not duplicated, per
hydra ADR-022.

Evidence for the "who serves the bytes" split:

- **Story 9789** (`catalog-publish-ooapi`, priority critical): the course
  catalog exposed via Open Onderwijs API — described from the user want, not
  prescribing who serves the bytes.
- **Insight 1031** ("Cross-app leaf: OOAPI 5.0 course-catalog publication
  belongs in opencatalogi"): "opencatalogi already owns public catalog
  publication (DCAT-AP experience) … scholiq contributes the schema mapping,
  opencatalogi the publication surface, faceting and public API."
- **`nl_standards` 520** (OOAPI 5.0, SURF): "opencatalogi should own the
  public catalog endpoint … openconnector can host the OOAPI adapter; scholiq
  supplies the source objects."
- **`nl_standards` 521** (RIO, DUO/Edustandaard): school-structure objects
  (programmes, cohorts) should be able to carry RIO `opleidingseenheid` /
  `aangeboden opleiding` identifiers when the institution has them.
- Without this, scholiq's `course-management` spec has a resolved *contract*
  but no consumer — the catalog-publication half of the promise (SURFeduhub
  participation) does not exist anywhere in the fleet. OpenCatalogi is the
  only app in the fleet that already operates a public, multi-tenant,
  catalog-scoped, standards-driven publication surface (DCAT-AP-NL, schema.org
  JSON-LD) — OOAPI 5.0 is the same shape of problem (structured education
  data → a national/sector standard's wire format) applied to a new domain.

## What Changes

- Public, CORS-enabled OOAPI 5.0 resource endpoints, scoped per catalog:
  `GET /api/catalogs/{catalogSlug}/ooapi/v5/organizations[/{id}]`,
  `.../programs[/{id}]`, `.../courses[/{id}]`,
  `.../courses/{courseId}/offerings` and `.../offerings/{id}`.
- `organization` resource: **rendered live** from OpenCatalogi's existing
  Organisation object (already used for DCAT publisher / PUB-CON-001
  contacts) via a new `x-ooapi` schema annotation — no new storage.
- `program` / `course` / `offering` resources: **materialized** OR objects,
  written by an OpenConnector `ooapi-catalog` Synchronization consuming
  scholiq's `DataExchangeJob` (`target: ooapi-catalog`) and mapping
  `Course → course`, `Programme → program`, `Cohort → offering` per the
  scholiq contract's field-mapping table — then rendered by OpenCatalogi
  exactly like every other public surface (OR object-search, no bespoke
  query layer).
- Schema-driven `x-ooapi` mapping annotation (mirrors `x-dcat`/`x-schema-org`)
  so no PHP file hardcodes the OOAPI 5.0 field shape.
- RIO `opleidingseenheid` / `aangeboden opleiding` identifier passthrough
  when present on the source object, omitted otherwise (matches the scholiq
  contract's nullable RIO keying).
- Per-catalog `hasOoapi` enable toggle + federation-directory
  `ooapiEndpoint` advertisement (mirrors `hasDcat` / `dcatEndpoint`,
  DCAT-009/DCAT-010).
- Consumer-credential authenticated access (**not** anonymous-public like
  DCAT/schema.org — see design.md D3) as an MVP simplification; admin
  settings surface to manage it.

## Impact

- **Specs**: new capability `ooapi-catalog-publication` (ADDED, OOAPI-001..010).
  No MODIFIED deltas to `catalogs`/`federation`/`admin-settings` — the
  `hasOoapi`/`ooapiEndpoint` fields ride the same additive-field pattern
  `dcat-ap-harvest` already used for `hasDcat`/`dcatEndpoint` (no JSON-schema
  file edit required), following that precedent exactly.
- **Code**: new `course`/`program`/`offering` OR schemas (dedicated `ooapi`
  register), `x-ooapi` annotation on those plus the existing Organisation
  schema, `OoapiMappingService`, `OoapiController` + routes, admin-settings
  additions (toggle, register/schema config, consumer-credential management,
  validation action), `DirectoryService` projection extension.
- **Out of scope (consumed or filed elsewhere, not built here)**:
  - **The OpenConnector `ooapi-catalog` Synchronization/mapping itself** — a
    sibling change in `openconnector`, tracked as a filed issue referencing
    this proposal and the scholiq field-mapping table. This change only
    defines the OR schema/register **shape** OpenConnector's Synchronization
    target writes into, and the read/serve surface over it.
  - **Scholiq-side changes** — already done; see
    `scholiq/openspec/changes/delegate-ooapi-to-opencatalogi/`. Not modified
    here.
  - **Full SURFconext OAuth2 client-credentials federation** — the MVP auth
    gate is a simple consumer credential (existing OR schema authorization /
    NC app-password pattern); the real SURFconext broker integration is
    out of scope for this M-sized change and is filed as a follow-up issue
    (also blocked on the fleet's credential-broker catalogue work).
  - **Object storage, publication state, RBAC, search** — owned by
    OpenRegister (hydra ADR-022).
  - **Visual portal rendering of OOAPI data** — external SURFeduhub consumers.

## References

- hydra ADR-022 — apps consume OpenRegister abstractions.
- OOAPI 5.0 (Open Onderwijs API, SURF/Edustandaard) — `organizations`,
  `programs`, `courses`, `offerings` resources.
- RIO (Register Instellingen en Opleidingen, DUO/Edustandaard).
- `scholiq/openspec/changes/delegate-ooapi-to-opencatalogi/proposal.md`,
  `design.md`, `specs/course-management/spec.md`,
  `specs/data-exchange/spec.md` — the cross-repo contract this change
  implements the opencatalogi side of.
- Existing specs: `dcat-ap-harvest` (the channel pattern this change
  mirrors), `structured-data-discoverability` (the second precedent for the
  same pattern), `publications`/`catalogs` (public API + catalog scoping),
  `federation` (directory/listing machinery extended, not duplicated),
  `admin-settings`, `opencatalogi-adopt-or-abstractions`
  (`RegisterResolverService`, no raw SQL, admin-config inventory rules).
- `openconnector/openspec/architecture/adr-005-source-synchronization-contract-triad.md`,
  `adr-002-mapping-rule-engine-stays-app-local.md`,
  `openconnector/openspec/specs/synchronization-engine/spec.md` — the
  existing OpenConnector mechanism this change's materialization side rides
  on (`Synchronization` target `{registerId}/{schemaId}` →
  `updateTargetOpenRegister()`, Twig/JSON-Logic mapping).
