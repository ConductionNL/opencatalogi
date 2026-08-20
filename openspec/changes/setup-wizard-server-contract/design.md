# Design: setup-wizard-server-contract

## Architecture Overview

`first-time-onboarding` shipped the `manifest.setup` block but no backend, so the
shared `@conduction/nextcloud-vue` wizard engine (`CnSetupWizard` +
`useSetupStatus`) calls endpoints that don't exist. This change supplies the
missing server half — a thin `SetupController` that the engine already expects —
and reshapes the manifest steps from "ask the user for register IDs" to "verify
real state + run privileged actions".

```
CnSetupWizard (nc-vue)                SetupController (new)            existing services
─────────────────────                ─────────────────────            ─────────────────
useSetupStatus  ──GET status──▶  GET  /api/setup/status   ──reads──▶  IAppConfig (keys)
                                                            ──reads──▶  ObjectService (catalog count)
                                                            ──reads──▶  DirectoryService (listing presence)
config step     ──POST config─▶  POST /api/setup/config    ──writes─▶  IAppConfig
run-action step ──POST action─▶  POST /api/setup/action/{id}
                                   ├─ reload-settings    ─▶ SettingsService::loadSettings()
                                   ├─ create-first-catalog ▶ ObjectService->saveObject(_rbac:false)
                                   ├─ connect-federation ─▶ DirectoryService::syncDirectory(DEFAULT_DIRECTORY_URL)
                                   └─ (completion)        ─▶ IAppConfig set onboarding_completed_version
```

Status is **always derived** from live state; nothing about "is this step done"
is stored except the final `onboarding_completed_version` completion marker.

## Context

- **Auto-wiring already works.** `InitializeSettings` (repair step) →
  `SettingsService::loadSettings()` → `updateObjectTypeConfiguration()` writes
  `{type}_register/_schema/_source` for every object type on install. The wizard's
  config-check form is therefore redundant on a normal install and actively
  harmful (blank fields asking for numeric IDs).
- **Route shadowing is the reason status fails today.** OpenCatalogi registers
  `GET /api/{catalogSlug}` (requirement `[a-z0-9-]+`), which matches `setup`.
  `GET /api/setup/status` resolves to the publications/catalog handler →
  *"catalog 'setup' does not exist"*. The fix is registration order + a
  requirement that excludes the reserved `setup` segment.
- **Federation already happens via cron.** `DirectoryService::doCronSync()`
  injects `DEFAULT_DIRECTORY_URL` every run, so the only gap is *immediacy +
  visibility* — hence an explicit `connect-federation` action rather than new
  sync machinery.

## Goals / Non-Goals

**Goals**
- Make the declared wizard actually function (status resolves, config saves,
  completion records).
- Stop asking for already-wired registers; auto-pass `config-check`, gate only
  when genuinely unconfigured.
- Add working `create-catalog` and `connect-federation` steps.
- One source of truth for the national directory URL.

**Non-Goals**
- No changes to the nc-vue wizard engine (config-field pre-seeding lives there
  and is a separate change).
- No new schemas, no example-content seeding (empty first catalog only).
- The `manifest.walkthrough` tour is untouched.

## Decisions

### D1 — `SetupController` over extending `SettingsController`
A dedicated controller keeps the ADR-042 contract paths (`/api/setup/*`) and
their auth posture isolated and route-reachability-checkable. `SettingsController`
already carries unrelated CRUD; mixing the setup contract into it muddies the
auth surface. **Alternative considered:** add methods to `SettingsController` —
rejected for auth-surface clarity and gate scoping.

### D2 — Status is derived, not stored
Each step's `done` is recomputed per request from `IAppConfig` (register/schema
keys, `default_catalog_scope`, the skip flag), an `ObjectService` count for
catalogs, and a `DirectoryService`/listing lookup for federation. Only
`onboarding_completed_version` is persisted. This guarantees the wizard never
shows stale "done" state after config drifts (e.g. a register reset).
**Alternative:** store per-step booleans — rejected; drifts from reality.

### D3 — Route ordering + reserved-segment requirement
Register the three `/api/setup/*` routes **above** the catalog wildcard, and
tighten the wildcard so the literal `setup` cannot be a `catalogSlug` (either an
explicit exclusion in the requirement regex, or rely on the earlier, more
specific routes matching first — verified by live curl + route-reachability gate).

