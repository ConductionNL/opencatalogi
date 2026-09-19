# Tasks: published-service-and-case-type-catalogue

## 1. The public request catalogue

- [x] 1.1 `serviceCatalogueEntry`: what it is, what it costs, how long it takes, who it is for, its group (REQ-PSC-101)
- [x] 1.2 A form binding on the entry: case type, audience, form name (REQ-PSC-101)
- [x] 1.3 The catalogue is public, grouped and searchable through the existing search surface (REQ-PSC-101)
- [x] 1.4 Show an entry whose form is unavailable as unavailable, and list those entries for the administrator (D1, risks)

## 2. The case type as a published record

- [x] 2.1 Publish a case type with a link to its form and to its API description (REQ-PSC-102)

## 3. Import from the national catalogue

- [x] 3.1 Read the national zaaktypecatalogue as a harvest source through the gateway (REQ-PSC-103)
- [x] 3.2 Import a definition, recording the source, the version and the moment (REQ-PSC-103)
- [x] 3.3 Resynchronise: show the diff against the source before anything applies, flagging locally changed properties (REQ-PSC-103)
- [x] 3.4 Show the sync date on every imported definition (REQ-PSC-103)

## 4. Knowledge articles

- [x] 4.1 An article is a published record in a catalogue (REQ-PSC-104)
- [x] 4.2 A reader's verdict counted and shown on the article (REQ-PSC-104)
- [x] 4.3 Extract an answer into a draft article that links back to the case, leaving the case unchanged (REQ-PSC-105)

## 5. Quality

- [x] 5.1 PHPUnit: the form binding resolves, the import diff, a locally changed property is flagged, an extraction leaves the case alone
- [x] 5.2 Playwright `tests/e2e/published-service-and-case-type-catalogue.spec.ts`: browse the catalogue, open an entry, import a case type and resynchronise it
- [x] 5.3 CORS headers on every new public endpoint, per the app's public endpoint rule
- [x] 5.4 Reuse OpenRegister's slug and date utilities under ADR-011
- [x] 5.5 Dutch and English strings; docs; `openspec validate published-service-and-case-type-catalogue --strict`

## What this change does not do

- It does not render the catalogue for the citizen. That is portaliq's
  `portal-intake-form-as-an-object`, and the form binding is what the two
  halves meet on.
- It does not implement the transport to the national zaaktypecatalogus.
  `GatewayCaseTypeSourceReader` hands the ask to integriq and reads the
  answer; with no gateway installed it reports the source as unreachable and
  never as empty.
- Of the Woo obligations around a published catalogue it implements the
  catalogue itself. Registration with the national Woo index belongs to
  `publication-inspection-and-the-national-indexes`.
