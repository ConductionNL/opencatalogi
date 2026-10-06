# Tasks: oai-pmh-endpoint

Groups 1 to 5 specified the OAI-PMH protocol. Decision D10 struck it (row 9.10), so they are kept below as a record without checkboxes and are not to be built. Build groups 6 and 7.

## 1. Service

- struck by D10, not a task: 1.1 `OaiPmhService::identify(string $catalogSlug)` — repositoryName from
      the catalog, baseURL from `catalogEndpointUrl`-style routing,
      protocolVersion 2.0, earliestDatestamp from the oldest visible
      publication, deletedRecord `transient`, granularity
      `YYYY-MM-DDThh:mm:ssZ`
- struck by D10, not a task: 1.2 `OaiPmhService::listMetadataFormats()` — `oai_dc` + `dcat` with
      schema/namespace URLs; `idDoesNotExist` for an unknown identifier arg
- struck by D10, not a task: 1.3 `OaiPmhService::listSets()` — one set per DCAT-enabled catalog
- struck by D10, not a task: 1.4 Record query: visible publications for a catalog windowed by
      `from`/`until` on the modified timestamp, ordered stably, paged with
      the DCAT-008 page size; depublished-but-windowed rows become deleted
      headers
- struck by D10, not a task: 1.5 `listIdentifiers` / `listRecords` / `getRecord` over 1.4, with
      `oai_dc` mapping from the DCAT node and `dcat` embedding the JSON-LD
      dataset node
- struck by D10, not a task: 1.6 Stateless resumption token encode/decode (HMAC, expiry) +
      `badResumptionToken` on tamper/expiry
- struck by D10, not a task: 1.7 OAI-PMH XML envelope + error responses via XMLWriter

## 2. Controller + route

- struck by D10, not a task: 2.1 `OaiPmhController::handle(string $catalogSlug)` — `#[PublicPage]`
      `#[NoCSRFRequired]`, verb dispatch, `badVerb` fallback, correct
      Content-Type `text/xml; charset=UTF-8`
- struck by D10, not a task: 2.2 Route entry in `appinfo/routes.php` (`GET /catalog/{catalogSlug}/oai`)
- struck by D10, not a task: 2.3 Cache validators on Identify/List responses per DCAT-008 discipline
- struck by D10, not a task: 2.4 `@spec` tags on every public method pointing at this change's delta
      spec

## 3. Tests

- struck by D10, not a task: 3.1 Unit: verb/argument matrix incl. every error code (fixtures loaded
      via `file_get_contents` + `simplexml_load_string`, never
      `simplexml_load_file`)
- struck by D10, not a task: 3.2 Unit: oai_dc field-by-field mapping from a DCAT node
- struck by D10, not a task: 3.3 Unit: resumption-token round-trip, tamper, expiry
- struck by D10, not a task: 3.4 Unit: tombstone emission for a depublished windowed record
- struck by D10, not a task: 3.5 e2e (`tests/e2e/spec-coverage/oai-pmh-endpoint.spec.ts`): Identify,
      ListRecords with oai_dc, a resumption-token page walk and GetRecord
      against a seeded published fixture catalog; replace the delta spec's
      `@e2e exclude` markers with real references as these land

## 4. Docs + i18n

- struck by D10, not a task: 4.1 `docs/` page for the OAI-PMH endpoint (verbs, prefixes, identifier
      scheme) — via the writing skill
- struck by D10, not a task: 4.2 No UI strings expected (protocol surface); confirm and skip i18n if
      so

## 5. Quality

- struck by D10, not a task: 5.1 phpcs / phpmd (per subdirectory) / psalm / phpstan individually
      green on the touched files
- struck by D10, not a task: 5.2 Hydra gates `--scope-to-diff` green

## 6. Amendment 2026-10-05: the changed-since read (row 8.12)

Decision D10 struck OAI-PMH (row 9.10). Groups 1 to 5 above specified the OAI-PMH protocol and are not to be built; they carry no checkboxes. Say so in the PR body. Build only this group. Read `openspec/woo-build-rules.md` first; for OpenRegister doubles copy `environmentAwareDouble()` from `tests/Unit/Service/SitemapServiceTest.php`.

- [ ] 6.1 Add `ChangesController::changes(string $catalogSlug)` (`#[PublicPage]`, `#[NoCSRFRequired]`, rate limited like `SearchController::index()`), its route and CORS preflight, reading through `PublicationQueryService` inside the anonymous scope and rendering nodes with `DcatMappingService` (OAI-006). Verify: `tests/Unit/Controller/ChangesEndpointTest.php::testOnlyPublicRecordsChangedSinceAreListed` (fails today: no route), `::testAMalformedSinceIs400`, `::testPagesFollowTheCursor`; a route-table test.
- [ ] 6.2 Add the withdrawn list from `depublication` records and archived publications that were once public (OAI-007). Verify: `ChangesEndpointTest::testAWithdrawnRecordIsListedWithIdAndDateOnly` and `::testANeverPublicDraftIsNotListed`.
- [ ] 6.3 Document the route in `openapi.json` (keeping `tests/Unit/OpenApiParityTest.php` green) and in `docs/` for re-users. Verify: `OpenApiParityTest` and a grep for U+2014 on the doc.
- [ ] 6.4 Live: ask the dev instance for changes since yesterday after editing and withdrawing one publication each, and paste the answer in the PR body (OAI-006, OAI-007). Verify: the pasted answer.

## 7. Verification (amendment)

- [ ] 7.1 `TMPDIR` a sibling directory outside the clone. PHPUnit judged by the `Tests:` line or with `--no-coverage`.
- [ ] 7.2 `run-hydra-gates.sh --base origin/development`, counting the gates that ran.
- [ ] 7.3 Once before push: `COMPOSER_PROCESS_TIMEOUT=0 composer check:strict`, then `npm run lint`, `format`, `check:l10n`, `check:l10n-js`, `check:manifest`, `check:schema-l10n`. The coverage guard needs a test for every added statement.
- [ ] 7.4 One PR with `--base development`; merge development in, never rebase; no `Co-Authored-By` on any commit.

Done when merged on `development` with CI green. Row 8.12 becomes `production` only once a store release ships it.