### D4 — Privileged actions reuse existing services with `_rbac:false`
`create-first-catalog` writes via the OpenRegister `ObjectService` system path
already used by `InitializeSettings` (so it works on CLI/automated installs and
regardless of the requesting user's RBAC, per ADR-042 §4). `connect-federation`
calls the existing `syncDirectory()` (keeping its SSRF guard + timeout).
`reload-settings` calls `loadSettings()`. No new business logic — the controller
orchestrates existing services.

### D5 — Default directory URL: constant + overridable config key
`Application::DEFAULT_DIRECTORY_URL` holds the literal; a `default_directory_url`
app-config key overrides it when set (admin running their own directory). A small
private resolver (`getDefaultDirectoryUrl()` reading config-with-fallback) is used
by `DirectoryService` and the controller; the Vue modal receives the value via
initial state rather than hardcoding it. **Alternative:** constant only — rejected
because some deployments federate against a non-national directory.

### Declarative-vs-imperative decision (ADR-031)
The two new behaviours — `create-first-catalog` and `connect-federation` — are
**imperative server actions**, and this is the ADR-031-sanctioned exception, not
a violation:
- They are **ADR-042 `run-action` setup actions** by definition: privileged,
  user-triggered, one-shot orchestration of existing services. ADR-042 §4
  mandates exactly this server-side privileged path.
- `connect-federation` is an **external integration** (HTTP sync of a remote
  directory) — an explicit ADR-031 imperative exception.
- `create-first-catalog` is a **one-shot seed/bootstrap** writing a single object
  via the OpenRegister abstraction (ADR-022), not a lifecycle/aggregation/derived
  field that belongs in the schema register.
No `x-openregister-{lifecycle,aggregations,calculations,notifications}` is
appropriate here; there is no recurring derived state to declare.

## API Design

### `GET /api/setup/status`
Admin-or-user readable (status is non-sensitive), CSRF not required for GET.
**Response:**
```json
{
  "version": 2,
  "completed": false,
  "steps": {
    "welcome": { "done": true },
    "config-check": { "done": true },
    "catalog-scope": { "done": false },
    "create-catalog": { "done": false },
    "connect-federation": { "done": false },
    "done": { "done": false }
  }
}
```

### `POST /api/setup/config`
Admin-only, CSRF-required. Persists posted app-config keys.
**Request:**
```json
{ "default_catalog_scope": "public" }
```
**Response:**
```json
{ "saved": true }
```

### `POST /api/setup/action/{actionId}`
Admin-only, CSRF-required. `actionId` ∈ `reload-settings`,
`create-first-catalog`, `connect-federation`, `complete`.
**Response (success):**
```json
{ "ok": true, "result": { "...action-specific...": "..." } }
```
**Response (federation unreachable):** HTTP 200 with `{ "ok": false, "error": "<message>", "skippable": true }` so the optional step surfaces a non-fatal error.

## Nextcloud Integration
- Controllers: `OCA\OpenCatalogi\Controller\SetupController` (new). Auth via
  Nextcloud attributes: `#[NoAdminRequired]` on `status` (read), admin-required +
  `#[NoCSRFRequired]` **absent** (CSRF enforced) on `config`/`action`. Route auth
  declared in `appinfo/routes.php` + method attributes (route-auth / semantic-auth gates).
- Services: `SettingsService` (`loadSettings`), `DirectoryService`
  (`syncDirectory`, default-URL resolver), OpenRegister `ObjectService`
  (catalog create + count) obtained via the existing service-resolution path.
- Mappers/Entities: none new — catalogs are OpenRegister objects.
- Events/Hooks: none.

## Security Considerations
- `config` + `action` endpoints are **admin-only and CSRF-guarded** (the wizard
  is an admin surface per ADR-042 §5); `status` is read-only and non-sensitive.
- `connect-federation` passes the URL through the existing `assertSafeOutboundUrl`
  SSRF guard in `syncDirectory`; the action only ever uses the resolved default
  directory, not an arbitrary client-supplied URL.
- `create-first-catalog` runs `_rbac:false` but writes only into the instance's
  own configured catalog register/schema — no cross-tenant write, no
  client-controlled register/schema id.
- Semantic-auth: the method body's privilege (admin) matches the declared
  attribute; no `NoAdminRequired` on a privileged write.

## File Structure
```
lib/
  AppInfo/Application.php           # + DEFAULT_DIRECTORY_URL constant
  Controller/SetupController.php    # NEW — status/config/action
  Service/DirectoryService.php      # getDefaultDirectoryUrl() resolver; 3 literals → constant
appinfo/
  routes.php                        # + 3 setup routes (before catalog wildcard)
  info.xml                          # version bump
src/
  manifest.json                     # setup block rework + setup.version bump + manifest version
  modals/directory/AddDirectoryModal.vue  # default URL from initial state, not literal (x4)
tests/
  Unit/Controller/SetupControllerTest.php # NEW
l10n/*.js                           # new manifest step source strings
```

## Trade-offs
- **Derived status costs a small per-open object-count query** vs storing
  booleans — accepted (D2) for correctness; it is bounded and cached within the
  request.
- **A dedicated controller adds a file** vs reusing `SettingsController` —
  accepted (D1) for a clean, gate-checkable auth surface.
- **Keeping the config-check step (auto-passing) instead of deleting it** retains
  the late-OpenRegister-install safety net at the cost of one extra (usually
  invisible) step — accepted; deleting it would remove the only remediation path
  when registers really are missing.

## Open Questions
- None blocking. The exact mechanism for excluding `setup` from the catalog
  wildcard (regex exclusion vs route precedence) is settled at implementation by
  whichever the route-reachability gate + live curl confirms.
