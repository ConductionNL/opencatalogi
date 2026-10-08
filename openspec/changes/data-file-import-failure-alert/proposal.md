---
kind: code
depends_on: [open-data-table-query-and-dictionary]
---

# Proposal: data-file-import-failure-alert

## Summary

When a published data file fails to import into its table, the editor who started the import and the people who manage the publication get a Nextcloud notification that names the publication, the file and the first refused row. Nobody has to find it in a log. The alert is a declaration on OpenCatalogi's `publishedTable` schema, dispatched by OpenRegister.

## Why

Row `int-import-failure-alert`, "Be alerted when a published data file fails to import, instead of finding it in a log." State `specified`, ours `no`, owner integriq. Re-rated 7 October 2026: "integriq's alerting shipped for job errors, not for a failed data file import. The import itself is open in open-data-table-query-and-dictionary, which specifies no alert."

The delivered change is integriq's `integriq-notifications` (archived 2026-09-30, 9 of 9 tasks): integriq notifies on its own job errors through OpenRegister's `AnnotationNotificationListener`. That covers integriq's synchronisations, not a data file a publication publishes. The data file import is OpenCatalogi's: the open change `open-data-table-query-and-dictionary` turns a CSV attachment into a `publishedTable` and stores `status: failed` with the first refused row as `lastError` (its design, step 2; tasks 1.3). It sends no alert. So the missing part is OpenCatalogi's, on top of that change.

## What changes

- `publishedTable` gets `importedBy` (the user who started the import) and declares an `x-openregister-notifications` rule `table-import-failed`: trigger `updated` with the condition `status` equals `failed`, recipients the `importedBy` user and those who may manage the publication, channel `nc-notification`, subject and message in Dutch and English naming the publication, the file and `lastError`, with an action that opens the publication's Tables section.
- An import of a file larger than 5,000 rows runs as a background job, and a re-import runs when the CSV attachment is replaced. Those are the imports nobody watches, and the reason the alert exists.
- The Tables section on the publication page shows a failed table with its `lastError`, Retry, and the time of the failure.

## Rows

| row | name | ours | what this closes |
|---|---|---|---|
| `int-import-failure-alert` | Be alerted when a published data file fails to import, instead of finding it in a log. | no | the alert on a failed table import |

## Out of scope

- Alerts on integriq synchronisations: integriq's `sync-failed` rule, still `enabled: false` there. That is integriq's to switch on.
- Email. The rule declares `nc-notification`; an administrator can add `email` to the rule later, OpenRegister supports it.
