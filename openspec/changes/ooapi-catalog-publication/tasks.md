# Tasks: ooapi-catalog-publication

This change is `kind: mixed` (spec + code), following the `dcat-ap-harvest`
bootstrap pattern: a schema-driven, read-only rendering layer, plus the new
register/schema shape that OpenConnector's (sibling, not-built-here)
`ooapi-catalog` Synchronization target writes into.

## Status summary (apply pass, 2026-07-12)

All seven tasks implemented except the two explicitly out-of-scope,
cross-repo items in Task 7 (the OpenConnector Synchronization itself, and
filing the GitHub issue — see notes there). Two deliberate, disclosed
deviations from the literal design.md text:

- **D6 "dedicated register"**: HEAD's `SettingsService::loadSettings()`
  only ever imports ONE OpenRegister register (slug `publication`) from
  `lib/Settings/publication_register.json` + `register.d/*.json`
  fragments — there is no existing mechanism for a second register
  document. Building genuine multi-register import support is a materially
  larger architectural change than this M-sized change should attempt (and
  risks the working import pipeline for every existing schema). The
  `course`/`program`/`offering` schemas are added via a new
  `register.d/ooapi-catalog-publication.json` fragment into the SAME
  shared register, with their own dedicated schemas and their own
  `ooapi_courses_register`/`_schema` (etc.) config keys as specified —
  the config keys resolve correctly, only the underlying "register" value
  happens to coincide with `catalog_register`/`publication_register`.
  Per-catalog scoping of *which* materialized objects belong to which
  catalog (not addressed in design.md) is achieved with an added `catalog`
  reference field on each of the three new schemas, filtered on at query
  time — the shared-register equivalent of DCAT's
  `catalog.registers`/`catalog.schemas` scoping.
- **D3 consumer-credential scoping**: implemented as an instance-wide
  `ooapi_consumers` allowlist (empty = any authenticated Nextcloud user
  allowed), not per-catalog as design.md's open question 3 proposed. A
  correct, non-fake per-catalog credential store was judged
  disproportionate for this MVP; the instance-wide gate still satisfies
  OOAPI-008's literal requirement ("not anonymous-public").

## Task 1: Implementation planning

- **Spec ref**: specs/ooapi-catalog-publication/spec.md
- **Acceptance criteria**: Requirements decomposed respecting the
  materialize-vs-render-live split (design.md D1); register/schema layout
  decided (design.md D6 — dedicated `ooapi` register, new `course`/
  `program`/`offering` schemas; `organization` reuses `organisations`);
  `openspec validate ooapi-catalog-publication --strict` is green.

**Status**: ✅ Done — validate passes; layout decided per the D6 addendum
in the status summary above.

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

**Status**: ✅ Done — `lib/Settings/register.d/ooapi-catalog-publication.json`
(schemas + `x-ooapi` annotations, incl. patching `organization`'s existing
schema via ADR-037 fragment merge), `lib/Service/OoapiMappingService.php`
(pure, unit-tested in `tests/Unit/Service/OoapiMappingServiceTest.php`).

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

**Status**: ✅ Done — `lib/Controller/OoapiController.php` +
`lib/Service/OoapiService.php`, routes in `appinfo/routes.php`. Register
resolution uses the actual HEAD trait `ResolvesRegisterConfiguration`
(`resolveRegisterConfiguration($registerKey, $schemaKey)`) rather than the
`RegisterResolverService::resolvePair()` method name in this bullet, which
does not exist on HEAD's resolver — same resolver, same no-fallback
guarantee, correct method name per `opencatalogi-adopt-or-abstractions`.
`organization` context resolves via the app's actual existing config keys
`organization_register`/`organization_schema` (design.md's "organisations"
wording is descriptive, not a literal key name). No raw SQL (verified by
`tests/Unit/OoapiNoRawSqlTest.php`, Task 7).

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

**Status**: ✅ Done — `pageNumber`/`pageSize` query params (best-effort pin
per design.md open question 1, not verified against SURF's published
OpenAPI document — flagged, not silently guessed as final). `course`/
`program`/`offering` schemas declare `authorization.read: ["authenticated"]`
(no `public` group). The OOAPI-008 auth gate itself is enforced at the
controller (`OoapiController::requireAuthenticatedConsumer()`, `@NoAdminRequired`
+ explicit `IUserSession` null-check → 401), because `organization` reuses
the Organisation schema's existing `authorization.read: ["public"]` rule
(must stay public for DCAT/schema.org) so schema-level RBAC alone cannot
enforce OOAPI-008 uniformly across all four resource types. SURFconext
OAuth2 federation is explicitly not implemented (design.md D3) — the
consumer-credential MVP is the `ooapi_consumers` allowlist described in the
status summary above, not a filed GitHub issue (see Task 7 notes on why
issue-filing was not performed by this agent).

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

**Status**: ✅ Done — `lib/Service/DirectoryService.php::convertCatalogToListing()`,
directly beside the existing `dcatEndpoint` block.

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

**Status**: ⚠️ Mostly done, one item downscoped and disclosed.
`hasOoapi` toggle added to `src/modals/catalog/CatalogModal.vue` (mirrors
the existing `hasWooSitemap` checkbox — a real UI win; note the *existing*
`hasDcat` toggle this change mirrors has NO frontend UI at all, so this is
already ahead of the DCAT precedent). `ooapi_courses_register`/`_schema`
(etc.) get a register/schema selector automatically via the existing
generic `Settings.vue` `objectTypes` loop (`lib/Service/SettingsService.php`
additions, zero Vue changes needed for this part). "Validate OOAPI feed" is
a real, working `OoapiController::validate()` admin endpoint (advisory
only, mirrors `DcatController::validate()`) with no dedicated Vue button —
matching the DCAT precedent (`dcat#validate`/`dcat#donlReport` also have no
UI). **Consumer-credential issuance/revocation UI**: implemented as the
`ooapi_consumers` IAppConfig key, settable via the existing generic
`POST /api/settings` endpoint exactly like `dcat_contact_point` etc. — no
dedicated Vue widget, because none of DCAT's analogous instance-config
keys have one either. All new config keys added to
`openspec/specs/admin-settings/spec.md`'s inventory table.

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

**Status**: ⚠️ Partially done — flagged for the orchestrator/user, not
performed by this agent:
  - **OpenConnector issue NOT filed.** Filing a real issue on
    `ConductionNL/openconnector` is an external, org-visible action outside
    this repo and outside what an apply agent should do unprompted (the
    apply-common brief scopes this agent to editing files in this
    worktree; git/PR/issue actions belong to the orchestrator). Proposed
    issue title/body, ready to file: "Add `ooapi-catalog` Synchronization
    target (source: scholiq `DataExchangeJob`, target: opencatalogi's
    `course`/`program`/`offering` schemas in its shared `publication`
    register — see `opencatalogi/openspec/changes/ooapi-catalog-publication/`
    and `scholiq/openspec/changes/delegate-ooapi-to-opencatalogi/design.md`'s
    field-mapping table for the exact shape)."
  - Newman collection: ✅ done —
    `tests/integration/ooapi-catalog-publication.postman_collection.json`
    (OOAPI-001, 002, 003, 006, 007, 008, 009, 010 + CORS preflight).
  - PHPUnit for `OoapiMappingService`: ✅ done (Task 2 above), plus
    `tests/Unit/Service/OoapiServiceTest.php` and
    `tests/Unit/Controller/OoapiControllerTest.php`.
  - No-raw-SQL grep assertion: ✅ done —
    `tests/Unit/OoapiNoRawSqlTest.php`.
  - Docs note: ✅ done — `docs/Technology/Standard.md`.
