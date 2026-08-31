---
kind: code
depends_on: []
---

# Proposal: robust-first-run-setup

## Summary
Fix three first-run robustness gaps in OpenCatalogi's onboarding path. First, a
clean install (`occ app:install`/`app:enable`) never runs
`InitializeSettings`'s register/schema import because it is registered only as
a `<post-migration>` repair step, so a truly-clean instance lands with unset
resolver keys and the UI floods with "Invalid configuration for object type"
errors until an operator manually runs `occ maintenance:repair` or the
wizard's reload action. Second, the `connect-federation` setup step depends on
reaching the external national directory (`directory.opencatalogi.nl`), which
can be empty or unreachable, and the spec that governs it must make explicit
— not just implicit in current code — that this step is optional and never
blocks the wizard from completing. Third, OpenCatalogi's `register()` wires
AppHost controllers (`GenericDashboardController`, `GenericPreferencesController`,
and the Health/Metrics equivalents) that construct OpenRegister classes present
only in OpenRegister >= 2.0.0 (the AppHost/ADR-040 engine); on an older
OpenRegister those classes are absent and every OpenCatalogi page fatal-500s
with "Class ... not found" — currently happening in production. This change
adds a `class_exists` runtime compatibility guard that skips the AppHost wiring
and degrades gracefully to a usable page plus a clear "OpenRegister >= 2.0
required" admin notice instead of a fatal error.

## Motivation
`lib/Repair/InitializeSettings.php` → `SettingsService::loadSettings()` wires
the four resolver keys (`catalog_register`, `catalog_schema`,
`publication_register`, `listing_register`) that the entire publishing
surface depends on. `<post-migration>` repair steps only run during
`occ upgrade` and `occ maintenance:repair`, not during `app:install` /
`app:enable` (Nextcloud does not invoke the post-migration repair pipeline on
a fresh app enable). On a from-scratch install this leaves every resolver key
empty, and the UI throws "Invalid configuration for object type" across
Catalogi/Publications/Listings until someone finds and runs the setup
wizard's `reload-settings` action or `occ maintenance:repair` by hand. This
is a zero-touch expectation gap: the app should be usable immediately after
`app:enable`, matching what `setup-wizard-server-contract`'s own spec already
says should happen ("set on install by `InitializeSettings`" — see its
`config-check` scenario "Auto-wired instance is not gated on config-check").

Separately, `setup-wizard-server-contract` introduced the `connect-federation`
step to let a user sync the national directory immediately instead of waiting
for `doCronSync`. The directory is an external dependency the wizard cannot
control — if it is empty or temporarily unreachable, a wizard implementation
that (even inadvertently) required this step to succeed before advancing
would trap users in an onboarding flow that can never complete on an
otherwise fully-configured instance. This change makes the "optional,
skippable, never blocks completion" contract an explicit, tested MODIFIED
requirement of the `first-time-onboarding` capability, and adds regression
coverage so a future edit cannot silently reintroduce the block.

Third, `Application::register()` (~lines 216-224) registers services that
alias `OCA\OpenCatalogi\AppHost\Controller\GenericDashboardController` and
`GenericPreferencesController` (and, above them, the Health/Metrics
equivalents) to closures that construct OpenRegister's
`OCA\OpenRegister\AppHost\Controller\Generic{Dashboard,Preferences,Health,Metrics}Controller`.
Those OpenRegister classes exist ONLY in OpenRegister >= 2.0.0-beta.3 (the
AppHost/ADR-040 engine). When OpenRegister is older — e.g. stable 1.1.1 — the
class is absent and OpenCatalogi throws a FATAL "Class ... not found" 500 on
every page of `/apps/opencatalogi/`. This is a **live production incident**:
`directory.opencatalogi.nl` runs OpenCatalogi 1.1.0-beta.4 against OpenRegister
1.1.1 and is fully down. Nextcloud's `info.xml` cannot express an app-to-app
minimum version (`<dependencies>` only pins Nextcloud core and PHP), so the
guard must be enforced at runtime: guard the AppHost wiring with
`class_exists(...)`, skip it when the class is absent (no fatal), log a clear
warning, and surface an admin-facing "OpenRegister >= 2.0 required" notice so
the app degrades to a usable state instead of a 500.

## Affected Projects
- [x] Project: `opencatalogi` — guarded boot-time settings fallback in
  `lib/AppInfo/Application.php`; `class_exists` OpenRegister-compatibility
  guard around the AppHost controller wiring in the same file; explicit spec +
  regression test coverage for `connect-federation` optionality in
  `SetupController`.

