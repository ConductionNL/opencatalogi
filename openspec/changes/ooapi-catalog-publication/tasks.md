# Tasks: ooapi-catalog-publication

This change is `kind: mixed` (spec + code), following the `dcat-ap-harvest`
bootstrap pattern: a schema-driven, read-only rendering layer, plus the new
register/schema shape that OpenConnector's (sibling, not-built-here)
`ooapi-catalog` Synchronization target writes into.

## Task 1: Implementation planning

- **Spec ref**: specs/ooapi-catalog-publication/spec.md
- **Acceptance criteria**: Requirements decomposed respecting the
  materialize-vs-render-live split (design.md D1); register/schema layout
  decided (design.md D6 — dedicated `ooapi` register, new `course`/
  `program`/`offering` schemas; `organization` reuses `organisations`);
  `openspec validate ooapi-catalog-publication --strict` is green.

## Task 2: `course`/`program`/`offering` schemas + `x-ooapi` mapping layer

- **Spec ref**: specs/ooapi-catalog-publication/spec.md — OOAPI-003, OOAPI-004,
  OOAPI-005, OOAPI-006
- **Acceptance criteria**:
  - New OR schemas `course`, `program`, `offering` defined in a dedicated
    `ooapi` register, with fields matching the scholiq contract's field
    mapping table (`code`/`name`/`level`/`language` for course;
    `name`/`code`/`level`/`courseIds` for program;
    `courseId`/`programmeId`/`period`/`academicYear`/`teacherIds`/`learnerIds`
    for offering) plus an optional RIO identifier field.
  - `x-ooapi` annotation declared on each (`resource: course|program|offering`,
    identity mapping) and on the existing Organisation schema (`resource:
    organization`, real field map).
  - `OoapiMappingService` resolves the mapping from `x-ooapi`; a schema
    without the annotation is never offered as an OOAPI resource (no default
    mapping, per OOAPI-004).
  - RIO identifier omitted (not null-emitted) when absent (OOAPI-005).

## Task 3: Public endpoints + serializer

- **Spec ref**: specs/ooapi-catalog-publication/spec.md — OOAPI-001, OOAPI-002,
  OOAPI-003
- **Acceptance criteria**:
  - `GET /api/catalogs/{catalogSlug}/ooapi/v5/{organizations,programs,courses,
    offerings}[/{id}]` and `.../courses/{courseId}/offerings` registered in
    `appinfo/routes.php`, CORS per `cross-origin-api-access`.
  - `organization` renders live from the existing Organisation object
    (`organisations` `RegisterResolverService` context); `course`/`program`/
    `offering` query the new `ooapi` register/schema contexts via
    `searchObjects`/`zoeken-filteren` — no raw SQL against OR magic tables.
  - `hasOoapi: false` (default) → `404` on all OOAPI endpoints for that
    catalog; unknown catalog slug or resource id → `404`.
  - `OoapiController` resolves register/schema pairs via
    `RegisterResolverService::resolvePair('ooapi_courses' | 'ooapi_programs' |
    'ooapi_offerings' | 'organisations')` — no `IAppConfig::getValueString(...,
    '')` fallback pattern.

## Task 4: Pagination + consumer-credential auth

- **Spec ref**: specs/ooapi-catalog-publication/spec.md — OOAPI-007, OOAPI-008
- **Acceptance criteria**:
  - List endpoints paginate per the OOAPI 5.0 convention (parameter names
    pinned against the published OOAPI 5.0 OpenAPI definition — design.md
    open question 1); no duplicates/gaps across pages.
  - OOAPI endpoints require `authenticated` via OR schema authorization
    (`authorization.read` conditional rule), not `group: public`; anonymous
    requests are rejected. Full SURFconext OAuth2 federation is explicitly
    NOT implemented here (OOAPI-008) — filed as a follow-up issue, not
    silently absorbed into this task.

## Task 5: Federation directory advertisement

- **Spec ref**: specs/ooapi-catalog-publication/spec.md — OOAPI-009
- **Acceptance criteria**:
  - `DirectoryService`'s listing-projection helper (the same one that
    populates `dcatEndpoint`) populates `ooapiEndpoint` only for catalogs
    whose `hasOoapi` is truthy; disabled catalogs carry no `ooapiEndpoint`.
    Additive field on the projected listing object — no Listing JSON-schema
    file edit required (mirrors `dcat-ap-harvest` Task 5).
  - No new broadcast channel/cron; retry/dead-letter untouched
    (FED-OR-001/002) — the new field rides the existing projection.

## Task 6: Admin settings

- **Spec ref**: specs/ooapi-catalog-publication/spec.md — OOAPI-010
- **Acceptance criteria**:
  - Per-catalog `hasOoapi` toggle (default off) + `ooapi_courses_register`/
    `_schema`, `ooapi_programs_register`/`_schema`,
    `ooapi_offerings_register`/`_schema` configuration in the existing
    admin-settings surface.
  - Consumer-credential issuance/revocation UI for OOAPI-008.
  - "Validate OOAPI feed" action reports mandatory-property violations per
    resource id; advisory only (never blocks serving).
  - Every new config key added to the `admin-settings` spec's inventory
    table in this change (per `opencatalogi-adopt-or-abstractions`).

## Task 7: Cross-repo coordination, tests, docs

- **Spec ref**: specs/ooapi-catalog-publication/spec.md (all)
- **Acceptance criteria**:
  - File an issue against `ConductionNL/openconnector` for the
    `ooapi-catalog` `Synchronization` target (source: scholiq
    `DataExchangeJob` consumer; target: this change's `ooapi` register/schema;
    mapping: the scholiq field-mapping table), referencing this proposal and
    `scholiq/openspec/changes/delegate-ooapi-to-opencatalogi/design.md`'s
    field-mapping table. Not built in this change (Out of scope, proposal.md).
  - Newman collection covering OOAPI-001..010 (API-only surface, per the
    Playwright-UI-only/Newman-API rule); PHPUnit for `OoapiMappingService`
    (annotation resolution, RIO omission, identity vs. real mapping).
  - PHPUnit/grep assertion: no OOAPI-related source file contains
    `oc_openregister_table_` or `information_schema` (OOAPI-003).
  - README/docs note pointing at the new OOAPI 5.0 surface and its
    consumer-credential onboarding step.
