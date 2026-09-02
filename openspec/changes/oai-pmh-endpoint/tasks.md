# Tasks: oai-pmh-endpoint

## 1. Service

- [ ] 1.1 `OaiPmhService::identify(string $catalogSlug)` — repositoryName from
      the catalog, baseURL from `catalogEndpointUrl`-style routing,
      protocolVersion 2.0, earliestDatestamp from the oldest visible
      publication, deletedRecord `transient`, granularity
      `YYYY-MM-DDThh:mm:ssZ`
- [ ] 1.2 `OaiPmhService::listMetadataFormats()` — `oai_dc` + `dcat` with
      schema/namespace URLs; `idDoesNotExist` for an unknown identifier arg
- [ ] 1.3 `OaiPmhService::listSets()` — one set per DCAT-enabled catalog
- [ ] 1.4 Record query: visible publications for a catalog windowed by
      `from`/`until` on the modified timestamp, ordered stably, paged with
      the DCAT-008 page size; depublished-but-windowed rows become deleted
      headers
- [ ] 1.5 `listIdentifiers` / `listRecords` / `getRecord` over 1.4, with
      `oai_dc` mapping from the DCAT node and `dcat` embedding the JSON-LD
      dataset node
- [ ] 1.6 Stateless resumption token encode/decode (HMAC, expiry) +
      `badResumptionToken` on tamper/expiry
- [ ] 1.7 OAI-PMH XML envelope + error responses via XMLWriter

## 2. Controller + route

- [ ] 2.1 `OaiPmhController::handle(string $catalogSlug)` — `#[PublicPage]`
      `#[NoCSRFRequired]`, verb dispatch, `badVerb` fallback, correct
      Content-Type `text/xml; charset=UTF-8`
- [ ] 2.2 Route entry in `appinfo/routes.php` (`GET /catalog/{catalogSlug}/oai`)
- [ ] 2.3 Cache validators on Identify/List responses per DCAT-008 discipline
- [ ] 2.4 `@spec` tags on every public method pointing at this change's delta
      spec

## 3. Tests

- [ ] 3.1 Unit: verb/argument matrix incl. every error code (fixtures loaded
      via `file_get_contents` + `simplexml_load_string`, never
      `simplexml_load_file`)
- [ ] 3.2 Unit: oai_dc field-by-field mapping from a DCAT node
- [ ] 3.3 Unit: resumption-token round-trip, tamper, expiry
- [ ] 3.4 Unit: tombstone emission for a depublished windowed record
- [ ] 3.5 e2e (`tests/e2e/spec-coverage/oai-pmh-endpoint.spec.ts`): Identify,
      ListRecords with oai_dc, a resumption-token page walk and GetRecord
      against a seeded published fixture catalog; replace the delta spec's
      `@e2e exclude` markers with real references as these land

## 4. Docs + i18n

- [ ] 4.1 `docs/` page for the OAI-PMH endpoint (verbs, prefixes, identifier
      scheme) — via the writing skill
- [ ] 4.2 No UI strings expected (protocol surface); confirm and skip i18n if
      so

## 5. Quality

- [ ] 5.1 phpcs / phpmd (per subdirectory) / psalm / phpstan individually
      green on the touched files
- [ ] 5.2 Hydra gates `--scope-to-diff` green
