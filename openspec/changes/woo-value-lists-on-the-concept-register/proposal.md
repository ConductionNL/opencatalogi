---
kind: code
depends_on: [dossiq/woo-refusal-grounds-list, openregister/property-code-list-from-concept-scheme, diwoo-metadata-on-the-publication]
---

# Proposal: woo-value-lists-on-the-concept-register

## Why

The national value lists a Woo publication cites (information categories, kinds of handling, organisations, document types, languages) change. Today OpenCatalogi carries its own copy in a JSON file, so a change waits for a release, and OpenRegister carries a second copy of some of the same lists. The refusal grounds exist in four versions across three apps. One copy of each list, kept current from its source, is what the rows ask.

Rows, from `opencatalogi/_round1/compare/M1-rows.md`, with our column from `baseline/openwoo.tsv`:

- **13.16** "A controlled list is refreshed from the national source without a release". Ours: partial, production. Evidence: "lib/Settings/tooi_waardelijsten.json and dcat_waardelijsten.json are bundled reference data refreshed by a release; openregister's SKOS importer can refresh a vocabulary from source, and opencatalogi's categories do not use it".
- **13.17** "The resource URI of every controlled-list entry is shown on one page, for an integrator to copy". Ours: partial, production. Evidence: "TooiVocabularyService::informatiecategorieList and soortHandelingList return code and URI ... no single page lists every controlled-list URI for an integrator".

Read on development at 35999c296. `TooiVocabularyService` reads `lib/Settings/tooi_waardelijsten.json`; `DcatVocabularyService` reads `dcat_waardelijsten.json`. OpenRegister ships a `vocabulary` register (SKOS-001), seeds the Woo TOOI lists (SKOS-003), imports a scheme idempotently keyed on URI with `VocabularyImportService::importJsonLd(array $jsonLd): array` (SKOS-002; a concept absent from a re-import is deprecated, never deleted), resolves concepts publicly (`vocabulary#resolveByUri`, `resolveByNotation`, `listConcepts`, SKOS-004), and is adding `x-openregister-concepts` on a property (`property-code-list-from-concept-scheme`, 5 of 9 tasks). `WooService::WEIGERINGSGRONDEN` is a 15-entry constant that validates `wooAssessment.weigeringsgronden`.

## What changes

- The TOOI and DiWoo lists OpenCatalogi uses (informatiecategorieën, soort handeling, organisaties, documentsoorten, talen) are read from OpenRegister's vocabulary register through one reader. `TooiVocabularyService` keeps its public methods and changes its source. `lib/Settings/tooi_waardelijsten.json` is removed, retiring WOO-TOOI-004's bundled copy.
- A daily job refreshes each scheme from its national source (the TOOI value-list downloads at standaarden.overheid.nl) through `VocabularyImportService::importJsonLd()`. A refresh failure keeps the last good scheme and raises the failure.
- Publication properties that take a list value declare `x-openregister-concepts` with their scheme, so OpenRegister validates them against the current list.
- One public page, `/apps/opencatalogi/value-lists` with its JSON twin `GET /api/value-lists`, lists every list entry OpenCatalogi uses: scheme, code, label, resource URI, status (active or deprecated) and the moment of the last refresh.
- Decision D3: the refusal grounds are dossiq's. `WooService::WEIGERINGSGRONDEN` is removed. `wooAssessment.weigeringsgronden` is validated against `OCA\Dossiq\Woo\WooRefusalGrounds::list()`. Stored codes are mapped to dossiq's codes, never guessed. Without dossiq, OpenCatalogi reads a vendored copy of dossiq's release snapshot for one use only: citing a ground per withheld passage when it redacts for publication. Grounds can then be picked, not edited, and the admin page says so.

## Fail closed

- A refresh that fails, or that would deprecate more than a third of a scheme at once, keeps the last good scheme and raises the failure for a person. A value list is never emptied by a broken download.
- When the vocabulary register cannot be read, a lookup throws and the caller fails as it does for an unknown value (omitted and reported in DiWoo, refused on save). It never falls back to a stale bundled copy.
- When dossiq is installed but `list()` throws `WooRefusalGroundsUnavailable`, a save that cites a ground is refused with the reason; the snapshot is not used then, because dossiq is the authority when present.
- A stored ground code without an unambiguous mapping is left as it is, flagged, and listed.

## Out of scope

- Maintaining the grounds (dossiq REQ-WRG-003).
- The DCAT lists in `dcat_waardelijsten.json` (data themes, HVD, licences). They are not TOOI lists and stay bundled until OpenRegister seeds them; the page of REQ-WVC-004 lists them too.
- Curating local additions to a list (`woo-value-list-curation`, wave 3).

## Dependencies

- `dossiq/woo-refusal-grounds-list` (dossiq, planned, wave 1; dossiq PR #3285): `WooRefusalGrounds::list(bool $includeRetired = false)` and `byCode(string $code)`, keys `id, code, article, paragraph, letter, label, description, parent, status, legalSource`, throwing `WooRefusalGroundsUnavailable` (REQ-WRG-007); the snapshot `lib/Settings/woo-refusal-grounds.snapshot.json` in the shape of `list()` with a version (REQ-WRG-008).
- `openregister/property-code-list-from-concept-scheme` (OpenRegister, open change outside this plan, 5 of 9 tasks): `x-openregister-concepts`.
- `diwoo-metadata-on-the-publication` (opencatalogi, wave 1): adds the documentsoorten and talen lists behind `TooiVocabularyService`, which move with the others here.

## Wave

Wave 2, after the dossiq grounds list and the DiWoo fields of wave 1.

## Decisions

- D3: "refusal grounds: one list in dossiq; TOOI lists: one copy in OpenRegister's concept register". Implemented as written.
- D12: Woo requests have no fallback without dossiq; the grounds keep a read-only fallback for redaction only. Implemented: the vendored snapshot is used only for citing a ground on a redacted passage, and only when dossiq is not installed.
- This change retires WOO-TOOI-004's bundled lists; the requirement is modified, quoted in full in the delta.

## Rows

| row | text | rating today | what makes it yes |
|---|---|---|---|
| 13.16 | A controlled list is refreshed from the national source without a release | partial | REQ-WVC-001 and REQ-WVC-002, scenario "A new TOOI entry is usable the next day without a release" |
| 13.17 | The resource URI of every controlled-list entry is shown on one page, for an integrator to copy | partial | REQ-WVC-004, scenario "An integrator copies a URI" |
