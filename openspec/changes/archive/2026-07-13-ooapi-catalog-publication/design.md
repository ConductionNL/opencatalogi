# Design: ooapi-catalog-publication

## Context

Verified at HEAD:

- OpenCatalogi already runs two schema-driven, read-only publication
  channels over the same OR object-search path: DCAT-AP-NL
  (`dcat-ap-harvest`, `DcatMappingService`/`DcatService`, `x-dcat` schema
  annotation) and schema.org JSON-LD (`structured-data-discoverability`,
  `x-schema-org` marker). Both are content-negotiated on the *existing*
  public publication/catalog endpoints — they render objects that already
  live in OpenCatalogi's own OR instance as `publication`/`catalog` objects.
- Catalogs already support multi-register/multi-schema scope (CAT-010) and a
  per-catalog boolean feature toggle pattern (`hasWooSitemap` in the JSON
  schema; `hasDcat` as an additive field on the projected listing object,
  per `dcat-ap-harvest` Task 5 — no JSON-schema-file edit was needed for
  that one).
- `opencatalogi-adopt-or-abstractions` establishes: every controller
  resolves its register/schema pair via `RegisterResolverService`
  (`resolvePair($context)`), no raw SQL against OR's magic tables, and every
  new admin-config key is added to the `admin-settings` inventory.
- The scholiq contract (`delegate-ooapi-to-opencatalogi`, already merged)
  commits scholiq to: `Course`/`Programme` (`lifecycle: published`) and
  `Cohort` (a course/programme "run") as the eligible objects; a field
  mapping `Course → course`, `Programme → program`, `Cohort → offering`,
  keyed to RIO `opleidingseenheid`/`aangeboden opleiding` when present; and a
  `publish`/`archive` transition that queues a `DataExchangeJob`
  (`direction: sync`, `target: ooapi-catalog`). Scholiq explicitly does
  **not** serve `/ooapi/v5/*` itself and implements no OOAPI wire protocol.
- OpenConnector's `Source → Synchronization → SynchronizationContract` triad
  (ADR-005) already supports writing into an OpenRegister register/schema as
  a *target* — `targetId` shaped `{registerId}/{schemaId}` branches into
  `updateTargetOpenRegister()` (`lib/Service/SynchronizationService.php`),
  which calls OR's `ObjectService::saveObject()`. Because OR registers are
  addressed by numeric id, not by owning app, this write path is inherently
  cross-app: nothing scopes it to registers OpenConnector itself owns. Field
  transformation uses `MappingService` (Twig + JSON Logic, ADR-002).
  Push-triggered synchronizations (driven by a source-side mutation, not
  just polling) already exist via `triggerFromRelatedObjects`
  (`SynchronizationService::findAllByRelatedObjectTrigger()`).

This means the "leaf" scholiq's insight 1031 describes — scholiq supplies
objects, OpenConnector adapts, OpenCatalogi publishes — is not a new
mechanism to invent; it is an existing, three-repo-spanning pattern with
each piece already precedented in its own app. The only genuinely new work
in OpenCatalogi is: (a) new resource shapes it hasn't served before, and
(b) the register/schema **target** OpenConnector's Synchronization writes
into.

## Architecture overview

```
scholiq: Course/Programme (lifecycle→published) or Cohort
        │  publish/archive transition queues
        ▼
DataExchangeJob(direction: sync, target: ooapi-catalog)      [scholiq — already spec'd, not built here]
        │
        ▼
OpenConnector: Synchronization(source: scholiq, target: {ooapiRegisterId}/{schemaId})
        │  MappingService (Twig + JSON Logic, ADR-002):
        │  Course→course, Programme→program, Cohort→offering,
        │  RIO id passthrough when present
        ▼
updateTargetOpenRegister() → ObjectService::saveObject()      [OpenConnector — filed as a sibling change, not built here]
        │
        ▼
OpenCatalogi-owned OR objects: course / program / offering schemas
(a NEW register OpenCatalogi defines — see D1)
        │  searchObjects / zoeken-filteren, same query path as PUB-003
        ▼
OoapiMappingService  ──  x-ooapi schema annotation (course/program/offering: identity;
                          organization: real field mapping — see D2)
        ▼
OoapiSerializer  ──  OOAPI 5.0 JSON resource shape
        ▼
GET /api/catalogs/{slug}/ooapi/v5/{organizations|programs|courses|offerings}[/{id}]
(consumer-credential authenticated, CORS, per-catalog `hasOoapi` gate)
```

