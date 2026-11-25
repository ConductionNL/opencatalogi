---
kind: code
depends_on: []
---

# Proposal: woo-access-boundary-hardening

## Summary

The boundary between non-public Woo records and the public side is proven by tests, reads of non-public records are logged, the officer side stays out of search indexes, and inspection periods get their own right instead of app admin.

- Rows: 12.5, 12.9, 12.27, 12.32.
- Wave: 1.
- Depends on: nothing to build. Uses OpenRegister on `development`: `x-openregister-processing`, `PermissionsDeclaringEvent`, `CustomScopeEvaluatingEvent`, `PermissionHandler::hasPermission()`.
- Decision: D1 (the Woo request routes get no new rights, because they move to dossiq).
- Build rules: openspec/woo-build-rules.md

## Why

OpenCatalogi holds records that are not public: assessments with refusal grounds, documents waiting for redaction, drafts. The boundary between those and the public side is enforced today, and a probe showed it holds. But it is observed, not proven, nobody can say who read a non-public record, the officer side is not kept out of search indexes, and the only way to let someone open an inspection period is to make them an administrator of the whole app.

Rows, from `opencatalogi/_round1/compare/M1-rows.md`, with our column from `baseline/openwoo.tsv`:

- **12.5** "Every read of a non public record is logged". Ours: partial, production. Evidence: "openregister lib/Service/ObjectService.php calls ProcessingLogService::logRead on every object read, which writes a processing log entry when the object's schema declares x-openregister-processing logReads true. No opencatalogi schema declares it".
- **12.9** "The public side cannot reach a non public record, and a test proves it". Ours: partial, production. Evidence: "the probe recorded above shows the public API serving only published records and refusing the admin surfaces with 401. No test asserts the boundary, so the guard is observed rather than proven".
- **12.27** "The officer side and any acceptance environment are kept out of search engine indexes". Ours: no, roadmap. Evidence: "opencatalogi lib/Controller/RobotsController.php allows the API paths and names no officer path".
- **12.32** "A right to one capability, such as opening an inspection period, is granted per group". Ours: partial, production. Evidence: "opencatalogi lib/Controller/InspectionController.php::open is #[AuthorizedAdminSetting(OpenCatalogiAdmin)], so opening an inspection rides on Nextcloud core admin delegation of the whole OpenCatalogi admin section to a group, one bucket for every admin capability".

Read on development at 35999c296. OpenRegister on development at 1dc6a46 offers what this needs: per-schema read logging (`x-openregister-processing.logReads`, `ProcessingLogService::logRead()`, batched for lists, fail-soft), custom permission verbs an app declares (`PermissionsDeclaringEvent::declareVerb()`) and decides (`CustomScopeEvaluatingEvent`, fail closed on a listener error), and `PermissionHandler::hasPermission(Schema $schema, string $action, ?string $userId, ...)`. Twenty-two OpenCatalogi controller actions carry `#[AuthorizedAdminSetting]`, among them `InspectionController::open`, `CommentPeriodController::open`, `DepublicationController::depublish`, `PublicationDisclosureController::announce`, `RetentionController::decide`, the Woo batch actions and `WooRegistrationController::request`.

## What changes

- The non-public Woo schemas (`wooAssessment`, `wooBatch`, `depublication`, `wooRequest`) and `publication` declare `x-openregister-processing` with `logReads: true` and a processing activity OpenCatalogi seeds. A read of one of their objects writes a processing log entry, inquirable in OpenRegister's processing log.
- A boundary suite proves the public side: a PHPUnit test lists every `#[PublicPage]` route, seeds one object of every non-public kind (draft, scheduled, withdrawn, archived, unlisted where present, assessment, batch, depublication, a withdrawn file), and asserts no public route returns any of them; a Playwright run does the same anonymously against the CI seed.
- robots.txt disallows OpenCatalogi's officer paths, every non-public OpenCatalogi response carries `X-Robots-Tag: noindex, nofollow`, and an admin switch `search_indexing: blocked` (for acceptance and test installs) answers every surface with `noindex` and a disallow-all robots.txt while leaving the public side open.
- Eight capabilities become rights granted per group: `openInspection`, `openCommentPeriod`, `depublish`, `announceNationally`, `decideRetention`, `processWooBatch`, `publishWooBatch` and `requestWooRegistration`. OpenCatalogi declares them as OpenRegister verbs, grants live in the `catalog` schema's authorization block per group, and OpenCatalogi's listener decides them. The controllers check the verb instead of `#[AuthorizedAdminSetting]`. Administrators keep every right.

## Fail closed

- A capability check that cannot resolve the user's groups or the grant list refuses. A verb with no grant is refused for everyone but administrators.
- A public route added later is covered by the suite by default, because the suite reads the routes rather than a hand-kept list.
- The `noindex` header is added by a middleware on every OpenCatalogi response that is not a `#[PublicPage]` route, so a new officer route is covered by default.
- OpenRegister's read logging is fail-soft by design: it never blocks a read. This change proves the entry is written on the normal path and reports the count of fallback-attributed entries in the readiness check; it does not turn a logging failure into a refused read.

## Out of scope

- Changing OpenRegister's read logging. If a row reviewer requires reads to fail when logging fails, that is an OpenRegister change.
- Settings pages (`SettingsController`, notice board configuration, retention defaults). They stay administrator settings.
- The Woo request routes, which move to dossiq (decision D1).

## Dependencies

- None to build. Uses OpenRegister on development: `x-openregister-processing`, `PermissionsDeclaringEvent`, `CustomScopeEvaluatingEvent`, `PermissionHandler::hasPermission()`.
- `publication-schedule-guards` (wave 2) adds `liftEmbargo` and `theme-archive-hotspot` (wave 2) the hotspot marking; both should declare their rights the same way once this lands, and their specs say so where they check a group setting.

## Wave

Wave 1. It needs nothing new.

## Decisions

- D1: the Woo request routes are not given new rights, because they move to dossiq.

## Rows

| row | text | rating today | what makes it yes |
|---|---|---|---|
| 12.5 | Every read of a non public record is logged | partial | REQ-WAB-001, scenario "An officer reads an assessment and the read is logged" |
| 12.9 | The public side cannot reach a non public record, and a test proves it | partial | REQ-WAB-002, scenario "No public route returns a non-public record" |
| 12.27 | The officer side and any acceptance environment are kept out of search engine indexes | no | REQ-WAB-003, scenarios "Officer paths are not indexed" and "An acceptance install is not indexed" |
| 12.32 | A right to one capability, such as opening an inspection period, is granted per group | partial | REQ-WAB-004, scenario "A group may open inspection periods and nothing else" |
