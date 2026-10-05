---
kind: code
depends_on: []
---

# Proposal: woo-metadata-suggestions

## Why

A Woo publication needs an information category, a publisher and a handling type before the Woo-index accepts it. Records that arrive from a source system often lack one of them, and today an editor fills the gap by hand or the document is sent with the field left out.

opencatalogi matrix, row `woo-metadata-derive`, "Fill in missing Woo metadata automatically from other data in the source system, with suggestions a person confirms." Own rating partial.

- Demand: tender, TenderNed 407973 (https://www.tenderned.nl/aankondigingen/overzicht/407973). Origin note: "TenderNed 407973 wishes VPB-02-KW-03, VPB-04, VPB-04-KW-02, VPB-04-KW-03 (AI-supported completion)".
- Own evidence: "some Woo metadata is derived automatically without a person: publisher @resource from the organisation's TOOI identifier (lib/Service/SitemapService.php:531-538, resolveOrganisationTooiIdentifier :639) and soortHandeling defaulting to ontvangst (lib/Service/TooiVocabularyService.php:168-172) ... Missing half: no suggestions a person confirms; grep for suggest, classif, TaskProcessing and llm in lib and src finds only the MCP/CMS tool plumbing (lib/Tool/CMSTool.php:163), nothing that proposes metadata."

No competitor is rated yes. CKAN and DKAN are rated no ("no metadata suggestion or derivation for Woo fields"; "nothing derives Woo metadata or offers suggestions to confirm"), and xxllnc, iprox and Decos are unknown. The row is `build` under the rule "a tender demand row".

## What changes

- When a publication lacks a Woo field, OpenCatalogi proposes a value and says where it came from.
- A rules floor proposes values from data the instance already holds: the category mapped to the record's type, the organisation's TOOI identifier, the handling type implied by how the record arrived.
- When Hermiq is installed, an editor can ask it for more suggestions from the document text. Hermiq's suggestions only add to the rules floor.
- An editor accepts or rejects each suggestion on the publication page. Nothing is written to the publication until a person accepts.

## Rows this closes

| matrix | row id | row name | own rating | what is missing |
|---|---|---|---|---|
| opencatalogi | `woo-metadata-derive` | Fill in missing Woo metadata automatically from other data in the source system, with suggestions a person confirms. | partial | no suggestion a person confirms; derivation happens silently at sitemap time only |

## Existing work it builds on

- Main spec `woo-compliance`, WOO-TOOI-001 to WOO-TOOI-004: the value lists and the DiWoo validator that already names the unresolved axes per document.
- Open change `woo-category-mapping-intake` adds a type-level category mapping (`WooCategoryMapping`). The rules floor reads it when it exists; this change does not depend on it.
- Precedent: dossiq's `WOOAnonymisationAssistService` (rules floor, Hermiq adds, a person reviews, nothing auto-applied), read at dossiq development `96b8ef8`.

## Out of scope

- Any prompt, model choice or model call inside OpenCatalogi. The fleet rule is that AI lives in Hermiq.
- Changing the value of a field a person already set.
- Mapping rules in integriq (row `woo-metadata-map`, owned by integriq, change `mapping-woo-index-field-mapping`).

## Sibling halves

- ConductionNL/hermiq owes a structured metadata suggestion surface beside its existing `POST /api/assistant/detect-pii` (hermiq `appinfo/routes.php:556`): given document text and the allowed values of each field, return field, value and a short reason. Until it exists, the rules floor works alone and the Ask Hermiq action is hidden.

## Amendment 2026-10-05: Woo capability programme

Rows, from `opencatalogi/_round1/compare/M1-rows.md`, with our column from `baseline/openwoo.tsv`:

- **13.19** "A field with one lawful value for this officer fills itself". Ours: no. Evidence: "nothing prefills a field that has one lawful value for this officer. caseType.defaultAssignee style defaults exist elsewhere in the fleet and not on a publication".
- **14.1** "Metadata is suggested for a document someone just uploaded". Ours: no, roadmap. Flagged "deliberately not building"; awaits strike or keep under D10.
- **14.2** "A summary is written for the citizen, from the publication". Ours: no. Flagged "deliberately not building"; awaits strike or keep under D10.

Re-checked on development at 35999c296: this change is unbuilt (0 of 7 tasks) and unchanged. It still waits on `woo-category-mapping-intake` (open, outside this plan, 0 of 14) for the category rules.

Decision D5, row 13.19: "Row wins narrowly: when exactly one lawful value exists it fills itself, labelled as such. AI suggestions stay accept-only." This amends REQ-WMS-003, quoted in full under `## MODIFIED Requirements` in the delta: the one exception to "nothing is written until a person accepts" is a field with exactly one lawful value for this officer, filled at create time by rule and labelled so. Hermiq suggestions are never auto-filled.

What is added: REQ-WMS-004 (the single-lawful-value fill, built). REQ-WMS-005 (14.1, suggestions on upload) and REQ-WMS-006 (14.2, a B1 summary suggestion labelled as AI-made) are written and gated on D10: the builder skips them unless Ruben keeps the rows. Fail closed: a field is filled by rule only when the set of lawful values is computed and has exactly one member; an error or an empty set fills nothing. Hermiq absent: REQ-WMS-004 needs no Hermiq; the gated requirements are offered only with Hermiq, as REQ-WMS-002 already says. Wave 2.

| row | text | rating today | what makes it yes |
|---|---|---|---|
| 13.19 | A field with one lawful value for this officer fills itself | no | REQ-WMS-004, scenario "An officer of one organisation does not pick it" |
| 14.1 | Metadata is suggested for a document someone just uploaded | no | gated on D10: REQ-WMS-005 |
| 14.2 | A summary is written for the citizen, from the publication | no | gated on D10: REQ-WMS-006 |
