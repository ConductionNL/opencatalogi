# Tasks: published-service-and-case-type-catalogue

## 1. The public request catalogue

- [ ] 1.1 `serviceCatalogueEntry`: what it is, what it costs, how long it takes, who it is for, its group (REQ-PSC-101)
- [ ] 1.2 A form binding on the entry: case type, audience, form name (REQ-PSC-101)
- [ ] 1.3 The catalogue is public, grouped and searchable through the existing search surface (REQ-PSC-101)
- [ ] 1.4 Show an entry whose form is unavailable as unavailable, and list those entries for the administrator (D1, risks)

## 2. The case type as a published record

- [ ] 2.1 Publish a case type with a link to its form and to its API description (REQ-PSC-102)

## 3. Import from the national catalogue

- [ ] 3.1 Read the national zaaktypecatalogue as a harvest source through the gateway (REQ-PSC-103)
- [ ] 3.2 Import a definition, recording the source, the version and the moment (REQ-PSC-103)
- [ ] 3.3 Resynchronise: show the diff against the source before anything applies, flagging locally changed properties (REQ-PSC-103)
- [ ] 3.4 Show the sync date on every imported definition (REQ-PSC-103)

## 4. Knowledge articles

- [ ] 4.1 An article is a published record in a catalogue (REQ-PSC-104)
- [ ] 4.2 A reader's verdict counted and shown on the article (REQ-PSC-104)
- [ ] 4.3 Extract an answer into a draft article that links back to the case, leaving the case unchanged (REQ-PSC-105)

## 5. Quality

- [ ] 5.1 PHPUnit: the form binding resolves, the import diff, a locally changed property is flagged, an extraction leaves the case alone
- [ ] 5.2 Playwright `tests/e2e/published-service-and-case-type-catalogue.spec.ts`: browse the catalogue, open an entry, import a case type and resynchronise it
- [ ] 5.3 CORS headers on every new public endpoint, per the app's public endpoint rule
- [ ] 5.4 Reuse OpenRegister's slug and date utilities under ADR-011
- [ ] 5.5 Dutch and English strings; docs; `openspec validate published-service-and-case-type-catalogue --strict`
