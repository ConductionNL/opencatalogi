---
kind: code
depends_on: []
---

# Proposal: publication-detail-for-the-portal

## Summary

The public endpoints give portaliq's citizen detail page what it needs: an officer-set document order, a stable document id with its own metadata routes, the comment period state, opt-in withheld documents with grounds, and an opt-in, throttled error report channel.

- Rows: 6.25; supports 6.15, 6.16, 6.19, 6.36, 7.20, which close in portaliq.
- Wave: 1.
- Depends on: nothing to build. Consumers: `portaliq/publication-detail-page-complete` (https://github.com/ConductionNL/portaliq/issues/1220) and `portaliq/publication-error-reports-and-withheld-notices` (https://github.com/ConductionNL/portaliq/issues/1221) test against REQ-PDP-002 to REQ-PDP-005. `opencatalogi/woo-decision-shows-what-was-withheld` (https://github.com/ConductionNL/opencatalogi/issues/1797) extends `withheld` to Woo decisions published from dossiq.
- Decision: D9 (`showWithheld` and `acceptErrorReports` are opt-in per catalogue, off by default); D11 (a document's own page is the target of a content hit).
- Build rules: openspec/woo-build-rules.md

## Why

The citizen's publication page lives in portaliq and reads OpenCatalogi's public endpoints. Five things that page needs are not in those endpoints, so portaliq cannot build them however it tries. This change is the OpenCatalogi half: it closes one row itself and gives portaliq the data for five more.

Rows, from `opencatalogi/_round1/compare/M1-rows.md`, with our column from `baseline/openwoo.tsv`:

- **6.25** (closed here) "Documents on a record have a stable, meaningful order the citizen sees, and can be reordered by the citizen". Ours: partial, production. Evidence: "PublicationDetailBlock renders opencatalogi's attachment list in the order toDocuments received it, which is the order the array was written in. Nothing lets the citizen reorder it, and nothing lets the officer either; there is no order field on an attachment and no drag handle on the detail page".
- Supported here, closed in portaliq: **6.19** "A document has a public page of its own that links back to its publication" (partial: "A DOCUMENT still has none ... nothing links a document page back to its publication"); **6.36** "A citizen downloads a document's metadata in an open format beside the file" (partial: "No citizen page offers a document's metadata for download next to the file"); **7.20** "The portal tells the citizen whether the reaction period has not started, is open, or has passed" (no: "none of what opencatalogi PR #1736 stored reaches a visitor"); **6.16** "The portal shows that a withheld record exists, and why it is withheld" (partial: "nothing publishes the existence of a withheld record"); **6.15** "A citizen reports an error in a publication, and it reaches someone" (partial: "opencatalogi ships no portal contribution with a proposable action for publications ... an anonymous visitor reading /publicatie has no way to report an error on it").

Read on development at 35999c296. `PublicationService::attachments()` serves `GET /api/{catalogSlug}/{id}/attachments` and `FederationController::publicationAttachments()` the federation twin. `CommentPeriodService::publicView()` already derives the three states (REQ-PCP-004) and nothing on the federation endpoint returns it. `wooAssessment` holds `assessment` (with `niet_openbaar`) and `weigeringsgronden` per document; a `wooBatch` records the publication it created in `wooPublication.publication`. `PortalContributionProvider::getContribution()` declares dossier and saved-search actions only, each run as the asserted subject.

## What changes

- Attachments carry an order. The publication stores `attachmentOrder` (a list of file ids). The officer sets it with move up and move down buttons that work by keyboard, as `keyboard-operable-reorder-controls` does for page blocks. The public and federation attachment lists answer in that order with a `position`, then any file not in the list by upload time.
- Each attachment answers with a stable `documentId` (its Nextcloud file id) and its own metadata. Two new public routes serve one document: its metadata as JSON, and its `diwoo:Document` record as XML, both linking back to the publication.
- The public and federation publication responses carry `commentPeriod`: the `publicView()` of the publication's comment period, when it has one.
- Where the catalogue opts in (`showWithheld`, off by default, decision D9), the public publication response carries `withheld`: per withheld document its position, its grounds as article references, and its title only when the assessment marks the title as public.
- Where the catalogue opts in (`acceptErrorReports`, off by default, decision D9), an anonymous, throttled route takes an error report on a publication. It lands as an `errorReport` the publishing team sees, and the catalogue names who moderates it.

## Fail closed

- Every new public field and route reads through the same anonymous public read as the publication. A document of a non-public publication, a withdrawn file or a draft answers 404.
- `withheld` is absent unless the catalogue opted in. A withheld document's title is absent unless its assessment says `titlePublic: true`. No file, no hash, no reference to the stored original is ever returned for a withheld document.
- The error report route answers 404 unless the catalogue opted in and named a moderator. It is rate limited per address, caps the message at 2,000 characters, stores no address, and never publishes the report.
- A comment period whose dates cannot be read returns no `commentPeriod`, as `CommentPeriodService::state()` refuses to guess; the response says `commentPeriodError` instead of a wrong state.

## Out of scope

- The portal blocks and pages: `portaliq/publication-detail-page-complete` (6.19, 6.36, 7.20) and `portaliq/publication-error-reports-and-withheld-notices` (6.15, 6.16).
- A visitor-side sort of the list (optional in the row; portaliq may add it over `position`).
- Tombstones of withdrawn publications: `publication-withdrawal-aftercare`.

## Dependencies

- None to build. Consumers in this programme: `portaliq/publication-detail-page-complete` and `portaliq/publication-error-reports-and-withheld-notices` (both wave 2). The contracts they must match are in REQ-PDP-002 to REQ-PDP-005, and each needs its own test against these keys.
- dossiq absent: nothing changes. Withheld grounds are read from the stored assessment, not looked up.

## Wave

Wave 1. Two portaliq changes in wave 2 need it.

## Decisions

- D9 (6.15, 6.16): "build all three as opt-in per organisation, off by default". Implemented for the two OpenCatalogi halves: both are catalogue settings, off by default, and the error channel is throttled with a named moderator.
- D11: a document's own page is the target of a content hit. `documentId` and the per-document route give that page its data.

## Rows

| row | text | rating today | what makes it yes |
|---|---|---|---|
| 6.25 | Documents on a record have a stable, meaningful order the citizen sees, and can be reordered by the citizen | partial | REQ-PDP-001, scenario "An officer orders the documents by keyboard and the portal gets that order" |
| 6.19, 6.36 | (supported) | partial | REQ-PDP-002 gives the data; portaliq closes them |
| 7.20 | (supported) | no | REQ-PDP-003 gives the data; portaliq closes it |
| 6.16 | (supported) | partial | REQ-PDP-004 gives the data; portaliq closes it |
| 6.15 | (supported) | partial | REQ-PDP-005 gives the channel; portaliq closes it |
