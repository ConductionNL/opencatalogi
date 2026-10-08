# Tasks: operations-publish-rights

## 1. Schema

- [ ] 1.1 Add property-level update authorization to `publicationDate` and `depublicationDate` (REQ-OPR-001). Verify: a test that saves a real publication as an editor and as a publisher against the real schema fragment and OpenRegister's `PropertyRbacHandler`.
- [ ] 1.2 Create the group `opencatalogi-publishers` in a repair step, idempotent (REQ-OPR-004). Verify: `tests/Unit/Migration/CreatePublishersGroupTest.php`, run twice.

## 2. Actions

- [ ] 2.1 Add `canPublish` to `GET /api/publications/{id}/visibility` and gate the action `visibleWhen` on it (REQ-OPR-002). Verify: `tests/Unit/Controller/PublicationVisibilityTest.php` and `tests/e2e/publish-rights.spec.ts`.
- [ ] 2.2 Make the publish and withdraw endpoints answer 403 for a caller outside the group (REQ-OPR-003). Verify: controller tests, three callers: editor, publisher, admin.

## 3. Settings, docs, strings

- [ ] 3.1 Add the settings line for the group and its member count (REQ-OPR-004). Verify: e2e spec from 2.1.
- [ ] 3.2 English and Dutch strings, release note, docs, `openspec validate operations-publish-rights --strict`.
