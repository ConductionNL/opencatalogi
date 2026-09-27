# Design: publications-unpublished-overview

Read at opencatalogi development `1694b051`; OpenRegister `FileMapper` read on OpenRegister development on 2026-09-27.

## Where it lands

| piece | file | what is there now |
|---|---|---|
| widget registration | `lib/AppInfo/Application.php:111-112` registers `lib/Dashboard/UnpublishedPublicationsWidget.php` and `UnpublishedAttachmentsWidget.php` (`IWidget`, `getUrl()` null, `load()` adds the bundle) | registered, always empty |
| widget bundles | `src/unpublishedPublicationsWidget.js`, `src/unpublishedAttachmentsWidget.js` mount `src/views/widgets/UnpublishedPublicationsWidget.vue` and `UnpublishedAttachmentsWidget.vue` | the first filters `status === 'Concept'` (:77) on the full `publication` collection; the second fetches the unprovisioned `attachment` collection (:73, :96); the row link needs a `catalog` field publications do not have (:111-120) |
| public or not | `lib/Service/PublicationQueryService.php:1019` `isObjectPublic()`: public when `publicationDate` is set and not in the future, and `depublicationDate` is empty or in the future | the rule to reuse |
| document window | OpenRegister `FileMapper`: `getFileIdsForObjects(array $uuids)` (:634), `getFilesByIds()` (:517), each file with `published` | per file, no cross-publication query |
| publication page route | `src/manifest.json` page `PublicationDetail`, route `/publications/:catalogSlug/:id` | needs a catalogue slug |

## D1. Not yet published, defined once on the server

`UnpublishedOverviewService` answers two lists for the current user:

- publications: readable publications (OpenRegister search with RBAC on) that `isObjectPublic()` says are not public and that have no past `depublicationDate` (a withdrawn publication is not "not yet published"), newest `@self.updated` first;
- documents: files of readable publications whose `published` is empty or in the future, found through `getFileIdsForObjects()` on the page of publications read, newest first.

Each list is capped (default 10, at most 50) and carries a total. The search is bounded (ADR-058): the documents list reads files of at most the 200 most recently updated publications, and says so in the response (`scope: recent-200`).

## D2. One endpoint for both widgets

`GET /api/dashboard/unpublished?kind=publications|documents&limit=10`, `#[NoAdminRequired]`, answers `{items, total, scope}`. Each item has `title`, `updated`, `publicationId` and the `catalogSlug` of the catalogue whose registers and schemas hold the publication (the same resolution `/api/federation/publications` uses), so the row link works without a `catalog` field.

## D3. The widgets read it

Both Vue widgets drop `objectStore.fetchCollection()` and call the endpoint. The empty state says "Everything you can see is published." Titles stay "Unpublished publications" and "Unpublished documents" (the second renamed from attachments, because a document is what an editor sees on the publication page). The PHP widgets keep their ids so a user's dashboard layout survives.

## Declarative or imperative

The two lists cross publications and files, and files are not OpenRegister objects, so an `x-openregister-aggregations` entry cannot count them (the reason DSH-011 was blocked). The endpoint is imperative, read-only and bounded. No schema change.

## Seed data

The second seed publication in `lib/Settings/publication_register.json` gets a `publicationDate` next year, so a fresh install lists one scheduled publication.

## Risks

- A user who can read many catalogues sees at most the capped list; the total says how many there are.
- Documents beyond the 200 most recent publications are not listed. The response says so, and the widget shows "Showing recent publications only" when `scope` is set.