The `organization` resource takes a shorter path: it renders directly from
OpenCatalogi's **existing** Organisation object (already used for DCAT
publisher and the PUB-CON-001 contacts leaf) — no OpenConnector hop, no new
schema, exactly the DCAT/schema.org "render what's already there" pattern.

## Key decisions

### D1 — Transformation seam: materialize course/program/offering, render organization live

This is the central architectural choice this change makes, and it is a
**split** decision rather than a single answer, because the two halves of
the OOAPI surface are not the same kind of object:

- **`organization`** already has a first-class OpenCatalogi representation
  (the Organisation object) living in OpenCatalogi's own OR instance. For
  this resource, OpenCatalogi does exactly what it does for DCAT's
  `foaf:Agent` publisher and schema.org: render the existing object live,
  through a schema annotation, at request time. No new storage.

- **`course`/`program`/`offering`** have **no existing OpenCatalogi
  representation** — their source of truth is scholiq's `Course`/
  `Programme`/`Cohort` objects, which live in a *different Nextcloud
  instance's* OpenRegister (each institution runs its own scholiq
  deployment; OpenCatalogi is the shared/central catalog-publication
  surface, mirroring the real SURFeduhub topology of many institutions
  publishing to one hub). DCAT/schema.org's "render live over
  `searchObjects`" pattern has nothing to render live *from* here — there is
  no cross-instance `ObjectService` call; OR's object-search path only
  reaches objects in the local instance. The scholiq contract already
  chose the bridge mechanism: a `DataExchangeJob` consumed by OpenConnector,
  exactly the pattern already used for BRON/ROD, OSO, Digikoppeling,
  SURFconext attributes, and generic HR sync in scholiq's `data-exchange`
  spec. OpenConnector's `Synchronization` triad already supports writing
  into an arbitrary OR register/schema as a target
  (`updateTargetOpenRegister()`), so materializing scholiq's objects as
  OpenCatalogi-owned `course`/`program`/`offering` OR objects is not new
  mechanism — it is the existing OpenConnector target-write path pointed at
  new, OpenCatalogi-defined schemas.

  Once materialized, these objects are rendered **exactly like every other
  OpenCatalogi public surface**: OR object-search, no bespoke query layer,
  no raw SQL (per `opencatalogi-adopt-or-abstractions`'s "no raw SQL against
  OR storage internals" rule). The divergence from DCAT/schema.org is only
  in *how the object came to exist in OpenCatalogi's OR instance*, not in
  how it is served once it does.

**Rejected alternative — direct cross-instance render.** Have OpenCatalogi's
catalog reference scholiq's registers/schemas directly (extending CAT-010's
multi-register support across instances) and render OOAPI live via an
`x-ooapi` mapping on scholiq's own `Course`/`Programme`/`Cohort` schemas,
skipping OpenConnector entirely. Rejected: OR's `ObjectService`/
`searchObjects` operate against the local database; there is no
cross-Nextcloud-instance object-search call in the platform, and inventing
one here would duplicate exactly the wire-protocol work `data-exchange`
already forbids Scholiq from doing and that OpenConnector already owns for
every other external protocol. It would also silently assume a
single-tenant topology (one scholiq per opencatalogi) that does not match
SURFeduhub's actual many-institutions-to-one-hub shape.

**Rejected alternative — OpenConnector writes final OOAPI JSON blobs.** Have
the Synchronization target write pre-serialized OOAPI 5.0 JSON directly
(e.g. into a single opaque `ooapiPayload` field), and OpenCatalogi just
passes it through. Rejected: this produces an untyped, unsearchable,
unfacetable OR object and reintroduces exactly the "hardcoded shape, not
schema-driven" anti-pattern `x-dcat`/`x-schema-org` were built to avoid —
and it means OpenCatalogi's public endpoint can never apply its own
authorization/visibility rules at the field level. Keeping `course`/
`program`/`offering` as real, typed OpenCatalogi schemas (D2) preserves
OR's searchability and keeps the render layer genuinely a render layer.

### D2 — Two-hop mapping, each owned by the app that hosts that hop

Field translation happens in two places, mirroring the "no PHP hardcoding,
schema-driven mapping" principle already established by `x-dcat` (DCAT-004)
at *each* hop rather than centralizing it in one:

1. **Scholiq → OpenCatalogi-owned schema** (OpenConnector's `MappingService`,
   Twig + JSON Logic, ADR-002): `Course.code/name/level/language → course.*`,
   `Programme.name/code/level/courseIds → program.*`,
   `Cohort.programmeId/courseId/period/academicYear/teacherIds/learnerIds →
   offering.*`, RIO id copied through when the source object has one. This
   is OpenConnector's spec, not modified by this change (see the filed
   sibling issue).
