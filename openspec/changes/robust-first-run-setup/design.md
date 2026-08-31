# Design: robust-first-run-setup

## Context
OpenCatalogi's publishing surface depends on four app-config resolver keys
(`catalog_register`, `catalog_schema`, `publication_register`,
`listing_register`) written by `SettingsService::loadSettings()`. The only
place that currently invokes `loadSettings()` automatically is
`lib/Repair/InitializeSettings.php`, registered in `appinfo/info.xml` as:

```xml
<repair-steps>
    <post-migration>
        <step>OCA\OpenCatalogi\Repair\InitializeSettings</step>
    </post-migration>
</repair-steps>
```

Nextcloud's repair-step pipeline (`OC\Repair`) only runs `<post-migration>`
steps during `occ upgrade` and `occ maintenance:repair` — **not** during
`occ app:install` or `occ app:enable`. A brand-new instance that runs
`occ app:enable openregister && occ app:enable opencatalogi` therefore never
executes `InitializeSettings::run()`, leaving all four resolver keys unset.
Every Catalogi/Publications/Listings page then renders "Invalid
configuration for object type" until an operator manually runs
`occ maintenance:repair`, opens the setup wizard and runs its
`reload-settings` action, or hits `GET /api/settings/load`.

`Application::boot()` (`lib/AppInfo/Application.php:263-293`) already runs on
every request (it registers the app-menu nav entry) but currently only
contains a comment — `"Initialization handled by the Repair step"` — that is
false for the install-time case.

Separately, `SetupController::status()` (`lib/Controller/SetupController.php:131-166`)
already computes:

```php
$completed = ($registersWired === true && $scopeChosen === true && $catalogReady === true);
```

`$federationDone` is reported per-step but deliberately excluded from
`$completed`, and `src/manifest.json`'s `connect-federation` step
(`src/manifest.json:16`) has no `"required": true` — unlike `config-check`,
`catalog-scope`, and `create-catalog`, which all declare it. Reading the code
confirms the "wizard can never complete when the directory is empty" failure
mode described in the originating bug report does **not** reproduce against
the current `setup-wizard-server-contract` implementation (commit
`3601d0a`). This design therefore treats Fix 2 as a spec-and-test hardening
exercise: make the optionality an explicit, `@spec`-traceable requirement and
add a regression test, rather than change runtime behaviour.

Fix 3 addresses a live production incident. `Application::register()`
(`lib/AppInfo/Application.php`, the AppHost block spanning ~lines 170-248)
registers four services whose closures construct OpenRegister's AppHost
generic controllers — `GenericHealthController`, `GenericMetricsController`,
`GenericDashboardController`, `GenericPreferencesController`. These classes
live in `OCA\OpenRegister\AppHost\Controller\*` and only exist in OpenRegister
>= 2.0.0-beta.3 (the ADR-040 AppHost engine). Nextcloud resolves a registered
service lazily, but the closures reference the OpenRegister class by name at
construction time, and OpenCatalogi's routes (`/`, `/{path}`,
`/api/preferences/{key}`, `/api/health`, `/api/metrics`) resolve to those
leaf-namespaced controller class names on every page load — so on an
OpenRegister older than 2.0 the container tries to construct a class that does
not exist and Nextcloud raises a fatal "Class ... not found" 500. This is
currently taking down `directory.opencatalogi.nl` (OpenCatalogi 1.1.0-beta.4
+ OpenRegister 1.1.1). Nextcloud's `info.xml` `<dependencies>` block can pin
Nextcloud core and PHP versions but has no element for an app-to-app minimum
version, so the constraint cannot be declared — it must be enforced at
runtime with a `class_exists()` guard.

## Goals / Non-Goals

**Goals**
- A clean `app:enable` leaves the resolver keys wired without any manual
  step, exactly once, without adding request-time overhead once wired.
