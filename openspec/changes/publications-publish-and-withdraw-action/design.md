# Design: publications-publish-and-withdraw-action

Read at opencatalogi development `1694b051` and `@conduction/nextcloud-vue` development (the app requires `^2.57.1`, `package.json:45`).

## Where it lands

| piece | file | what is there now |
|---|---|---|
| publication page | `src/manifest.json` page `PublicationDetail`, config `lifecycleActions: {field: status}` | only the Archive transition |
| lifecycle | `lib/Settings/publication_register.json:218` `x-openregister-lifecycle` on `status`: `published` to `archived`, `archived` final | no way back |
| public or not | `publicationDate` and `depublicationDate` on the publication schema; OpenRegister's published predicate evaluates them | an editor types the dates |
| depublish | `appinfo/routes.php:82` `POST /api/publications/depublish` to `lib/Controller/DepublicationController.php` `depublish()`, `#[AuthorizedAdminSetting]`, reads the whole publication from the request body | stores a `depublication` object, sends withdrawals; the publication itself is not changed |
| service | `lib/Service/Publication/DepublicationService.php:77` `depublish()` refuses an empty reason, calls `NationalIndexService::withdraw()` per channel (:108), records not delivered on `IndexUnreachableException` | reusable as is |
| depublication schema | `lib/Settings/register.d/publication-inspection-and-the-national-indexes.json`, `depublication`: `publication`, `reason`, `depublishedBy`, `depublishedAt`, `withdrawals[]` | no document field |
| header actions | nextcloud-vue `CnDetailPage` prop `headerActions` (`api-call`, `open-form`, `toggle`, `navigate`, each with `visibleWhen`); `visibleWhen` has an `endpoint` mode that reads a field from a same-origin JSON endpoint (`src/utils/visibleWhen.js`) | not used on this page |

## D1. The server says what state a publication is in

`GET /api/publications/{id}/visibility` answers `{state}` with one of `draft` (no `publicationDate`), `scheduled` (a future `publicationDate`), `public`, `withdrawn` (a past `depublicationDate`) or `archived`. The header actions gate on it through `visibleWhen` `endpoint` mode, so the page never recomputes date logic in the browser.

## D2. Three actions, one menu

Declared in `PublicationDetail.config.headerActions`:

- Publish now (`api-call`, `POST /api/publications/@objectId/publish`, confirm), visible when the state is `draft` or `scheduled`. Sets `publicationDate` to now and clears `depublicationDate`.
- Withdraw (`open-form` with a required reason field, `POST /api/publications/@objectId/withdraw`), visible when the state is `public` or `scheduled`. Calls `DepublicationService::depublish()` with the channels the publication was delivered to, stores the `depublication` as today, then sets the publication's `depublicationDate` to now. Answers the outstanding channels, which the toast names.
- Publish again (`api-call`, confirm), visible when the state is `withdrawn`. Same as Publish now; the earlier `depublication` object stays as history.

Archive stays a lifecycle transition for the retention flow. It is not a way to withdraw.

## D3. The id comes from the route, the rights from the object

The new endpoints load the publication by id with RBAC on, not from the request body. They are `#[NoAdminRequired]` and refuse a user who cannot update that publication (ADR-005, fail closed; the check is OpenRegister's authorisation on the object, gate-7 IDOR rule). The existing admin-only `POST /api/publications/depublish` stays for API callers and is untouched.

## D4. One document at a time

`POST /api/publications/{id}/files/{fileId}/withdraw` with a reason: sets the file's `depublished` time through OpenRegister's file depublish endpoint, then stores a `depublication` with a new optional `file` property (the file id). The DiWoo sitemap already skips a file that is not public, because it lists files through OpenRegister's `FileService`. The Attachments section is OpenRegister's files integration; the per-file action is added there as a row action if the integration offers one, else as an `open-form` header action with a file picker. Task 3.1 decides which by reading the integration on the pinned version.

## Declarative or imperative

- The three actions and their visibility are declared in the manifest (`headerActions`, `visibleWhen`).
- `depublication.file` is a schema property.
- The endpoints are imperative because they call the national channels (ADR-031 exception: external integration) and write two objects in one step.

## Seed data

The second seed publication gets a past `depublicationDate` and one `depublication` with a reason, so a fresh install shows Publish again. Both seed publications live in `lib/Settings/publication_register.json`.

## Risks

- `headerActions` on `CnDetailPage` may be newer than the pinned library version. Task 1.1 checks it first; if absent, the change waits for the library bump rather than adding a custom component.
- Two editors withdraw at once. The second call finds the state `withdrawn` and answers 409 without a second depublication.
