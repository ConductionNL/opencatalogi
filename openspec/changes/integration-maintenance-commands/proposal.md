---
kind: code
depends_on: []
---

# Proposal: integration-maintenance-commands

## Why

An operator who runs OpenCatalogi for several organisations wants to do routine work from a script: sync the federation directory now, run the retention evaluation after changing defaults, check the Woo-index readiness after a web-server change, rebuild a catalogue's cache after an import. Today each of these runs only from a background job on its own schedule or from a button, so an operator waits or clicks.

opencatalogi matrix, row `int-cli`, "Run bulk maintenance from the command line." Own rating partial.

- Own evidence: "appinfo/info.xml:256-257 registers lib/Command/AttachDocumentsToPublicationsCommand.php:71 (opencatalogi:documents:attach-to-publications) and MigrateCmsToPortaliqCommand.php:68 (opencatalogi:cms:migrate-to-portaliq)".
- Note: "Two single-purpose bulk commands. There is no general maintenance CLI such as republish, reindex or retention run."

What the competitors show, quoted from the matrix:

| system | rating | evidence |
|---|---|---|
| CKAN | yes | "the 'ckan' CLI registers dataset (show/list/delete/purge), search-index rebuild, db, jobs, sysadmin, user, views, clean and file commands (ckan/cli/cli.py:219-238; ckan/cli/dataset.py:23-71, cli/search_index.py:22-35); extensions add 'ckan harvester' (ckanext-harvest cli.py:126-362) and 'ckan datastore' (setup.cfg:52)." |
| DKAN | yes | "about 30 drush commands, now declared with Drush attributes under src/Drush/Commands, cover bulk maintenance: harvest register, run, run-all, revert, archive, publish and cleanup ..., datastore import, drop, drop-all and localize ..., reimport and purge-all ..., dkan:metastore:publish" |

No demand row. The row is `build` under the rule "two or more competitors rated yes".

## What changes

Five `occ` commands, each a thin caller of a service that already does the work:

- `opencatalogi:directory:sync` syncs every directory now, or one with `--url`.
- `opencatalogi:directory:broadcast` announces this directory to the known directories now.
- `opencatalogi:retention:evaluate` runs the retention evaluation now, with `--dry-run` to report without acting.
- `opencatalogi:woo:readiness` runs the Woo-index readiness check and prints each check, exiting non-zero on failure.
- `opencatalogi:catalog:cache` rebuilds or clears the cache of one catalogue or all of them.

Every command prints what it did in plain lines and supports `--output=json` for scripts.

## Rows this closes

| matrix | row id | row name | own rating | what is missing |
|---|---|---|---|---|
| opencatalogi | `int-cli` | Run bulk maintenance from the command line. | partial | no general maintenance commands |

## Existing work it builds on

- The two existing commands under `lib/Command/` and their registration in `appinfo/info.xml`.
- The background jobs `DirectorySync`, `Broadcast` and `RetentionEvaluation`, and `WooReadinessService` (main spec `woo-compliance` WOO-HR-001), whose services the commands call.
- ADR-069: background job and repair conventions. The commands and the jobs share one service call each, so both paths behave the same.

## Out of scope

- Commands that create or edit publications. The OpenRegister API and its own commands cover objects.
- A search index rebuild. OpenRegister owns the index.
