# Design: data-file-import-failure-alert

## D1. Declaration, not dispatch

ADR-031: notifications are declared on the schema and OpenRegister's `AnnotationNotificationListener` dispatches them. OpenCatalogi calls no notification manager. In `lib/Settings/register.d/open-data-tables.json` (the fragment `open-data-table-query-and-dictionary` adds), schema `publishedTable`:

```json
"x-openregister-notifications": {
  "table-import-failed": {
    "trigger": {"type": "updated", "condition": {"field": "status", "operator": "equals", "value": "failed"}},
    "enabled": true,
    "channels": ["nc-notification"],
    "recipients": [{"kind": "field", "field": "importedBy"}, {"kind": "object-acl", "permission": "manage"}],
    "subject": {"nl": "Tabel {{title}} kon niet worden ingelezen", "en": "Table {{title}} could not be imported"},
    "message": {"nl": "{{lastError}}", "en": "{{lastError}}"}
  }
}
```

The `updated` condition (`equals`, `field`, `value`) is evaluated by `AnnotationNotificationDispatcher::fieldChangeConditionMatches()` on openregister development. A table created directly as `failed` would not match `updated`; `PublishedTableService` therefore always creates the table as `importing` first and updates it to `ready` or `failed`. A second failure of the same table (`failed` to `failed` after a retry) matches again because the retry first sets `importing`.

The action link opens `PublicationDetail` with the Tables section; the notification's object link is the table object, whose page redirects to its publication.

## D2. Who started it

`importedBy` (string, user id) is set by `PublishedTableService` from the session on Publish as table and Retry, and from the attachment's last modifier on a re-import after a replaced file. Recipients are deduplicated by OpenRegister.

## D3. Background imports

`lib/BackgroundJob/PublishedTableImportJob.php` (QueuedJob) runs the import of `open-data-table-query-and-dictionary` for a file over 5,000 rows, a constant in `PublishedTableService`. A listener on OpenRegister's file update event for a publication attachment that has a `publishedTable` queues a re-import. The job catches every Throwable and stores `failed` with its message, so a crash also alerts; it never leaves a table in `importing`. A table in `importing` for more than an hour is set `failed` with "the import stopped without an answer" by the same job's next run.

## D4. Screen

No board draws the Tables section yet (board `OcPublicatie` has Attachments, not Tables); `open-data-table-query-and-dictionary` task 4.1 adds the section. This change adds to it: a failed table shows the chip "Import failed", `lastError`, the time, and Retry. The notification is Nextcloud's own UI.

## D5. Tests

Schema test on the declaration with OpenRegister's `NotificationAnnotationValidator` (skipped with a named reason without OpenRegister); service test that a failed preview writes `importing` then `failed` with `importedBy`; job test that a Throwable ends `failed`; e2e that a CSV with a bad row shows the failure and a notification appears for the editor.
