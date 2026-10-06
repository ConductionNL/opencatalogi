# Tasks: dcat-catalogue-completion

Read `openspec/woo-build-rules.md` first. For OpenRegister doubles copy `environmentAwareDouble()` from `tests/Unit/Service/SitemapServiceTest.php`. The value list and the DONL API are authorities: fetch them, do not reconstruct them from memory.

## 1. Licence list

- [ ] 1.1 Fetch the EU licences authority list and bundle the members data.overheid.nl accepts (cross-check with the DONL licence list) in `dcat_waardelijsten.json` with version and source URL (REQ-DCC-001). Verify: `tests/Unit/Service/DcatVocabularyServiceTest.php::testTheLicenceListCarriesItsSourceAndVersion`. If neither source can be fetched, stop and report.
- [ ] 1.2 Add `resolveLicence()` and `licenceList()` (REQ-DCC-001). Verify: `DcatVocabularyServiceTest::testALicenceResolvesFromIriCodeOrLabel` and `::testAnUnknownLicenceResolvesToNull`.
- [ ] 1.3 Bind or omit in `DcatMappingService` for catalogue, dataset and distribution, and report in the validator (REQ-DCC-001). Verify: `tests/Unit/Service/DcatMappingServiceTest.php::testALicenceCodeIsEmittedAsItsAuthorityIri` (fails today) and `::testAnUnresolvedLicenceIsOmittedAndReported`; a controller test through `dcat#validate`.
- [ ] 1.4 Replace the free-text licence fields with a picker, refuse an unlisted value on save (REQ-DCC-001). Verify: `tests/e2e/dcat-catalogue-completion.spec.ts` "an editor picks a licence", carrying `@e2e` REQ-DCC-001, and a pre-save test `tests/Unit/Listener/LicenceCheckTest.php::testAnUnlistedLicenceIsRefused` on the REAL event.

## 2. DONL registration

- [ ] 2.1 Read the data.overheid.nl API documentation and record in the PR body the call that counts datasets from one harvest source; use it, do not guess (REQ-DCC-002). Verify: the cited documentation URL in the PR body.
- [ ] 2.2 Add `DonlRegistrationService`, the fetch recorder in `DcatController::instance()` and `catalog()`, and `DonlHarvestCheck` registered in `appinfo/info.xml` (REQ-DCC-002). Verify: `tests/Unit/Service/DonlRegistrationServiceTest.php::testRegisteredWithoutEvidenceIsNotHarvested`, `::testAHarvesterFetchIsRecorded` (through the controller), `tests/Unit/BackgroundJob/DonlHarvestCheckTest.php::testAnUnreachableApiKeepsTheLastCountAndSaysSo` (fails today), `::testTheJobIsRegistered`.
- [ ] 2.3 Show the status block in the admin DCAT settings with the operator's requested and registered actions (REQ-DCC-002). Verify: `tests/e2e/dcat-catalogue-completion.spec.ts` "the admin sees whether data.overheid.nl harvests us", with the job's stored values seeded.

## 3. Linked data and SPARQL

- [ ] 3.1 Route the dataset and catalogue IRIs with content negotiation (REQ-DCC-003). Verify: `tests/Unit/Controller/DcatDereferenceTest.php::testADatasetIriAnswersTurtle` (fails today), `::testHtmlRedirectsToThePublicPage`, `::testADraftIriIs404`.
- [ ] 3.2 Add the SPARQL library (record its name, version and licence in the PR body; `composer audit` clean), the graph cache with invalidation on publication and catalogue events, and `GET`/`POST /api/sparql` with its CORS preflight (REQ-DCC-003). Verify: `tests/Unit/Controller/SparqlEndpointTest.php::testASelectQueryListsTheDatasetsOfATheme` (fails today), `::testAnUpdateIsRefused`, `::testServiceAndLoadAreRefused`, `::testTheRowCapIsSaid`; `tests/Unit/OpenApiParityTest.php` stays green with the route documented.
- [ ] 3.3 Live: run the scenario query against the dev instance and paste the answer in the PR body (REQ-DCC-003). Verify: the pasted answer.

## 4. Docs

- [ ] 4.1 Document the licence list, the DONL status and the SPARQL endpoint in `docs/`, and the strings in `l10n/` (en, nl). Verify: `npm run check:l10n` and a grep for U+2014 on the changed docs.

## 5. Verification

- [ ] 5.1 `TMPDIR` a sibling directory outside the clone. PHPUnit judged by the `Tests:` line or with `--no-coverage`.
- [ ] 5.2 `run-hydra-gates.sh --base origin/development`, counting the gates that ran; `composer audit` covers the new library.
- [ ] 5.3 Once before push: `COMPOSER_PROCESS_TIMEOUT=0 composer check:strict`, then `npm run lint`, `format`, `check:l10n`, `check:l10n-js`, `check:manifest`, `check:schema-l10n`. The coverage guard needs a test for every added statement.
- [ ] 5.4 One PR with `--base development`; merge development in, never rebase; no `Co-Authored-By` on any commit.

Done when merged on `development` with CI green. Rows 8.5, 8.6 and 8.8 become `production` only once a store release ships it.
