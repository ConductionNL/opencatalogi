---
kind: code
depends_on: []
---

# Proposal: publication-lifecycle-on-or

## Summary

A publication gets one declared lifecycle on OpenRegister's engine (draft, in review, approved, published, archived) that an editor can follow and a caller can reconcile against, plus an unlisted state that keeps a public record off every machine surface while its link works.

- Rows: 5.9, 5.16, 5.17, 9.21. Row 5.14 moved to the follow-up `opencatalogi/publication-lifecycle-on-or-draft-purge` (https://github.com/ConductionNL/opencatalogi/issues/1796, split off 2026-10-06).
- Wave: 1.
- Depends on: nothing to build. Uses OpenRegister on `development`: `TransitionEngine`, `LifecycleValidationListener`, `LifecycleActionExecutor`, the `immutable` keyword. `publication-schedule-guards`, `publication-withdrawal-aftercare` and `publication-relations-place-and-source-ids` build on it.
- Decision: none of D1 to D13 implemented; RET-001's single visibility predicate is extended, not duplicated (D5 context).
- Build rules: openspec/woo-build-rules.md

## Why

The state of a publication is spread over three places and none of them is a lifecycle an editor can follow. `publication.status` is a two-value enum (`published`, `archived`) with one declared transition (`archive`) in `lib/Settings/publication_register.json`. Its initial value is `published`, so a publication nobody published still reads as published. The richer states exist only as dates (`PublicationStateService::stateOf()` derives draft, scheduled, public, withdrawn and archived from `publicationDate`, `depublicationDate` and `status`) and as two side records (`publicationProcess.state`, `wooBatch.status`) that nothing joins together.

Rows, from `opencatalogi/_round1/compare/M1-rows.md`, with our column from `baseline/openwoo.tsv`:

- **5.9** "The state of a record is visible: draft, in review, scheduled, public, gone". Ours: partial. Evidence: "opencatalogi #publication.status is only 'published' or 'archived'; the richer state lives in #publicationProcess.state and #wooBatch.status, so the state of a record is spread over three schemas".
- **5.16** "A state transition the product does not allow is refused by name, and the allowed transitions are published". Ours: no. Evidence: "opencatalogi #publication.status is a two-value enum with no transition table; nothing publishes allowed transitions or refuses one by name".
- **5.17** "A calling system can list exactly the records that are ready to publish, so it can reconcile its own state". Ours: partial. Evidence: "opencatalogi lib/Controller/PublicationsController.php lists publications with filters; nothing exposes a ready-to-publish set for a caller to reconcile against".
- **9.21** "A published record is kept out of search, the sitemaps and the national index while its link still works, and can be put back". Ours: no. Evidence: "Nearest: opencatalogi #publication.status 'archived' (PublicationQueryService:474-481, RET-006) removes the record from every public surface including its own link, and `searchable` in lib/Settings/publication_register.json is a whole-schema flag, not per record".

Read on development at 35999c296. Two facts differ from the evidence and change the design:

- `publicationProcess` is declared in `lib/Settings/register.d/publication-inspection-and-the-national-indexes.json`, but no code in `lib/` writes one (`git grep -n publicationProcess lib` finds only the schema key in `SettingsService`). Computing "in review" from it would compute from nothing. This change makes "in review" a stored lifecycle state instead.
- A `wooBatch` creates its publication only at the moment of publishing (`BatchPublicationWriter`, line 304 writes `publicationDate: $now`). A batch in review has no publication yet, so the batch status cannot be a publication state. It stays the batch's own state.

## What changes

- The publication schema declares one lifecycle on OpenRegister's engine (`x-openregister-lifecycle`, OpenRegister `object-lifecycle` REQ-007 and the list-form guard): stored states `draft`, `in_review`, `approved`, `published`, `archived`, initial `draft`, with named transitions `submit`, `approve`, `reject`, `retract`, `publish`, `publishWithoutReview` and the existing `archive`.
- The public states are not stored. Scheduled, public and withdrawn derive from the dates while the stored state is `published`, exactly as `stateOf()` reads them today. So the lifecycle derives from the publication dates rather than competing with them.
- The one public read rule gains one clause: a publication is public only while its stored state is `published` and its dates say so. This is RET-001's predicate, extended, not a second visibility check.
- Every path that writes `publicationDate` today moves the lifecycle instead: `PublicationStateController::publish()`, `EventService::publishObject()`, `BatchPublicationWriter`, and the mass publish modal `src/modals/object/MassPublishObjects.vue`.
- The state is one value, shown on the publications list and the detail page, served by the server.
- The permanent delete of a never-released draft (row 5.14) is the follow-up change `publication-lifecycle-on-or-draft-purge`, split off on 2026-10-06 to keep this change at 20 tasks.
- An authenticated endpoint lists exactly the publications in state `approved`, filterable by case reference and since.
- A per-publication `unlisted` flag keeps a public record out of search, the DiWoo sitemaps, DCAT, PLOOI delivery and the federation list while its own link keeps working, and clearing it puts the record back.
- A repair step backfills the stored state on existing publications and rewrites the read rule on installs where the schema import is version-gated.

## Fail closed

- A publication whose stored state is not `published` is not public, whatever its dates say. A draft with a past `publicationDate` stays private.
- A legacy publication with an empty `status` is not public until the repair step has classified it. The repair runs post-migration, so the window is the upgrade itself.
- An unlisted publication is still refused by PLOOI delivery. Unlisting never sends a withdrawal, because the record is not withdrawn.

## Out of scope

- Writing `publicationProcess` records. Nothing does today, and this change does not start.
- A per-catalogue choice of which transitions exist. One lifecycle serves every catalogue; whether review is required is one admin setting.
- The embargo guard on the publish action (`publication-schedule-guards`).
- Tombstones and frozen withdrawals (`publication-withdrawal-aftercare`).
- Filtering the ready list by source identifier. `publication-relations-place-and-source-ids` adds `sourceIdentifiers` and that filter.

## Dependencies

None to build first. It uses what OpenRegister ships on development at 1dc6a46:

- `TransitionEngine::transition(string $objectId, string $action, array $data = []): ObjectEntity` and `availableActions(string $objectId): array` (`lib/Service/Lifecycle/TransitionEngine.php`), routes `POST /api/objects/{id}/transition` and `GET /api/objects/{id}/available-actions`.
- `LifecycleValidationListener` (refuses a list-form edit no transition allows, code `lifecycle-invalid-transition`), `LifecycleActionExecutor` with the built-in `set-field` action and its `@now` token.
- The `immutable` property keyword from `object-archive-state` REQ-OAS-005 (built, tasks C29.3 ticked).
- `ObjectService::deleteObject(..., bool $permanent = false)` and `AuditTrailMapper::createAuditTrail(?ObjectEntity $old, ?ObjectEntity $new, ?string $action, ?array $cascadeContext)`.

`publication-schedule-guards`, `publication-withdrawal-aftercare` and `publication-relations-place-and-source-ids` build on this change.

## Wave

Wave 1. Three wave 2 changes read the stored state this change introduces, and it needs nothing that is not on development.

## Decisions

- D5 (5.11 and 5.5 rows) is not implemented here, but this change keeps RET-001's rule that there is one visibility predicate. It amends RET-001 by extending that predicate, quoted in full under `## MODIFIED Requirements`.
- None of D1 to D13 is implemented by this change.

This change supersedes the state list of REQ-PPW-001 (`publications` spec) and amends RET-001 (`publication-retention-lifecycle` spec). Both are quoted in full under `## MODIFIED Requirements`.

## Rows

| row | text | rating today | what makes it yes |
|---|---|---|---|
| 5.9 | The state of a record is visible: draft, in review, scheduled, public, gone | partial | REQ-PLC-002, scenario "One state on the list and the page" |
| 5.16 | A state transition the product does not allow is refused by name, and the allowed transitions are published | no | REQ-PLC-001, scenarios "An illegal move is refused by name" and "The allowed moves are published" |
| 5.17 | A calling system can list exactly the records that are ready to publish, so it can reconcile its own state | partial | REQ-PLC-005, scenario "A source system reconciles" |
| 9.21 | A published record is kept out of search, the sitemaps and the national index while its link still works, and can be put back | no | REQ-PLC-006, scenarios "Unlisted, still reachable" and "Put back" |