## Scope

### In Scope
- A guarded, idempotent fallback in `Application::boot()` that calls
  `SettingsService::loadSettings(force: false)` exactly once, when
  OpenRegister is installed and an app-config flag (e.g.
  `settings_initialized`) is not yet set to `'true'`, so a clean
  `app:install`/`app:enable` wires the resolver keys without waiting for a
  repair run. The guard flag is set after a successful call so the fallback
  never re-runs on every request.
- A spec requirement (MODIFIED, `first-time-onboarding` capability) stating
  the install-time auto-wiring must be zero-touch: no manual
  `maintenance:repair` or wizard action required for a normal install where
  OpenRegister was already enabled first.
- A spec requirement (MODIFIED, `first-time-onboarding` capability) making
  explicit that `connect-federation` is optional/skippable and MUST NOT be a
  precondition for `onboarding_completed_version` to be set, covering the
  "directory empty" and "directory unreachable" cases.
- A `class_exists`-based OpenRegister-compatibility guard around the AppHost
  service/controller wiring in `Application::register()` (Dashboard,
  Preferences, Health, Metrics): when
  `OCA\OpenRegister\AppHost\Controller\GenericDashboardController` is absent,
  skip the AppHost wiring, log a warning, and register an admin-facing notice
  that OpenRegister >= 2.0 is required; when present, wire exactly as today.
- Graceful degradation: OpenCatalogi pages MUST still load (returning a
  usable page / clear message) when OpenRegister is too old, never a fatal
  500.
- PHPUnit coverage: a guarded-boot-fallback test (fires once when unset,
  no-ops when the flag is already set, no-ops when OpenRegister is absent);
  a regression test asserting `SetupController::status()`'s `completed`
  computation excludes `connect-federation`/`federationDone` even when the
  directory sync fails or returns zero listings; and a compat-guard test
  asserting `register()` skips the AppHost wiring (no fatal) when the
  OpenRegister AppHost class is absent and wires it when present.

### Out of Scope
- Any change to `SetupController::status()`'s existing step-computation logic
  itself — code inspection during spec-writing confirmed `connect-federation`
  already does not gate `completed` (`completed` is computed from
  `registersWired && scopeChosen && catalogReady` only) and the manifest step
  already omits `"required": true` on `connect-federation`. This change adds
  the explicit spec contract and a regression test guarding that behaviour;
  it does not change the computation.
- Changing `<post-migration>` to a different repair-step timing, or adding a
  new repair-step hook — Nextcloud's repair-step API has no "on-install-only"
  hook; the fix is a boot-time application-level guard, not a repair-step
  change.
- Any change to `DirectoryService::syncDirectory()`'s SSRF guard, timeout, or
  retry behaviour.
- Re-implementing the deleted bespoke Dashboard/Preferences/Health/Metrics
  controllers as a fallback when OpenRegister is too old — the compat guard
  degrades to a notice, it does NOT restore a pre-AppHost code path. The
  supported remediation is upgrading OpenRegister to >= 2.0.
- Bumping OpenRegister's own version or its `info.xml` — the fix lives
  entirely in OpenCatalogi's runtime.
- Seeding example data (catalogs, publications) beyond what
  `setup-wizard-server-contract`'s `create-first-catalog` action already does.

## Approach
Fix 1 adds a small guarded block at the top of `Application::boot()` that
checks `IAppConfig::getValueString(self::APP_ID, 'settings_initialized', '')`;
when empty and OpenRegister is enabled, it resolves `SettingsService` from the
container, calls `loadSettings(force: false)`, and — only on success — writes
`settings_initialized = 'true'`. Failure leaves the flag unset so the next
request retries rather than silently giving up (bounded by the existing
idempotent no-op behaviour of `loadSettings` once the registers exist).

Fix 2 does not change `SetupController` or the manifest — the current
implementation already satisfies the "optional, non-blocking" contract. This
change writes that contract down as a spec requirement so it is enforced by
`@spec` traceability and Gate-19/Gate-16 in future PRs, and adds a PHPUnit
regression test that fails if a future edit ties `completed` or
`onboarding_completed_version` to `federationDone`.

