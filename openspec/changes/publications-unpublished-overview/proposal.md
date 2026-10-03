---
kind: code
depends_on: []
---

# Proposal: publications-unpublished-overview

## Why

An editor wants to see what is ready but not yet public before it goes out. OpenCatalogi registers two Nextcloud dashboard widgets for exactly that, and both are always empty.

opencatalogi matrix, row `pub-unpublished`, "See on a dashboard which publications and attachments are not yet published." Own rating no, `built.state` built.

- Own evidence: "lib/AppInfo/Application.php:111-112 registers both widgets; src/views/widgets/UnpublishedPublicationsWidget.vue:76 filters status === 'Concept' but publication status enum is published|archived (publication_register.json:207); UnpublishedAttachmentsWidget.vue:96 fetches collection 'attachment', a type no longer provisioned (SettingsService.php:1185-1191)".
- Note: "Dead: both widgets filter on a 'Concept' status no current record can carry. Row click also needs a catalog FK that publications do not have (UnpublishedPublicationsWidget.vue:110)."

What the competitors show, quoted from the matrix:

| system | rating | evidence |
|---|---|---|
| CKAN | partial | "the user dashboard /dashboard/datasets (ckan/views/dashboard.py:31,66, template user/dashboard_datasets.html) lists the user's datasets with Private and Draft badges (templates/snippets/package_item.html:22-24,36). Missing half: not a Nextcloud dashboard widget, no filter" |
| DKAN | partial | "the admin list /admin/dkan/datasets has exposed filters for published status and moderation state (...views.view.dkan_dataset_content.yml:699-773, path :1543), so an editor can list drafts and archived items." |

No demand row. The row is `build` under the rule "in the core area" (publications) for a row rated no.

## What changes

- "Not yet published" is defined on the current model: a publication with no publication date or one in the future, and a document (a file on a publication) with no published time or one in the future.
- One server endpoint lists both, newest first, with counts, for the signed-in user's readable publications.
- The two dashboard widgets read that endpoint, and a row opens the publication in the app.
- The main dashboard spec's requirement DSH-011, blocked on an attachment schema that no longer exists, is rewritten around files.

## Rows this closes

| matrix | row id | row name | own rating | what is missing |
|---|---|---|---|---|
| opencatalogi | `pub-unpublished` | See on a dashboard which publications and attachments are not yet published. | no | both widgets filter on a status nothing carries and on a retired collection |

## Existing work it builds on

- Main spec `dashboard`, DSH-011 "Unpublished-content dashboard widgets", marked "Not implemented, blocked" and asking for "a design decision (promote attachments to a real OR schema, or redesign these widgets around the Files-metadata model)". This change takes the second path and modifies DSH-011.
- Open change `dashboard-consume-or-aggregations` names these widgets; its tasks for them are not done and wait for the `attachment` schema. This change supersedes those two tasks. Its other work (the KPI tiles) is untouched.
- Open change `attachments-are-files`: a document is a file on its publication with its own publication window (OpenRegister `FileMapper::setPublicationWindowForFile()`).

## Out of scope

- The app's own dashboard page (`DashboardView`). Only the two Nextcloud dashboard widgets change.
- Publishing from the widget. The row opens the publication, where `publications-publish-and-withdraw-action` offers Publish now.
