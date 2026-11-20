# Design: integration-maintenance-commands

Read at opencatalogi development `9aa54150`.

## Where it lands

| piece | file | what is there now |
|---|---|---|
| commands | `lib/Command/AttachDocumentsToPublicationsCommand.php` (Symfony `Command`, `configure()` sets name and options, dry run unless `--apply`), `lib/Command/MigrateCmsToPortaliqCommand.php` | registered at `appinfo/info.xml:256-257` |
| directory sync | `lib/Service/DirectoryService.php:192` `doCronSync()`, `:471` `syncDirectory(string $directoryUrl)`; job `lib/BackgroundJob/DirectorySync.php:111` | hourly |
| broadcast | `lib/Service/BroadcastService.php:425` `broadcast(?string $url = null)`; job `lib/BackgroundJob/Broadcast.php:85` | every four hours |
| retention | `lib/Service/RetentionService.php:544` `evaluate()`, `:644` `getQueueSummary()`; job `lib/BackgroundJob/RetentionEvaluation.php:82` | daily |
| Woo readiness | `lib/Service/WooReadinessService.php:139` `runCheck()` | from the admin settings button only |
| catalogue cache | `lib/Service/CatalogiService.php:653` `invalidateCatalogCache()`, `:672` by id, `:723` `warmupCatalogCache()`, `:741` by id | from object events only |

## D1. Thin commands, one service call each

Each command resolves its service through DI, calls the method in the table, and formats the returned array. No logic moves into a command. Where a background job does more than call the service (for example a guard that skips when nothing is configured), the guard moves into the service so the job and the command share it.

## D2. Safe by default where it changes data

`opencatalogi:retention:evaluate` acts only with `--apply` and reports what it would do otherwise, following `AttachDocumentsToPublicationsCommand`. `evaluate()` gains a `dryRun` parameter that returns the planned actions without saving. The other four do not change publications: sync and broadcast talk to other directories, readiness reads, and the cache command rebuilds a cache.

## D3. Output a script can read

Plain lines by default, one per item, and `--output=json` for the returned array as JSON. The new commands extend `OC\Core\Command\Base`, which provides that option, where the two existing ones extend Symfony's `Command` directly. Exit codes: 0 success, 1 a check or sync failed, 2 wrong usage. `opencatalogi:woo:readiness` exits 1 when the verdict is not pass, so it can gate a deployment script.

## D4. Registration

The five classes under `lib/Command/` are added to the `<commands>` block of `appinfo/info.xml`. Names follow `opencatalogi:<area>:<verb>`. When the app id moves, the commands move with it, which the docs say.

## Declarative or imperative

Console commands are imperative by definition (ADR-031 exception: scheduled or bulk work). No schema change.

## Seed data

None.

## Risks

- A sync started by hand while the cron sync runs. Both call the same idempotent service; the listing's `lastSync` staleness check (DIR-010) prevents double writes of the same data.
- `retention:evaluate --apply` on a large register. It reports the count first and processes in the service's existing pages.
