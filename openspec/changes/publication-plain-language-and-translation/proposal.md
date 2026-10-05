---
kind: code
depends_on: []
---

# Proposal: publication-plain-language-and-translation

## Why

A Woo decision is written for lawyers. The government's own standard for citizen text is taalniveau B1, and a reader who cannot follow the decision cannot use the right the Woo gives them. A publication has no place to say in plain words what it is about.

Rows, from `opencatalogi/_round1/compare/M1-rows.md`, with our column from `baseline/openwoo.tsv`:

- **15.4** "A publication carries a plain language summary". Ours: no. Evidence: "no plain language summary field. #publication.summary is free prose with no register or reading level attached".
- **14.7** "A publication is translated into another language". Ours: no, roadmap. Evidence: "zero translation of a publication. l10n/ translates the interface, not the record". This row is flagged "deliberately not building" in the plan (reason: "area 14: the AI app is deliberately outside this bundle"), and decision D10 leaves it awaiting Ruben's strike or keep. Its requirement is written here and gated.

Read on development at 35999c296. The publication has `summary` and `description`, both free prose. OpenCatalogi has no AI client today (`git grep -i hermiq lib` finds nothing). Nextcloud's TaskProcessing API offers the task types `core:text2text:simplification` and `core:text2text:translate`, which hermiq (or any other provider) can serve. `diwoo-metadata-on-the-publication` (wave 1) adds `language` to the publication.

## What changes

- The publication gains `plainSummary` and `plainSummaryLevel` (CEFR `A2`, `B1` or `B2`; default `B1`). A plain summary without a level is refused. The public response carries both, the portal can show them, and the DCAT dataset emits the summary as `dct:abstract` with its language.
- An officer can ask for a draft. When a TaskProcessing provider for `core:text2text:simplification` is installed, Draft plain summary sends the title, summary and description and shows the result as a suggestion. The officer edits and accepts it; only then is it stored, with its provenance (`generatedBy`, `provider`, `at`, `acceptedBy`). Without a provider the button is absent and a short line says why; writing by hand always works.
- Gated on D10 (row 14.7): a translation of title, summary and plain summary per language, drafted through `core:text2text:translate`, accepted by an officer, stored in `translations` keyed by language with the same provenance, and served on the public API by `Accept-Language` or `?lang=`.

## Fail closed

- A draft is never stored as the plain summary or a translation without an officer's accept. The stored provenance names the person who accepted it.
- A draft is sent to the provider only when the publication is a draft or the officer may already read it; nothing that is not the officer's to read leaves the instance. When the provider fails or times out, nothing is stored and the officer sees the error.
- In staging (`instance-staging-mode`), the draft call is an outbound call like any other: when the TaskProcessing provider is remote, it goes through the outbound gate.

## Out of scope

- Measuring the reading level of a text automatically. The level is what the officer declares.
- Translating documents (files).

## Dependencies

- None to build. Uses Nextcloud's `OCP\TaskProcessing\IManager` (`getAvailableTaskTypes()`, `scheduleTask()`, `getTask()`), so it works with hermiq or any provider and offers manual entry without one.
- Reads `language` from `diwoo-metadata-on-the-publication` when present for `dct:abstract`'s language tag; defaults to `nl` otherwise.

## Wave

Wave 1. It needs nothing new.

## Decisions

- D10: 14.7 is flagged and awaits strike or keep. Its requirement (REQ-PPL-003) and tasks (group 4) are gated: the builder skips them unless Ruben has kept 14.7.
- D5 (row 13.19, REQ-WMS-003, a person decides on a suggestion) is followed: every draft is a suggestion an officer accepts.

## Rows

| row | text | rating today | what makes it yes |
|---|---|---|---|
| 15.4 | A publication carries a plain language summary | no | REQ-PPL-001 and REQ-PPL-002, scenarios "An officer writes a B1 summary and the reader sees it" and "A draft is a suggestion until accepted" |
| 14.7 | A publication is translated into another language | no | gated on D10: REQ-PPL-003, scenario "A reader asks for English" |
