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
