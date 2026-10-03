---
kind: code
depends_on: []
---

# Proposal: federation-connection-last-success

## Why

An administrator who looks at the directory page sees a coloured dot per federated catalogue, but not when it last worked or why it failed. The stored `lastSync` time does not help: it is written on every attempt, failed or not, so it says when the app last tried, not when it last succeeded.

opencatalogi matrix, row `fed-status`, "See whether each federation connection works and when it last succeeded." Own rating partial.

- Own evidence: "DirectoryService.php:862,912 store lastSync + statusCode, :1497 on error; FederationDirectory.vue:149-185 shows up/degraded/down dot and HTTP status per listing, grep lastSync in the view: 0; ConnectionReporter.php reports sync/broadcast to integriq (connections.json)".
- Note: "Whether each connection works is visible per listing, but when it last succeeded is stored (lastSync) and not shown on the directory page."

What the competitors show, quoted from the matrix:

| system | rating | evidence |
|---|---|---|
| CKAN | yes | "via ckanext-harvest v1.6.2: each source has a job list with status, start and finish time and error counts (ckanext/harvest/templates/source/job/list.html:22-43, route views.py:78-80 /harvest/<source>/job), a last-job page (views.py:82-84 job/last) and a per-job error summary" |
| DKAN | yes | "/admin/dkan/harvest lists every harvest source with its extract status, last run time and dataset count (modules/dkan_harvest/src/HarvestPlanListBuilder.php:73-76,106-113 ...), with per-run detail from drush dkan:harvest:status and dkan:harvest:info ... and GET /api/1/harvest/runs/{id}" |

No demand row. The row is `build` under the rule "two or more competitors rated yes".

## What changes

- Each listing records when it last synchronised successfully, separately from when the app last tried, and keeps the last error message.
- The directory page shows, per listing, the last successful sync, the last attempt, and the error when the last attempt failed.
- An administrator can start a sync of one listing from the page and see the result on the same row.

## Rows this closes

| matrix | row id | row name | own rating | what is missing |
|---|---|---|---|---|
| opencatalogi | `fed-status` | See whether each federation connection works and when it last succeeded. | partial | the last success is not recorded apart from the last attempt and is not shown |

## Existing work it builds on

- Main spec `dashboard`, DIR-003 (sync one listing, admin only), DIR-004 (hourly cron sync), DIR-010 (staleness during sync) and DIR-012 (directory management UI).
- Open change `dashboard` (DIR-010 staleness) reads `lastSync`; this change keeps `lastSync` as the attempt time so that logic is unchanged.
- Open change `adopt-connection-registry` reports sync and broadcast to integriq's Integrations page. That report stays; this change puts the per-listing answer where the administrator manages listings.

## Out of scope

- A history of every run. The row asks for the last success; a run log belongs with `harvest-observability`.
- Alerts when a listing fails. Integriq's connection registry already carries the connection state.
