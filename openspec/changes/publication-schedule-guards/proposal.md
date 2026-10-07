---
kind: code
depends_on: [publication-lifecycle-on-or, integration-publish-by-reference]
---

# Proposal: publication-schedule-guards

## Summary

An embargoed publication cannot be published early except by a named right that is logged, the officer is warned before a depublication date takes a record down, and a publication without any document is refused.

- Rows: 5.11, 5.13, 5.21.
- Wave: 2.
- Depends on: `opencatalogi/publication-lifecycle-on-or` (https://github.com/ConductionNL/opencatalogi/issues/1754); `opencatalogi/integration-publish-by-reference` (https://github.com/ConductionNL/opencatalogi/issues/1631, built as written; until it lands the reference count is 0).
- Decision: D5, row 5.11 (a refusal on the publish action, overridable only by a named right and logged); amends REQ-PPW-002.
- Build rules: openspec/woo-build-rules.md

## Why

A publication date in the future already keeps a record off every public surface (RET-001). But it is a schedule, not a hold. Anyone who may update the record can press Publish now (REQ-PPW-002) or write an earlier date, and the record is out before the embargo. A depublication date is equally silent: nothing tells the officer that the record will disappear by itself. And nothing stops an officer from publishing a record that carries no document at all.

Rows, from `opencatalogi/_round1/compare/M1-rows.md`, with our column from `baseline/openwoo.tsv`:

- **5.11** "An embargo holds a record until a date, and nothing leaks before it". Ours: partial, production. Evidence: "#publication.publicationDate accepts a future moment and lib/Service/Publication/PublicationStateService.php holds the record until then. There is no embargo as a distinct state with its own guard, so an officer who publishes by hand bypasses the date".
- **5.13** "A record depublishes itself at a moment set in advance, and the officer is warned when that is on". Ours: partial, production. Evidence: "#publication.depublicationDate plus lib/Service/Publication/PublicationStateService.php hold a future date; nothing warns an officer that automatic depublication is on".
- **5.21** "The product warns before a record with no documents is published". Ours: no. Evidence: "opencatalogi src/views/publications/PublicationDetail.vue shows 'No attachments added yet' on the detail page, and lib/Service/QualityService.php scores metadata quality but never gates publishing and does not count documents; nothing warns at the moment of publishing".

Read on development at 35999c296: `PublicationStateController::publish()` refuses only a public or archived publication, so a scheduled one is published at once. The dialog is `src/dialogs/publication/PublishPublicationDialog.vue`; the state widget is `src/components/widgets/PublicationVisibilityWidget.vue`.

## What changes

- A publication whose `publicationDate` lies in the future is under embargo. While it is, the publish action and any write that moves `publicationDate` earlier are refused. Moving the date later, or clearing it back to a draft, is allowed, because neither can leak.
- One named right lifts an embargo: `liftEmbargo`, granted to the groups in the app config `publication_embargo_lift_groups` (default: `admin`). Lifting takes a reason and writes one audit entry. There is no second visibility check: the guard sits on the write, and visibility stays RET-001's one predicate (decision D5).
- A publication with a depublication date shows "Automatic depublication on <date>" on its page and as a marker on the list. Saving a depublication date answers with a warning the page shows. Seven days before the date, the users who may manage the publication get a Nextcloud notification, declared in the schema's `x-openregister-notifications` as a `scheduled` rule.
- The publish dialog counts the publication's documents: attached files plus document references (`integration-publish-by-reference`). With zero it warns and asks for a second confirmation. The publish API refuses a publication with zero documents unless the call says `confirmNoDocuments: true`.

## Fail closed

- The embargo guard refuses when it cannot read the stored `publicationDate` or cannot resolve the caller's groups. Unpublished is the safe state.
- A lift without a reason, or by a user outside the configured groups, is refused and writes nothing.
- When the document count cannot be read, the publish dialog and the API treat the count as zero and ask for the confirmation. They never treat an unknown count as "has documents".
- The batch publish (`BatchPublicationWriter`) always publishes with documents (REQ-WBP-001), so it is not asked to confirm; when its document list is empty it refuses as it does today.

## Out of scope

- A separate embargo date field. The future publication date is the embargo, as the row and the plan entry say.
- Changing which surfaces a scheduled record is absent from. RET-001 already holds that.
- Email reminders. The reminder is a Nextcloud notification on OpenRegister's notification engine.

## Dependencies

- `publication-lifecycle-on-or` (opencatalogi, planned, wave 1): the publish action is the `publish` and `publishWithoutReview` transitions. This change adds a guard to both.
- `integration-publish-by-reference` (opencatalogi, open change built as written in this programme, 0 of 9 tasks): its `documentReference` schema is counted as a document. Until it lands, the count is files only and the reference count is 0; the code reads the schema when it is present.
- OpenRegister, on development: the `scheduled` notification trigger with the `withinNext` filter operator and per-object dedupe (`ScheduledNotificationJob`, `ScheduledFilterEvaluator`), `AuditTrailMapper::createAuditTrail()`.

## Wave

Wave 2. It guards the transitions `publication-lifecycle-on-or` introduces in wave 1.

## Decisions

- D5, row 5.11: "Row wins as a refusal on the publish action for an embargoed record, overridable only by a named right and logged. No second visibility check." Implemented as written. This amends REQ-PPW-002, quoted in full under `## MODIFIED Requirements`: Publish now is no longer offered for a scheduled publication to a user without the lift right.

## Rows

| row | text | rating today | what makes it yes |
|---|---|---|---|
| 5.11 | An embargo holds a record until a date, and nothing leaks before it | partial | REQ-PSG-001, scenarios "Publish now is refused under embargo" and "An earlier date is refused under embargo"; REQ-PSG-002 "A lift is logged" |
| 5.13 | A record depublishes itself at a moment set in advance, and the officer is warned when that is on | partial | REQ-PSG-003, scenarios "Automatic depublication is shown" and "The officer is reminded before the date" |
| 5.21 | The product warns before a record with no documents is published | no | REQ-PSG-004, scenario "Publishing with no documents asks twice" |
