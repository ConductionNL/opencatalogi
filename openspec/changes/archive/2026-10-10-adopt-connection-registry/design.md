# Design: adopt-connection-registry

The contract is hydra `openspec/changes/connection-registry/design.md` (hydra#667, amended in hydra#673, hydra#674 and hydra#676). This file records how OpenCatalogi meets it and where it fits loosely.

## D1. Which connections are declared

Each candidate was checked against the code on `development` (e03eafbc). Only three services make outbound calls: `DirectoryService`, `BroadcastService` and `WooReadinessService`. A search for PDOK, a search backend of its own and other HTTP clients under `lib/` found nothing else. The Solr mentions belong to OpenRegister's backend.

| Key | Declared as | Why |
|---|---|---|
| `directory` | `reportedOnly: true` | `DirectoryService::doCronSync()` syncs every known directory plus `default_directory_url`. |
| `broadcast` | `reportedOnly: true`, no `settingsUrl` | One row for the family of peers `BroadcastService::broadcast()` notifies. No settings section controls it. |
| `woo-index` | `reportedOnly: true` | `WooReadinessService::runCheck()` is the only thing that knows whether the harvester can read this instance. |

**Why the directory has no `requiredConfig`.** `default_directory_url` falls back to `Application::DEFAULT_DIRECTORY_URL` when unset. Integriq reads the stored value, which is empty on a default install, so rule 5 would show Not configured for a sync that works.

**Why the broadcast has no `settingsUrl`.** `broadcast_max_retries`, `broadcast_request_timeout` and `local_federation_hosts` are set with `occ` only. Linking to the Federation sync section would promise a setting that is not there.

**Anchors.** The admin section is `opencatalogi` (`OpenCatalogiAdmin::getSection()`), so each link is `/settings/admin/opencatalogi#section-…`. `NcSettingsSection` has one root element, so the `id` on it lands on that element.

## D2. What OpenCatalogi reports, and when

`lib/Service/Connection/ConnectionReporter.php` sends both events. It names the classes by string behind `class_exists` (ADR-041) and never throws. `ConnectionObservations` maps outcomes to a status and a message, and holds no state.

**Directory, after every `doCronSync()`** (the `DirectorySync` job, the Sync directories now button, the setup wizard's sync step).

| Directories that answered | Status |
|---|---|
| all | `configured` |
| some | `limited`, naming the first host that failed |
| none | `error`, naming the first host that failed |

**Broadcast, after every `broadcast()`** (the `Broadcast` job, the setup wizard's connect step, and a sync that finds a new directory). Throttled.

| OpenCatalogi sees | Status |
|---|---|
| This instance advertises a local address | `unconfigured`, naming `overwrite.cli.url` |
| No peer to notify | `unconfigured` |
| every peer accepted | `configured` |
| some accepted | `limited`, naming the first host that refused |
| none accepted | `error`, naming the first host that refused |

**Woo-index, after a readiness check** (`POST /api/woo/readiness/run`).

| The check met | Status |
|---|---|
| No catalog with Woo sitemaps (the 409) | `unconfigured` |
| The check threw | `error`, without the exception text |
| No check passed | `error` |
| Some checks failed | `limited` |
| Every check passed, registration matches | `configured` |
| Every check passed, not registered, pending or a different URL | `limited` |

A message names a directory or a peer by host only, never by its full URL. Exception text never reaches a message: a Guzzle error can carry a URL with a token in it.

**Refresh.** `ConnectionReporter::REFRESH_KEYS` maps a connection to the settings whose save changes what its row judges. `SettingsController::update()` passes the keys it wrote, so a Woo-index registration save refreshes `woo-index`. `SetupController::config()` does the same, so a save of `default_directory_url` refreshes `directory`. Neither save reports: a report needs a sync or a check, and a save must not make outbound calls. A refresh also clears the broadcast report memory, so the next outcome shows at once.

**Why this is cheap.** A save, a button and a readiness check are admin actions. `DirectorySync` runs once per `sync_interval_seconds`, never below 900 seconds. `Broadcast` runs every 14400 seconds, but a sync can broadcast once per new directory, so the broadcast report is throttled: the same status at most once an hour, a different status at most once every five minutes. The memory is one app-config key, `connection_report_broadcast`. No page request sends an event (ADR-076).

**Wiring.** Every caller is autowired: `DirectoryService`, `BroadcastService`, `WooReadinessController`, `SettingsController` and `SetupController` take the reporter as an optional last argument. `Application::register()` builds none of them by hand, so there is no factory to update.

## D3. The page

- `src/manifest.d/connection-registry.json`: an `index` page `Integrations` at `/settings/integrations`, `requiresApp` integriq, `permission: admin`, `showAdd: false`, and the columns connection, status, status message, last checked and settings.
- Its menu entry `IntegrationsMenu` sits in the settings gear with `query: {app: opencatalogi}`, `permission: admin` and `visibleIf.appInstalled: integriq`.
- `src/services/connectionRegistry.js` holds the two formatters and `openIntegriqConnections`.
- `App.vue` passes the formatters through CnAppRoot's `formatters` prop. It passed none before this change. `src/registry.js`, the app's `customComponents` map, carries the handler.

**Formatters.** The installed `@conduction/nextcloud-vue` 2.37.0 ships no `connectionStatus` built-in, so OpenCatalogi carries a local copy with all six labels, `limited` included.

## D4. Contract misfits

- **A config key with a code default.** `default_directory_url` is optional because the code falls back to a constant. The contract has no way to say "empty means the default". `reportedOnly` works around it.
- **Broadcast family.** Peers are records in the listing schema, not settings. One row stands for all of them (D12, "still out").
- **A setting set outside the settings page.** `default_directory_url` is written by the setup wizard and by `occ`. The wizard refreshes; `occ` does not, and integriq's hourly resolver pass has nothing to resolve on a reported-only row, so the row keeps its last report until the next sync.

## Risks

- **A throttled broadcast can lag.** A changed status waits up to five minutes after the last report, and an unchanged one refreshes its `checkedAt` once an hour.
- **A readiness report ages.** The check runs only on the admin's button, so the row keeps its last outcome until the next run or a registration save.