2. **OpenCatalogi-owned schema → OOAPI 5.0 wire shape** (this change's new
   `x-ooapi` annotation, `OoapiMappingService`): for `course`/`program`/
   `offering`, this is effectively an identity mapping — OpenConnector
   already wrote OOAPI-shaped property names — but it is still declared as
   an annotation, not hardcoded PHP, so a schema change never requires a
   PHP release. For `organization`, `x-ooapi` carries a real mapping
   (Organisation's native fields → OOAPI's `organization` resource fields),
   exactly like `x-dcat`'s annotated-schema case.

```json
{
  "x-ooapi": {
    "resource": "course",
    "mapping": { "primaryCode.code": "code", "name": "name", "level": "level" }
  }
}
```

Unannotated schemas in an OOAPI-enabled catalog are simply not offered as an
OOAPI resource (unlike DCAT's conservative default mapping — there is no
sensible default OOAPI shape for an arbitrary schema the way there is a
sensible default "title/description/modified" for DCAT).

### D3 — Consumer-credential auth, not anonymous-public (divergence from DCAT/schema.org)

DCAT-AP-NL and schema.org JSON-LD are deliberately anonymous-public: open
government data and general web indexing. OOAPI 5.0 / SURFeduhub data is
narrower — course-catalog and enrollment-adjacent structural data intended
for a defined set of institutional/sector consumers, not the open web. This
change's MVP auth gate reuses OR's existing schema authorization
(conditional `authorization.read` rules, the same mechanism PUB-015 already
exercises) requiring `authenticated` rather than `group: public`, gated by a
per-catalog consumer credential issued through the existing admin-settings
surface (NC app-password pattern — no new auth framework).

**Explicitly out of scope:** full SURFconext OAuth2 client-credentials
federation. That is real integration work (an OIDC broker relationship with
SURFconext) disproportionate to this M-sized change, and the fleet's
credential-broker catalogue (`decidesk`) does not yet list SURFconext as a
brokered provider. Filed as a follow-up issue; the consumer-credential MVP
is a deliberate, stated simplification, not a silent gap.

### D4 — Visibility mirrors the scholiq publish/archive lifecycle, not a local predicate

Unlike DCAT-003 (a `publicatiedatum <= now` RBAC predicate evaluated at
render time over objects that already exist locally), OOAPI visibility is
governed by **whether the object was materialized at all**: scholiq's
`publish` transition queues the sync that creates it; `archive` queues the
matching unpublish/removal sync (per the scholiq contract). OpenCatalogi's
endpoint therefore needs no bespoke visibility predicate of its own for
`course`/`program`/`offering` — a materialized object is by construction
one scholiq already decided to publish. `organization` keeps the existing
Organisation object's own visibility rule (unchanged).

### D5 — Offering nesting

`offering` (from `Cohort`) carries `courseId`/`programmeId` (per the
scholiq mapping table) and is rendered with a parent link to its `course`;
`GET .../courses/{courseId}/offerings` is a filtered list over the same
`offering` schema, not a separate storage shape.

### D6 — Register/schema layout

`course`/`program`/`offering` get a **new, dedicated register** (not the
existing `publication_register.json`) — SURFeduhub structural data is not a
WOO/open-data "publication" and mixing it into the publication register's
schema list would blur `admin-settings`' per-context resolver inventory.
Config keys follow the existing `RegisterResolverService` naming
convention: `ooapi_courses`, `ooapi_programs`, `ooapi_offerings` contexts
(`ooapi_courses_register`/`_schema`, etc.); `organization` reuses the
already-existing `organisations` context — no new config key for it.

## Open questions

1. Exact OOAPI 5.0 JSON envelope and pagination parameter names (`pageNumber`/
   `pageSize` vs. an alternative) — pin against the published OOAPI 5.0
   OpenAPI definition during implementation rather than guessing here.
2. Whether `program.courses` is carried as an array field populated by the
   OpenConnector mapping (scholiq's `Programme.courseIds` already exists) or
   resolved as a reverse query at render time — proposed: array field, kept
   consistent with how OpenConnector already writes it, avoiding a second
   query path.
3. Whether the consumer-credential MVP (D3) should be scoped per catalog or
   per instance — proposed: per catalog, matching `hasOoapi`'s per-catalog
   toggle, so a multi-catalog instance can grant different SURFeduhub
   consumers access to different institutional catalogs.