- The fallback is safe to run concurrently with the repair step (upgrade
  path) and with the wizard's own `reload-settings` action — none of the
  three call sites may double-import or corrupt configuration.
- `connect-federation`'s optionality becomes an explicit spec requirement
  with regression coverage, so a future edit cannot silently reintroduce a
  block on wizard completion.
- OpenCatalogi loads a usable page (with a clear "OpenRegister >= 2.0
  required" admin notice) instead of a fatal 500 when OpenRegister is too old
  to provide the AppHost engine.

**Non-Goals**
- Changing how `<post-migration>` repair steps are declared or timed — this
  is a framework constraint, not something this app controls.
- Adding a new repair-step type or install-time hook — Nextcloud's
  `IRepairStep` API has no "on-install-only" phase.
- Modifying `SetupController`'s step-computation logic, the manifest's setup
  block, or `DirectoryService::syncDirectory()`.
- Any change to RBAC, multitenancy, or the `_rbac:false` / `_multitenancy:false`
  privileged-action pattern already used by `InitializeSettings` and
  `SetupController`.
- Restoring the deleted bespoke Dashboard/Preferences/Health/Metrics
  controllers as a fallback code path for old OpenRegister — the compat guard
  degrades to a notice; the supported remediation is upgrading OpenRegister.
- Changing OpenRegister itself, or its version.

## Decisions

### Decision 1: Guard via a dedicated app-config flag, not `registersConfigured()`
`SetupController::registersConfigured()` already checks whether the four
resolver keys are non-empty — reusing that check to gate the boot fallback
was considered, but rejected: `loadSettings(force: false)` no-ops
successfully whenever the registers already exist (see the "Verified
environment facts" in the proposal), so gating on "keys unset" alone is
*correct* but wastes a `SettingsService` container resolution + a full
`loadSettings()` call on **every request** post-install, since the check
itself doesn't short-circuit before invoking the service. A dedicated flag
(`settings_initialized`, written only after a successful call) makes the
common case (already-wired instance, which is every request after the
first) a single cheap `IAppConfig::getValueString()` read with no service
resolution at all — mirroring the existing `catalogExists()` /
`onboarding_completed_version` short-circuit pattern already used in
`SetupController`.

**Alternative considered**: check `registersConfigured()`-equivalent logic
directly in `boot()` instead of a dedicated flag. Rejected — it re-resolves
`IAppConfig` and reads four keys instead of one, and does not distinguish
"never attempted" from "attempted and failed", which matters for retry
semantics (see Decision 2).

### Decision 2: Flag is set only on success, so failures self-heal on the next request
If `loadSettings()` throws (e.g. OpenRegister briefly unavailable at boot),
the flag is left unset. The next request re-attempts. This mirrors
`InitializeSettings::run()`'s own try/catch — a failure is logged, not fatal,
and not falsely marked "done". The alternative (mark attempted regardless of
outcome, to guarantee at-most-once) was rejected: it would leave an instance
permanently unconfigured if the very first boot after install raced
OpenRegister's own initialization, with no automatic recovery path short of
an operator manually clearing the flag.

### Decision 3: Reuse `SettingsService::loadSettings(force: false)`, not a new method
`InitializeSettings` already calls this with `force: false`, and it is
documented as idempotent ("running multiple times will not create
duplicates"). The boot fallback calls the exact same method with the exact
same argument, so its behaviour when the registers already exist (whether
wired by a prior repair run, a prior boot-fallback run, or the wizard's
`reload-settings` action) is identical: a fast no-op. No new service method,
no parallel import path.

### Decision 4: Boot-time DI resolution, not a background job
`SettingsService` is resolved from `IBootContext::getServerContainer()`
inside `boot()`, matching how `InitializeSettings` resolves it from
`ContainerInterface` and how `boot()` already resolves `INavigationManager`
and `IURLGenerator` a few lines below. A background job (`IJob`/`TimedJob`)
was considered — rejected because it introduces a delay between
`app:enable` and a working UI (the job might not run for up to a cron
interval), which reintroduces exactly the "floods with errors on first
load" symptom this fix removes. The boot-time guard costs a single
app-config read on the hot path and nothing more once set.

### Decision 5: `class_exists` compat guard in `register()`, keyed on one marker class
The guard checks `class_exists(\OCA\OpenRegister\AppHost\Controller\GenericDashboardController::class)`
once at the top of the AppHost block in `register()`. `GenericDashboardController`
is chosen as the single canonical marker because it is part of the same
ADR-040 AppHost engine release as the other three generics — they ship
together, so one check gates all four registrations. When it returns `false`,
`register()` skips all four `registerService(...)` calls, logs a warning via
the injected/resolved `LoggerInterface` naming the missing class, and
registers a Nextcloud admin notification (or, if a notification manager is
unavailable at `register()` time, defers a notice via an app-config flag that
an admin-settings surface reads) stating "OpenRegister >= 2.0 is required".
When it returns `true`, the four registrations proceed byte-for-byte as today.

**Alternatives considered:**
- *Per-registration `class_exists` inside each closure* — rejected: the
  closures run lazily at request time when the container constructs the
  controller, and returning early there still leaves the route resolving to a
  non-constructible controller (the 500 moves, it doesn't disappear). The
  guard must prevent the *registration* so the route dispatch has a clean
  failure/fallback, not the construction.
- *A `use function \class_exists` on the OpenRegister app version via
  `IAppManager::getAppVersion('openregister')` + version compare* — rejected:
  brittle against pre-release version strings (`2.0.0-beta.3` vs `2.0.0`) and
  couples to a version literal; `class_exists` on the actual required symbol
  is the direct, self-documenting test of "is the engine present".
- *Catching the fatal at the routing layer* — not possible: a
  "Class not found" is a PHP fatal `Error`, and it is raised deep in NC's
  controller resolution where OpenCatalogi has no interception seam.

### Decision 6 (declarative-vs-imperative, ADR-031): all three fixes are correctly imperative
Fix 1 is an **install/boot lifecycle guard** — a one-time state transition
gated on framework-lifecycle facts (is OpenRegister installed, has this app
already self-configured) that OpenRegister's `x-openregister-*` schema
extensions have no vocabulary for; none of lifecycle/aggregations/
calculations/notifications/relations/widgets model "run this admin action
once after install." This falls under ADR-031's explicit allowance for
"External API integrations" / lifecycle-adjacent framework glue, and more
precisely under the ADR-003 "apps SHOULD still write service code" carve-out
for install/boot orchestration — it is PHP framework wiring, not object
business logic, and it does not touch a schema register at all.

Fix 2 is **wizard controller logic** (`SetupController::status()`'s step
composition) — a UI/completion-gating decision about which steps are
required vs. optional. This is analogous to selecting a lifecycle template
(ADR-031's "domain rule engine that selects which lifecycle template
applies" carve-out) rather than being a lifecycle/aggregation/calculation/
notification itself; there is no OR schema concept of "optional wizard
step."

Fix 3 is a **startup dependency-compatibility guard** — a `class_exists`
check on a peer app's engine class during DI registration. This is the
purest form of the ADR-031 lifecycle-guard exception: it is framework/DI
wiring that decides whether a peer abstraction is even available to consume,
which by definition cannot be expressed as `x-openregister-*` schema metadata
(the metadata engine is exactly the thing being probed for). It also sits
squarely under ADR-003 "apps SHOULD still write service code" for
cross-app/boot orchestration.

No `x-openregister-*` extension fits any of the three fixes — all are
correctly PHP, and ADR-031's declarative-first default does not apply.

## Nextcloud Integration
- **Controllers**: none new. `SetupController` unchanged (Fix 2 is spec +
  test only).
- **Services**: `SettingsService` (existing, reused via
  `loadSettings(force: false)`), `DirectoryService` (existing, unchanged;
  its `syncDirectory()` failure path is exercised by the new regression
  test, not modified).
- **App lifecycle**: `Application::boot()` gains the guarded settings-fallback
  block, resolving `SettingsService` and `IAppManager`/`IAppConfig` from
  `IBootContext::getServerContainer()`. `Application::register()` gains the
  `class_exists` compat guard wrapping the four AppHost `registerService(...)`
  calls, using `LoggerInterface` (warning log) and NC's notification /
  admin-notice mechanism (`OCP\Notification\IManager` or an app-config-backed
  admin notice) for the too-old-OpenRegister signal.
- **Config keys**: new `settings_initialized` app-config key (values:
  unset → not yet run; `'true'` → wired successfully at least once). If the
  admin-notice path uses an app-config flag rather than a live notification,
  a second key (e.g. `openregister_incompatible`) records that state for the
  admin-settings surface to read. No interaction with
  `onboarding_completed_version` or `default_directory_url`.

## Security Considerations
The boot fallback calls the same `SettingsService::loadSettings()` already
invoked unauthenticated-context by the repair-step pipeline (`occ` CLI runs
as system/root) — it introduces no new privilege boundary. It does not
accept any request input; the guard condition is server-side app-config
state only, not derived from the request. No new routes, no new CSRF
surface, no new admin-authorization surface. The `settings_initialized` flag
is a boolean-shaped app-config value with no sensitive content.

The Fix 3 compat guard only *reduces* attack surface: when OpenRegister is
too old it registers fewer services and exposes fewer working endpoints, and
the marker check reads a class name, not request input. The admin notice is
shown only to admins (via NC's notification/admin-settings authorization),
so it does not leak infrastructure detail to unauthenticated users. It
introduces no new privilege boundary.

## File Structure
```
lib/
  AppInfo/
    Application.php          # MODIFIED: guarded loadSettings() fallback in boot();
                            #           class_exists AppHost compat guard in register()
tests/
  Unit/
    AppInfo/
      ApplicationTest.php    # NEW (or extended): boot-fallback + AppHost compat-guard coverage
  Unit/
    Controller/
      SetupControllerTest.php # MODIFIED: connect-federation optionality regression test
openspec/
  specs/
    first-time-onboarding/
      spec.md                 # MODIFIED ONB-001, ONB-007 + ADDED ONB-009 (synced from this change's delta)
```

## Trade-offs
- **Boot-time check vs. a smarter repair-step registration**: Nextcloud
  offers no "run on install" repair hook, so the alternative to a boot-time
  guard would be asking users/ops to always run `occ maintenance:repair`
  after `app:enable` — documented today but not enforced, which is the
  exact gap this change closes. The boot-time guard trades a small amount
  of one-time boot latency for zero-touch correctness.
- **Spec-only fix for Fix 2**: risks looking like "no code changed" to a
  reviewer skimming the diff, but the regression test IS the code change
  that matters — it converts an implicit (and previously reported as
  broken, per the environment brief) behaviour into an enforced contract.
- **Compat guard degrades to a notice, not a fallback code path**: an old
  OpenRegister still can't serve the dashboard/preferences/health/metrics
  endpoints — those simply 404/notice instead of 500ing the whole app. The
  alternative (resurrecting the deleted bespoke controllers as a fallback)
  was rejected as far more code and maintenance for a state the operator
  should exit by upgrading OpenRegister, not linger in. The guard buys a
  working, self-explaining app while the upgrade happens, which is the whole
  point given the live production outage.
- **`class_exists` vs. version-string compare**: `class_exists` on the actual
  required symbol is chosen over `IAppManager::getAppVersion()` compares
  because it is robust against pre-release version strings and tests the
  literal thing that matters (is the engine class loadable), at the cost of
  coupling to one class name — accepted, since that class is the stable
  public entry point of the AppHost engine OpenCatalogi already consumes.
