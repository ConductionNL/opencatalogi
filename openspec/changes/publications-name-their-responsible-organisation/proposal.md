---
kind: code
depends_on: [diwoo-metadata-on-the-publication, openregister/consolidate-organisation-on-or]
---

# Proposal: publications-name-their-responsible-organisation

## Why

The organisation that puts a document online is not always the one answerable for it. A shared service centre or a regional body publishes for a municipality; the Woo-index then needs to know who is responsible (`diwoo:verantwoordelijke`) apart from who published (`diwoo:publisher`). And a document that leaves its publication, downloaded or passed on, should still say whose it is, by the organisation's legal number (RSIN).

Rows, from `opencatalogi/_round1/compare/M1-rows.md`, with our column from `baseline/openwoo.tsv`:

- **2.21** "The publishing organisation's own legal number is held and travels with every stored document". Ours: partial, production. Evidence: "opencatalogi resolves the organisation's TOOI identifier via TooiVocabularyService; no RSIN is held, and nothing travels with a stored document".
- **2.22** "A publication names the organisation responsible for it separately from the organisation that publishes it". Ours: no. Evidence: "opencatalogi lib/Settings/publication_register.json gives a publication one organization field, which lib/Service/SitemapService.php::mapDiwooDocument emits as diwoo:publisher; there is no separate responsible-organisation (verantwoordelijke) field and grep for verantwoordelijke/opsteller finds nothing".

Read on development at 35999c296. `publication.organization` refers to OpenRegister's shared organisation (`$ref: nc-organisation`, `publications-reference-the-shared-organisation`, 9 of 10 tasks). That projection (`OrganisationObjectSourceProvider`) already carries `tooi`, `rsin` and `kvk`. `SitemapService::resolveOrganisationTooiIdentifier()` resolves the TOOI identifier of the publisher. `diwoo-metadata-on-the-publication` (wave 1) emits `diwoo:verantwoordelijke` from the publisher until this change adds its own field.

## What changes

- The publication gains `responsibleOrganization` (`$ref: nc-organisation`). Empty means the publisher is also responsible. `SitemapService::mapDiwooDocument()` emits `diwoo:verantwoordelijke` from it, with its TOOI URI, and `diwoo:publisher` from `organization` as today.
- The RSIN travels: when a document is attached to a public publication, the publishing organisation's RSIN (checked with the 11-proef) is written to the file's metadata in OpenRegister (`rsin`, `publisherName`, `publisherTooi`) and, when `published-file-carries-its-facts` is merged, into the file's own embedded metadata beside the title. The publication stores `publisherRsin` as it was at publication.
- The editor form offers the responsible organisation through the same picker as the publisher.

## Fail closed

- A responsible organisation without a TOOI identifier is not emitted as a literal. The element is omitted and the DiWoo validator reports it. When the DiWoo XSD makes it mandatory, `DiwooCompletenessListener` (from `diwoo-metadata-on-the-publication`) refuses to make the publication public, naming `responsibleOrganization`.
- An RSIN that fails the 11-proef, or is empty, is not stamped. The attachment records `rsin: missing` with the reason and the validator lists it. A wrong legal number is worse than none.

## Out of scope

- Editing organisations, their RSIN or TOOI identifier. That is OpenRegister's organisation management.
- A DiWoo element for the RSIN: the builder checks the XSD fixture of `diwoo-metadata-on-the-publication`; if DiWoo has none, the RSIN travels in file metadata only.

## Dependencies

- `diwoo-metadata-on-the-publication` (opencatalogi, planned, wave 1): the DiWoo record, the XSD fixture and the completeness listener.
- `openregister/consolidate-organisation-on-or` (OpenRegister, open change outside this plan, 14 of 15 tasks): the shared organisation with `rsin` and `tooi` on the `nc-organisation` projection, which is on development at 1dc6a46.
- `publications-reference-the-shared-organisation` (opencatalogi, open, 9 of 10 tasks; amended in this programme for row 12.34): `organization` already points at `nc-organisation`.
- `published-file-carries-its-facts` (opencatalogi, planned, wave 1): optional; when merged, its `EmbeddedTitleWriter` also writes the RSIN.

## Wave

Wave 2. It needs the DiWoo fields of wave 1.

## Decisions

None of D1 to D13 is implemented here.

## Rows

| row | text | rating today | what makes it yes |
|---|---|---|---|
| 2.21 | The publishing organisation's own legal number is held and travels with every stored document | partial | REQ-PRO-002, scenario "The RSIN travels with the file" |
| 2.22 | A publication names the organisation responsible for it separately from the organisation that publishes it | no | REQ-PRO-001, scenario "A shared service centre publishes for a municipality" |
