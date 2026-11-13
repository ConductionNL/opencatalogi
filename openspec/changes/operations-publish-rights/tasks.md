# Tasks: operations-publish-rights

## 1. Schema

- [x] 1.1 Add property-level update authorization to `publicationDate` and `depublicationDate` (REQ-OPR-001). Verify: a test that saves a real publication as an editor and as a publisher against the real schema fragment and OpenRegister's `PropertyRbacHandler`.
  - Done: `lib/Settings/publication_register.json` (publication schema 0.0.6, register 0.7.2). `tests/Unit/Settings/PublishRightsSchemaTest.php` reads the shipped fragment and runs OpenRegister's own `PropertyRbacHandler::getUnauthorizedProperties()` on it: an editor's new date is refused, a publisher's is admitted, and a title change with the dates unchanged passes. That test skips with a named reason when OpenRegister is not installed next to the app.
- [x] 1.2 Create the group `opencatalogi-publishers` in a repair step, idempotent (REQ-OPR-004). Verify: `tests/Unit/Migration/CreatePublishersGroupTest.php`, run twice.
  - Done: `lib/Repair/CreatePublishersGroup.php`, registered under `<install>` and `<post-migration>`. It lives with the app's other repair steps, so the test is `tests/Unit/Repair/CreatePublishersGroupTest.php`, which runs the step twice.

## 2. Actions

- [x] 2.1 Add `canPublish` to `GET /api/publications/{id}/visibility` and gate the action `visibleWhen` on it (REQ-OPR-002). Verify: `tests/Unit/Controller/PublicationVisibilityTest.php` and `tests/e2e/publish-rights.spec.ts`.
  - Done: `PublishRight::mayPublish()` (publisher or admin) feeds `canPublish`. The actions are buttons in `PublicationVisibilityWidget`, not manifest `visibleWhen`, so they are gated through `visibilityActions()` in `src/services/publicationVisibility.js`. Covered by `tests/Unit/Controller/PublicationStateControllerTest.php` (the existing visibility tests) and `tests/vitest/publishRights.spec.js`.
  - [ ] e2e `tests/e2e/publish-rights.spec.ts`: needs a live stack with an editor and a publisher account.
- [x] 2.2 Make the publish and withdraw endpoints answer 403 for a caller outside the group (REQ-OPR-003). Verify: controller tests, three callers: editor, publisher, admin.
  - Done: `guardPublish()` runs after the existing guard (401 / 404 / update-right 403), then answers 403 `not-a-publisher` naming the group. Editor: `PublicationStateControllerTest`. Publisher: the existing publish/withdraw tests and the withdraw golden. Admin: `PublishRightTest::testAnAdministratorMayPublishOutsideTheGroup`. The single-document withdrawal is not one of the two endpoints and is unchanged.

## 3. Settings, docs, strings

- [x] 3.1 Add the settings line for the group and its member count (REQ-OPR-004). Verify: e2e spec from 2.1.
  - Done: `GET /api/settings/publish-rights` (`PublishRightsController`, admin setting) and `PublishRightsLine.vue` in the Woo section. Covered by `PublishRightsControllerTest` and `tests/vitest/publishRightsLine.spec.js`. The e2e check is the open item under 2.1.
- [ ] 3.2 English and Dutch strings, release note, docs, `openspec validate operations-publish-rights --strict`.
  - Done: en/nl strings (`l10n/*.json`, `.js` regenerated), the CHANGELOG `[Unreleased]` entry and `docs/features/publish-rights.md`. Open: `openspec validate`, because the CLI is not installed in the build container.