Fix 3 wraps each AppHost `registerService(...)` closure body's target class
in a `class_exists()` check performed once in `register()`. If
`OCA\OpenRegister\AppHost\Controller\GenericDashboardController` (the
canonical marker class for the OpenRegister >= 2.0 AppHost engine) is absent,
`register()` skips all four AppHost registrations, logs a warning, and
registers a Nextcloud admin notification / notice indicating OpenRegister
>= 2.0 is required; the app's other services (SPA, setup, catalog/federation
domain services) still register, so pages render. When the class is present,
registration proceeds unchanged. Details in design.md.

## New Dependencies
None.

## Impact
- `lib/AppInfo/Application.php` — new guarded fallback block in `boot()`;
  `class_exists` compat guard around the AppHost registrations in `register()`
  plus a warning-log + admin-notice path when OpenRegister is too old.
- `lib/Controller/SetupControllerTest.php` (or equivalent PHPUnit path) — new
  regression test(s) for connect-federation optionality.
- `tests/Unit/AppInfo/ApplicationTest.php` (new or extended) — boot-fallback
  unit coverage plus AppHost compat-guard coverage.
- `openspec/specs/first-time-onboarding/spec.md` — MODIFIED + ADDED requirements.
- No routes, no schema, no DB migration.

## Cross-Project Dependencies
Runtime-only: depends on OpenRegister being installed for
`SettingsService::loadSettings()` to have any effect; the guard is a no-op
when OpenRegister is absent (mirrors `InitializeSettings`'s existing check).
Fix 3 explicitly targets a **version** mismatch: OpenCatalogi's AppHost
adoption (ADR-040) hard-requires OpenRegister >= 2.0.0-beta.3, which
Nextcloud's `info.xml` cannot enforce — the compat guard is the runtime
substitute for a declarable app-to-app minimum-version constraint.

## Risks

### Risk 1: Boot-time fallback runs on every request if the guard flag write fails silently
**Severity:** Medium — **Mitigation:** The flag is written via
`IAppConfig::setValueString`, a synchronous DB write; a failure there throws
and is caught by the same try/catch that already wraps `loadSettings()` in
`InitializeSettings`, logged, and the request continues unaffected. Worst
case is the fallback re-attempts on the next request (safe, since
`loadSettings(force:false)` no-ops when registers already exist per the
verified environment fact) — not a crash or a repeated visible error.

### Risk 2: Boot-time DB reads/writes add latency to every request until the flag is set
**Severity:** Low — **Mitigation:** The check is a single indexed app-config
read; once the flag is set (first successful boot after install) it short-
circuits immediately on every subsequent request. This matches the existing
pattern used elsewhere in the app (e.g. `catalogExists()`'s
`onboarding_completed_version` short-circuit in `SetupController`).

### Risk 3: Compat guard hides a genuinely broken OpenRegister install behind a "too old" notice
**Severity:** Medium — **Mitigation:** The guard keys off a single canonical
marker class (`GenericDashboardController`), which is present iff the AppHost
engine is available; its absence is unambiguous. The admin notice names the
exact required version (OpenRegister >= 2.0) and the warning log records the
missing class, so operators get an actionable signal rather than a silent
degrade. The guard does not swallow other OpenRegister errors — it only
gates the AppHost wiring on that one class check.

### Risk 4: Spec-only change for Fix 2 gives a false sense of "fixed" without a code change
**Severity:** Low — **Mitigation:** The regression test is the enforcement
mechanism — it fails the build if a future change reintroduces gating on
`connect-federation`, which is the actual risk this proposal defends against
(silent regression), not the current absence of a bug.

## Rollback Strategy
Remove the guarded fallback block from `Application::boot()`, revert the
`class_exists` compat guard in `register()` to the unconditional AppHost
registrations, and delete the new PHPUnit test files. No schema, DB, or data
migration is involved — the `settings_initialized` app-config key, if already
set on some instances, is inert and harmless to leave behind. The spec delta
reverts cleanly by removing the MODIFIED/ADDED requirements from
`first-time-onboarding`. Note: reverting Fix 3 restores the production
fatal-500 on old OpenRegister, so a rollback should only happen alongside an
OpenRegister >= 2.0 upgrade.

## Capabilities
- **first-time-onboarding** (Modified) — adds the zero-touch install-time
  auto-wiring guarantee, makes the `connect-federation` optional/skippable
  contract an explicit tested requirement, and adds the OpenRegister-version
  compatibility guard so OpenCatalogi degrades gracefully instead of
  fatal-500ing on a too-old OpenRegister.
