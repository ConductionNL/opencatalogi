---
kind: code
depends_on: []
---

# Proposal: diwoo-metadata-on-the-publication

## Summary

Every Woo publication stores the metadata the DiWoo standard and the national Woo-index require (title, document type, language, creation date, validity dates), a Woo publication missing a mandatory field is refused before it goes public, and the sitemap emits the stored values.

- Rows: 2.3 (statutory, Woo art. 3.3, with the DiWoo metadata standard as the shape the Woo-index accepts), 2.13, 2.23, 2.25.
- Wave: 1.
- Depends on: nothing to build. Followed by `opencatalogi/diwoo-metadata-on-the-publication-filinq-handoff` (split off 2026-10-06, the filinq contract, REQ-DWP-006). Paired with `filinq/diwoo-documentsoort-to-opencatalogi` (https://github.com/ConductionNL/filinq/issues/1344), which writes the new field.
- Decision: D8 (this change ships the repair step that moves documentsoort out of existing summaries); D3 respected (the new lists sit behind `TooiVocabularyService`).
- Build rules: openspec/woo-build-rules.md

## Why

The Woo obliges an organisation to make the information of the categories in Woo art. 3.3 public actively, and the national Woo-index (KOOP) only takes a document whose metadata conforms to the DiWoo metadata standard. OpenCatalogi declares DiWoo 0.9.8 (`StandardsVersionService::DIWOO_VERSION`, schema `StandardsVersionService::DIWOO_METADATA_XSD`). The main spec `woo-compliance` maps a publication to a `diwoo:Document` (WOO-006, WOO-010) and binds three axes to the TOOI and DiWoo value lists (WOO-TOOI-001 to WOO-TOOI-004). It cites no article. This change names the legal basis: Woo art. 3.3 for the duty, the DiWoo metadata standard for the shape the Woo-index accepts.

Today the record does not hold most of that metadata. `SitemapService::mapDiwooDocument()` assembles it at render time. It emits `loc`, `lastmod`, `diwoo:creatiedatum` (from `@self.created`, the moment the object was saved, not the moment the document was made), `diwoo:publisher`, `diwoo:format`, `diwoo:informatiecategorie` and `diwoo:documenthandeling`. It emits no `diwoo:officieleTitel`, no document type, no language and no validity dates. The publication schema in `lib/Settings/publication_register.json` (version 0.0.5) has none of those properties. `wooCategory` and `soortHandeling` exist, the latter through `lib/Settings/register.d/woo-information-categories-as-data.json`.

The rows this change closes, with our column today:

| row | text | rating today | evidence |
|---|---|---|---|
| 2.3 (statutory) | DiWoo metadata fields sit on every publication | partial, production | `lib/Service/SitemapService.php::mapDiwooDocument` emits DiWoo at render time; the publication schema stores 19 properties and none of them is a DiWoo field |
| 2.13 | The language of the record is recorded | no, roadmap | no language property on `#publication` or on any `register.d` fragment; `WooCategory.php` carries nl and en labels for the category, which is the vocabulary's language and not the record's |
| 2.23 | A publication carries the dates its content takes and loses legal effect, apart from its publication date | no | the publication schema carries `publicationDate` and `depublicationDate` only; `src/components/widgets/NationalAnnounceWidget.vue` takes an `effectiveDate` and passes it to `lib/Service/Publication/NationalIndexService.php::composeNotice`, but it is not stored and there is no end date |
| 2.25 | A document carries a document type from the national documentsoorten list | no | `lib/Service/TooiVocabularyService.php` resolves the informatiecategorieen and soortHandeling lists; no documentsoorten list is bundled (`lib/Settings/tooi_waardelijsten.json`) and no field carries a document type |

There is a second cost. filinq hands a ready Woo record to OpenCatalogi and, because the publication has no field for it, writes the DiWoo documentsoort into `summary` (filinq REQ-DDWPP-005, `openspec/specs/woo-publicatie-pipeline/spec.md`; code `lib/Service/Publication/OpenCatalogiPublicationMap.php::toPublication()`, `'summary' => (string) ($record['documentsoort'] ?? '')`). The type is lost to the sitemap, and the summary of every handed-off publication reads `besluit` or similar. `PlooiDeliveryService::document()` then sends that word to PLOOI as the description.

## What changes

- The publication schema gains the DiWoo fields the renderer now derives or lacks: `documentsoort` (with an optional per-file override `fileDocumentsoorten`), `language`, `creationDate`, `validFrom` and `validUntil`. `title` is the official title and is emitted as `diwoo:officieleTitel`. `verantwoordelijke` is emitted from the publishing organisation until `publications-name-their-responsible-organisation` adds its own field.
- OpenCatalogi bundles the DiWoo documentsoorten list and the language list behind `TooiVocabularyService`, the same seam as the other lists, and serves the documentsoorten at `GET /api/woo/documentsoorten`.
- A pre-save listener on OpenRegister's `ObjectCreatingEvent` and `ObjectUpdatingEvent` normalises a documentsoort given as code or label to its URI, and refuses a save that would make a Woo publication public, now or on a scheduled date, while a field the DiWoo XSD makes mandatory is missing or does not resolve.
- `SitemapService::mapDiwooDocument()` reads the stored fields. It falls back to today's derivation only for a record written before the migration. A document that still lacks a mandatory field after the fallback is left out of the sitemap page and reported, so the Woo-index never receives a document it would refuse.
- A Nextcloud repair step, `OCA\OpenCatalogi\Repair\MoveDocumentsoortOutOfSummary`, registered post-migration in `appinfo/info.xml`, moves a documentsoort value out of an existing `summary` into `documentsoort` and empties that summary. It is idempotent.
- The paired filinq change writes `documentsoort` instead of `summary` (decision D8). The filinq lane owns that PR. The contract it must meet, and the test on this side, are the follow-up change `diwoo-metadata-on-the-publication-filinq-handoff` (split off on 2026-10-06 to keep this change at 20 tasks).

## Fail closed

- A Woo publication that lacks a mandatory DiWoo field is not made public. The save is refused with the missing fields named. The safe state is unpublished, not published with the field left out.
- A legacy record that still lacks a mandatory field after the fallback is omitted from the sitemap page and listed by the DiWoo validator (WOO-TOOI-004). The rest of the page is served. An invalid `diwoo:Document` on a page can make the harvester refuse the page, which would take valid documents down with it.
- A documentsoort that is not a member of the bundled list is refused, never stored as free text and never emitted as a literal.
- The repair step moves a summary only when it equals a list member exactly. A summary that is anything else is left untouched.

## Out of scope

- The responsible organisation as a field of its own, and the RSIN. That is `publications-name-their-responsible-organisation`.
- Validating the rendered page against the XSD at generation time, the completeness reconciliation and failure notifications. That is `woo-national-output-assurance`.
- Moving the bundled value lists onto OpenRegister's concept register. That is `woo-value-lists-on-the-concept-register` (decision D3). This change adds the documentsoorten and language lists to the same bundled seam that change retires, so they move with the others.
- Multilingual content. The abandoned `register-i18n` change (no task started) specified language-tagged fields, negotiation and translation. This change supersedes it for one thing only: the language of the record, as one stored field. The rest of `register-i18n` stays where it is.
- filinq code. The filinq lane writes and merges its own PR.

## Dependencies

- None to build. This is wave 1.
- Paired, not a dependency: filinq change `diwoo-documentsoort-to-opencatalogi` (name the filinq lane may change; it is planned in this programme, written by the filinq lane, not started). It depends on this change: it writes the field this change adds.
- Consumed, already on development: OpenRegister `ObjectCreatingEvent` and `ObjectUpdatingEvent` (`setErrors()`, `stopPropagation()`, `setModifiedData()`; a stopped event is thrown by `MagicMapper` as `HookStoppedException`).

## Wave

Wave 1. It is statutory, it is the base the wave 2 changes build on (`woo-national-output-assurance`, `publications-name-their-responsible-organisation`), and it waits on nothing. The filinq PR lands in the same wave.

## Decisions

- D8: implemented as written. The filinq PR writes the new field; this change ships the migration (the repair step) that moves documentsoort out of existing summaries.
- D3: respected. The new lists live behind `TooiVocabularyService`, the one seam `woo-value-lists-on-the-concept-register` moves onto OpenRegister's concept register. No second consumer of a bundled copy is added.
- D5 (13.19, REQ-WMS-003): not touched. Nothing here fills a field on the officer's behalf except the documented defaults (`language` nl), which are schema defaults, not suggestions.

## Supersedes and amends

- Main spec `woo-compliance` WOO-006: the field list grows and the sources become stored fields. The full replacement is in this change's delta as MODIFIED.
- Main spec `woo-compliance` WOO-TOOI-004: unchanged in principle (the validator is advisory and the sitemap is still served), extended by REQ-DWP-004: a document whose mandatory field is missing is now omitted from the page and reported.
- Open change `register-i18n`: superseded for the record language only.

## Rows

| row | text | rating today | what makes it yes |
|---|---|---|---|
| 2.3 | DiWoo metadata fields sit on every publication | partial | the mandatory DiWoo fields are stored on the publication, a Woo publication missing one is refused before it goes public, and the sitemap emits the stored values (REQ-DWP-001, REQ-DWP-003, REQ-DWP-004) |
| 2.13 | The language of the record is recorded | no | `language` stored, defaulting to nl, emitted in DiWoo (REQ-DWP-001, REQ-DWP-004) |
| 2.23 | A publication carries the dates its content takes and loses legal effect, apart from its publication date | no | `validFrom` and `validUntil` stored apart from `publicationDate`, emitted as DiWoo geldigheid, and read by the national announcement as the effective date (REQ-DWP-001, REQ-DWP-004) |
| 2.25 | A document carries a document type from the national documentsoorten list | no | `documentsoort` and `fileDocumentsoorten` validated against the bundled list and emitted per document (REQ-DWP-002, REQ-DWP-004) |

## Release notes

- Existing publications whose summary holds only a document type (written by filinq) get that value moved to the new document type field, and their summary is emptied. Editors may want to write a real summary.
- A Woo publication can no longer be made public without the metadata the Woo-index requires. The save names what is missing.
