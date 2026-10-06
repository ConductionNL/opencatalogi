---
kind: code
depends_on: [woo-value-lists-on-the-concept-register, publications-reference-the-shared-organisation, openregister/local-changes-to-app-shipped-configuration]
---

# Proposal: woo-value-list-curation

## Summary

An organisation curates how it uses the national value lists (hide, order, explain in its own words, activate a subset of organisations) in an overlay that a refresh of the list never overwrites.

- Rows: 13.12, 13.13, 13.14, 13.15, 13.32.
- Wave: 3.
- Depends on: `opencatalogi/woo-value-lists-on-the-concept-register` (https://github.com/ConductionNL/opencatalogi/issues/1780); `opencatalogi/publications-reference-the-shared-organisation` (https://github.com/ConductionNL/opencatalogi/issues/1774); `openregister/local-changes-to-app-shipped-configuration` (open change outside this plan, 12 of 19 tasks; no `[OpenSpec]` issue found). `opencatalogi/publications-name-their-responsible-organisation` (https://github.com/ConductionNL/opencatalogi/issues/1773) uses the same activated set.
- Decision: D5, row 13.14 (normative fields refreshed, the organisation's own text kept); D5, row 13.32 (a hidden category serves an empty, valid sitemap index).
- Build rules: openspec/woo-build-rules.md

## Why

The national lists are the same for every organisation; how an organisation uses them is not. A water board never publishes some categories a municipality does, wants its most used categories first, wants to explain a category in its own words to a citizen, and publishes for a handful of organisations out of the thousands in the TOOI register. Today none of that can be set, and anything set by hand would be overwritten by the next refresh of the list.

Rows, from `opencatalogi/_round1/compare/M1-rows.md`, with our column from `baseline/openwoo.tsv`:

- **13.12** "An administrator reorders a controlled list, and the order drives the pick lists officers see". Ours: partial, production. Evidence: "opencatalogi #theme.sort orders themes; the 17 information categories are a PHP constant with a fixed order".
- **13.13** "An administrator activates only the organisations their installation publishes for, out of the national register". Ours: no. Evidence: "nothing activates a subset of organisations from a national register. TooiVocabularyService resolves one organisation at a time".
- **13.14** "A value-list entry is partly editable: the organisation's own text can change, the normative fields cannot". Ours: no. Evidence: "the 17 categories are a PHP constant in lib/Service/WooCategory.php; no part of a value list entry is editable".
- **13.15** "An administrator writes an explanation for a category, and the citizen sees it where they choose the filter". Ours: no. Evidence: "nothing stores an administrator's explanation of a category, and nothing shows one at the filter".
- **13.32** "An administrator hides the information categories that do not apply to the organisation's type". Ours: no. Evidence: "WooCategoryRegistry.php adds local categories as informationCategory objects, but every waardelijst member stays in the set and a sitemap is served for each".

Read on development at 35999c296. The evidence for 13.12 and 13.14 is partly out of date: the categories are data now (REQ-WIC-001, `WooCategoryRegistry`, the admin-only `informationCategory` schema with `code`, `title`, `titleEn`, `mapsTo`, `schemas`), and `woo-value-lists-on-the-concept-register` (wave 2) moves the TOOI lists onto OpenRegister's vocabulary register. But nothing orders, explains, hides or activates an entry, and the categories endpoint `GET /api/woo/categories` answers only to a signed-in user.

## What changes

- One overlay schema, `valueListChoice`, holds what the organisation owns about a list entry: the scheme, the entry's URI, `order`, `localLabel`, `explanation`, `hidden` and `active`. The normative fields (code, URI, the source's label) stay on the concept in OpenRegister and are refreshed from the source; the overlay is never touched by a refresh (decision D5 on SKOS-002).
- The administrator orders a list in the Woo settings, by keyboard operable move up and down. The order drives every officer pick list and the public category list.
- The administrator writes an explanation per category. It is served by a public `GET /api/woo/categories/public` for the portal to show at the filter.
- The administrator sets the organisation type. A bundled table proposes which categories do not apply to that type, each entry with its legal basis; the administrator confirms or overrides per category. A hidden category disappears from pick lists and the public filter, and its sitemap index stays served, valid and empty (decision D5 on REQ-WIC-001).
- The administrator activates, from the TOOI organisation register, the organisations this installation publishes for. Only activated organisations are offered as publisher and responsible organisation and resolved in DiWoo; activating one links or creates the matching OpenRegister organisation with its TOOI identifier.

## Fail closed

- A refresh never changes an overlay field, and an overlay never changes a normative field: a save of `valueListChoice` that sets a code, URI or source label is refused.
- A hidden category with publications already filed under it cannot be hidden until they are moved; the save names how many. Hiding never takes a published record out of the national index.
- A publication whose organisation is not activated keeps its record, but DiWoo omits the publisher and the validator reports it; nothing is published under an organisation the administrator did not activate.
- When the overlay cannot be read, pick lists fall back to the full list in source order, and the page says the local choices could not be loaded; no category is hidden by an error.

## Out of scope

- The lists themselves and their refresh (`woo-value-lists-on-the-concept-register`).
- The portal filter that shows the explanation (portaliq). This change serves it; the citizen half of 13.15 is the portal's.
- Editing a normative field. That is a change at the national source.

## Dependencies

- `woo-value-lists-on-the-concept-register` (opencatalogi, planned, wave 2): the lists in OpenRegister's vocabulary register.
- `publications-reference-the-shared-organisation` (opencatalogi, open, 9 of 10 tasks, amended in wave 2): publisher as `nc-organisation`.
- `openregister/local-changes-to-app-shipped-configuration` (OpenRegister, open change outside this plan, 12 of 19 tasks): keeps an administrator's change to app-shipped configuration across upgrades (REQ-LCA-001 to 003). The bundled applicability table is shipped configuration; an administrator's override of it must survive an upgrade.
- `publications-name-their-responsible-organisation` (wave 2): its responsible organisation picker uses the same activated set.

## Wave

Wave 3, on top of the lists as data from wave 2.

## Decisions

- D5, row 13.14: "Row wins: split normative fields (refreshed) from the organisation's own text (kept)." Implemented as the overlay schema.
- D5, row 13.32: "Row wins: a hidden category serves an empty, valid sitemap index rather than none, so the national harvester sees a stable set." Implemented as written; REQ-WIC-001 is modified accordingly in the delta.

## Rows

| row | text | rating today | what makes it yes |
|---|---|---|---|
| 13.12 | An administrator reorders a controlled list, and the order drives the pick lists officers see | partial | REQ-WVL-002, scenario "An administrator puts the most used category first" |
| 13.13 | An administrator activates only the organisations their installation publishes for | no | REQ-WVL-005, scenario "Only activated organisations are offered" |
| 13.14 | A value-list entry is partly editable: the organisation's own text can change, the normative fields cannot | no | REQ-WVL-001, scenarios "A refresh keeps the local label" and "A normative field cannot be edited" |
| 13.15 | An administrator writes an explanation for a category, and the citizen sees it where they choose the filter | no | REQ-WVL-003 serves it, scenario "The explanation is served publicly"; yes once the portal shows it |
| 13.32 | An administrator hides the information categories that do not apply to the organisation's type | no | REQ-WVL-004, scenarios "A water board hides the categories it never publishes" and "A hidden category's sitemap stays valid and empty" |
