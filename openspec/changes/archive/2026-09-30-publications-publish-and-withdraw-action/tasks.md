# Tasks: publications-publish-and-withdraw-action

## 1. Preconditions

- [x] 1.1 Confirm `CnDetailPage` `headerActions` with `visibleWhen` `endpoint` mode exists in the `@conduction/nextcloud-vue` version the app resolves (REQ-PPW-002). Verify: a note in the PR naming the version and file.

## 2. Server

- [x] 2.1 Add `GET /api/publications/{id}/visibility` (REQ-PPW-001). Verify: `tests/Unit/Controller/PublicationVisibilityControllerTest.php`, one case per state.
- [x] 2.2 Add `POST /api/publications/{id}/publish` and `/withdraw` with the object update right checked, the withdraw path writing `depublicationDate` after `DepublicationService::depublish()` (REQ-PPW-002, REQ-PPW-003). Verify: controller test including a user without update rights and a second withdraw answering 409.
- [x] 2.3 Add `depublication.file` and `POST /api/publications/{id}/files/{fileId}/withdraw` (REQ-PPW-004). Verify: controller test asserting the file's depublished time and the stored depublication.

## 3. Screen

- [x] 3.1 Declare Publish now, Withdraw and Publish again in `PublicationDetail.config.headerActions`, and the per-document withdraw in the Attachments section (REQ-PPW-002, REQ-PPW-004). Verify: `tests/e2e/publish-and-withdraw.spec.ts` withdraws with a reason, sees Publish again, publishes again.

## 4. Seed data, docs and strings

- [x] 4.1 Add the withdrawn seed publication and its depublication (REQ-PPW-003). Verify: fresh install shows Publish again on it.
- [x] 4.2 Document the actions in `docs/` and add the strings to `l10n/` in English and Dutch. Verify: `npm run lint` and the l10n check.
