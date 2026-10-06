---
kind: code
depends_on: [publication-detail-for-the-portal, woo-value-lists-on-the-concept-register, dossiq/woo-refusal-grounds-list]
---

# Proposal: woo-decision-shows-what-was-withheld

## Summary

A citizen reading a Woo decision that dossiq published sees which documents were withheld and on which refusal ground, without their content, where the organisation opts in.

- Rows: supports 6.16 (its second half: withheld documents with their grounds on the public read of a Woo decision); `portaliq/publication-error-reports-and-withheld-notices` (https://github.com/ConductionNL/portaliq/issues/1221) shows them and closes the row.
- Wave: 3.
- Depends on: `opencatalogi/publication-detail-for-the-portal` (https://github.com/ConductionNL/opencatalogi/issues/1761) for the `showWithheld` opt-in and the `withheld` response key (REQ-PDP-004); `opencatalogi/woo-value-lists-on-the-concept-register` (https://github.com/ConductionNL/opencatalogi/issues/1780) for `RefusalGrounds`; `dossiq/woo-refusal-grounds-list` (https://github.com/ConductionNL/dossiq/issues/3288) for `WooRefusalGrounds::byCode()`. dossiq's caller of `WithheldDocuments::record()` is `dossiq/woo-decision-records-what-was-withheld` (https://github.com/ConductionNL/dossiq/issues/3303), which sends exactly `{position, grounds}` per entry.
- Decision: D3 (the grounds are dossiq's; labels are taken from dossiq's list, never from a copy); D9 (showing that a withheld record exists is opt-in per organisation, off by default); D12 (no snapshot fallback on this path).
- Build rules: openspec/woo-build-rules.md

## Why

Row 6.16, from the Woo capability comparison: "The portal shows that a withheld record exists, and why it is withheld". Ours: partial, production. Evidence: "nothing publishes the existence of a withheld record". The plan places the row in portaliq (`publication-error-reports-and-withheld-notices`) and gives OpenCatalogi the data half in `publication-detail-for-the-portal` REQ-PDP-004.

REQ-PDP-004 reads OpenCatalogi's own `wooAssessment` objects of the `wooBatch` that created the publication. After decisions D1 and D12, a Woo decision is no longer made in OpenCatalogi. dossiq decides it and publishes it: `OCA\Dossiq\Service\WooPublicationService::publish()` (dossiq `development`) builds the payload in `buildPayload()` (`title`, `summary`, `description`, `publicationDate`, `status`, `publicationKind`, `wooCategory`, `caseReference`, optional `period`) and `selectDisclosableDocuments()` drops every `niet_openbaar` document. A published Woo decision therefore carries no trace of the documents that were withheld, and no `wooBatch` exists for REQ-PDP-004 to read. The second half of 6.16 has no change in either app. This change is the OpenCatalogi half of it, and it names the dossiq call it needs.

## What changes

- A schema `withheldDocument` in OpenCatalogi's register holds, per withheld document of a publication: `publication` (uuid), `position` (the document's number in the decision's inventory, from 1), `grounds` (list of `{code, article, label}`), `source` and `recordedAt`. It has no `title`. It is readable and writable only by administrators and the system, like `wooAssessment`.
- `OCA\OpenCatalogi\Service\Woo\WithheldDocuments::record(string $publicationId, array $entries, string $source): array` replaces the withheld list of a publication. Each entry is `{position: int, grounds: list<string>}`; any other key, `title` included, is ignored and not stored. Each ground code is resolved through dossiq's `WooRefusalGrounds::byCode()`, and its code, article and label are stored as dossiq answered them at that moment. It answers `{recorded: int, refused: list<{position, code, reason}>}`.
- Where the publication's catalogue has `showWithheld` true (REQ-PDP-004), the public publication response (`publications#show`) and its federation twin (`federation#publication`) carry the stored entries in `withheld`, merged with the batch entries REQ-PDP-004 derives, ordered by position. A stored entry carries `position`, `grounds` as codes and `groundDetails: [{code, article, label}]`, and never a title. A batch entry keeps REQ-PDP-004's keys and gains `groundDetails` too.
- dossiq calls `record()` after it creates or updates the publication, with its `niet_openbaar` assessments. That call is `dossiq/woo-decision-records-what-was-withheld` (dossiq issue #3303); both sides name the entry keys `position` and `grounds` and nothing else.

## Fail closed

- `withheldDocument` has no public read rule. A withheld list reaches an anonymous reader only through the two OpenCatalogi endpoints, and only when the catalogue opted in. Without the opt-in the `withheld` key is absent, as REQ-PDP-004 already requires.
- `record()` stores nothing that could reveal content: no file, no file id, no hash, no document reference, no text and no title. Any key in an entry other than `position` and `grounds` is ignored and not stored. A title is left out on purpose: the title of a withheld document can itself be the withheld information, and dossiq's `wooDocumentAssessment` has no field that marks a title as public, so there is nothing a title could safely be released on.
- A ground code that dossiq's list does not hold refuses that entry. When dossiq is not installed, or `byCode()` throws `WooRefusalGroundsUnavailable`, `record()` refuses every entry and stores nothing. The vendored snapshot is not used on this path (D12 keeps it for redaction only).
- A `record()` call for a publication that does not exist refuses every entry.
- A stored entry keeps the label dossiq gave at recording time, so the public read never looks a ground up and never shows a label from a copy.

## Out of scope

- The portal block that renders the list (`portaliq/publication-error-reports-and-withheld-notices`).
- Partly withheld documents (`deels_openbaar`). They are published redacted, and their grounds per passage are the redaction's business (rows 4.x).
- dossiq's code. The dossiq lane writes the caller of `record()` and its own test of this contract.

## Dependencies

- `publication-detail-for-the-portal` (opencatalogi, wave 1): `showWithheld` on the catalogue and the `withheld` key of REQ-PDP-004.
- `woo-value-lists-on-the-concept-register` (opencatalogi, wave 2): `OCA\OpenCatalogi\Service\Woo\RefusalGrounds`, the one reader of dossiq's list, used here to resolve batch entries' `groundDetails`.
- `dossiq/woo-refusal-grounds-list` (dossiq, wave 1; dossiq PR #3285): `OCA\Dossiq\Woo\WooRefusalGrounds::byCode(string $code): ?array` with the keys `id, code, article, paragraph, letter, label, description, parent, status, legalSource`, throwing `WooRefusalGroundsUnavailable` (REQ-WRG-007). A retired ground stays readable through `byCode()`, so a decision citing it can still be recorded.
- dossiq absent: nothing calls `record()`, so nothing new is recorded. Entries already stored keep showing, because they carry their own labels.

## Wave

Wave 3, after the grounds reader of wave 2.

## Decisions

- D3: the refusal grounds are dossiq's. The label shown is the one dossiq's list holds, stored at recording time.
- D9: showing that a withheld record exists is a disclosure choice, opt-in per organisation and off by default. This change reuses REQ-PDP-004's `showWithheld` rather than adding a second switch.
- D12: Woo requests and decisions require dossiq. The grounds' read-only snapshot serves redaction only and is not used here.

## Rows

| row | text | rating today | what makes it yes |
|---|---|---|---|
| 6.16 | The portal shows that a withheld record exists, and why it is withheld | partial | supported here: REQ-WDW-003, scenario "A citizen sees which documents of a Woo decision were withheld and why"; portaliq closes the row |
