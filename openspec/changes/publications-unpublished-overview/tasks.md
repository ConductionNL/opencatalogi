# Tasks: publications-unpublished-overview

## 1. Server

- [ ] 1.1 Add `UnpublishedOverviewService` with the publications and documents lists, bounded as in D1 (REQ-PUO-001). Verify: `tests/Unit/Service/UnpublishedOverviewServiceTest.php` with a draft, a scheduled, a public and a withdrawn publication, and a file with a future published time.
- [ ] 1.2 Add `GET /api/dashboard/unpublished` with `kind` and `limit`, RBAC on (REQ-PUO-001). Verify: controller test, including a user who reads only one catalogue.

## 2. Widgets

- [ ] 2.1 Point `UnpublishedPublicationsWidget.vue` at the endpoint and fix the row link with `catalogSlug` (REQ-PUO-002). Verify: `tests/e2e/unpublished-widgets.spec.ts` on the Nextcloud dashboard opens the publication from a row.
- [ ] 2.2 Point `UnpublishedAttachmentsWidget.vue` at the endpoint with `kind=documents`, retitle it Unpublished documents (REQ-PUO-002). Verify: same e2e spec.

## 3. Specs, seed data, docs and strings

- [ ] 3.1 Mark tasks of `dashboard-consume-or-aggregations` that wait on the `attachment` schema as superseded by this change (REQ-PUO-002). Verify: `openspec validate dashboard-consume-or-aggregations --strict` still passes.
- [ ] 3.2 Give the second seed publication a future `publicationDate` (REQ-PUO-001). Verify: a fresh install lists it in the widget.
- [ ] 3.3 Update `docs/` and `l10n/` (English and Dutch) for the new titles and empty states. Verify: `npm run lint` and the l10n check.
