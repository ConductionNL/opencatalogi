# Tasks: dcat-catalogue-completion

Read `openspec/woo-build-rules.md` first. For OpenRegister doubles copy `environmentAwareDouble()` from `tests/Unit/Service/SitemapServiceTest.php`. The value list and the DONL API are authorities: fetch them, do not reconstruct them from memory.

## 1. Licence list

- [x] 1.1 Fetch the EU licences authority list and bundle the members data.overheid.nl accepts (cross-check with the DONL licence list) in `dcat_waardelijsten.json` with version and source URL (REQ-DCC-001). Verify: `tests/Unit/Service/DcatVocabularyServiceTest.php::testTheLicenceListCarriesItsSourceAndVersion`. If neither source can be fetched, stop and report.
  - Built 23 Nov: the authority list (`http://publications.europa.eu/resource/authority/licence`, version 20260617-0) and data.overheid.nl's CKAN `license_list` were both fetched. The five EU members data.overheid.nl accepts are bundled under `licences` with code, English and Dutch label, SPDX id, the DONL label and their exact-match IRIs. DONL's three OWMS licences (geslotenlicentie, geogedeeld, licentieonbekend) have no EU authority IRI and are left out.
- [x] 1.2 Add `resolveLicence()` and `licenceList()` (REQ-DCC-001). Verify: `DcatVocabularyServiceTest::testALicenceResolvesFromIriCodeOrLabel` and `::testAnUnknownLicenceResolvesToNull`.
- [x] 1.3 Bind or omit in `DcatMappingService` for catalogue, dataset and distribution, and report in the validator (REQ-DCC-001). Verify: `tests/Unit/Service/DcatMappingServiceTest.php::testALicenceCodeIsEmittedAsItsAuthorityIri` (fails today) and `::testAnUnresolvedLicenceIsOmittedAndReported`; a controller test through `dcat#validate`.
  - Built 23 Nov: dataset, distributions, the catalogue node and the instance document emit `dct:license` only as a resolved IRI; an unresolved value is reported once per dataset (`axis: dct:license`) and `validateCatalog()` lists it. Controller test: `tests/Unit/Controller/DcatLicenceValidateTest.php`. The golden recordings now carry the authority IRI where they carried the Creative Commons URI.
- [ ] 1.4 Replace the free-text licence fields with a picker, refuse an unlisted value on save (REQ-DCC-001). Verify: `tests/e2e/dcat-catalogue-completion.spec.ts` "an editor picks a licence", carrying `@e2e` REQ-DCC-001, and a pre-save test `tests/Unit/Listener/LicenceCheckTest.php::testAnUnlistedLicenceIsRefused` on the REAL event.
  - Server half built 23 Nov: `LicenceCheck` (registered on `ObjectCreatingEvent` and `ObjectUpdatingEvent`) refuses a new or changed catalogue `dcatLicense` or publication `license` that `resolveLicence()` does not resolve, naming the property; a stored legacy value left unchanged passes, so an unrelated edit is not blocked (the feed already omits and reports it). `SettingsService::updateSettings()` refuses a `dcat_default_license` outside the list before anything is written and stores a listed one as its authority IRI (`DcatLicenceSetting`). Tests: `LicenceCheckTest` (6, on the REAL events), `DcatLicenceSettingTest` (3), two `SettingsServiceTest` cases. Left unchecked: no form has a licence field yet (the catalogue modal, the publication form and the admin DCAT settings each need one, a picker over `licenceList()` served to the page), and the e2e spec has not run.

## 2. DONL registration

- [x] 2.1 Read the data.overheid.nl API documentation and record in the PR body the call that counts datasets from one harvest source; use it, do not guess (REQ-DCC-002). Verify: the cited documentation URL in the PR body.
- [x] 2.2 Add `DonlRegistrationService`, the fetch recorder in `DcatController::instance()` and `catalog()`, and `DonlHarvestCheck` registered in `appinfo/info.xml` (REQ-DCC-002). Verify: `tests/Unit/Service/DonlRegistrationServiceTest.php::testRegisteredWithoutEvidenceIsNotHarvested`, `::testAHarvesterFetchIsRecorded` (through the controller), `tests/Unit/BackgroundJob/DonlHarvestCheckTest.php::testAnUnreachableApiKeepsTheLastCountAndSaysSo` (fails today), `::testTheJobIsRegistered`.
- [ ] 2.3 Show the status block in the admin DCAT settings with the operator's requested and registered actions (REQ-DCC-002). Verify: `tests/e2e/dcat-catalogue-completion.spec.ts` "the admin sees whether data.overheid.nl harvests us", with the job's stored values seeded.

## 3. Linked data and SPARQL

- [x] 3.1 Route the dataset and catalogue IRIs with content negotiation (REQ-DCC-003). Verify: `tests/Unit/Controller/DcatDereferenceTest.php::testADatasetIriAnswersTurtle` (fails today), `::testHtmlRedirectsToThePublicPage`, `::testADraftIriIs404`.
- [ ] 3.2 Add the SPARQL library (record its name, version and licence in the PR body; `composer audit` clean), the graph cache with invalidation on publication and catalogue events, and `GET`/`POST /api/sparql` with its CORS preflight (REQ-DCC-003). Verify: `tests/Unit/Controller/SparqlEndpointTest.php::testASelectQueryListsTheDatasetsOfATheme` (fails today), `::testAnUpdateIsRefused`, `::testServiceAndLoadAreRefused`, `::testTheRowCapIsSaid`; `tests/Unit/OpenApiParityTest.php` stays green with the route documented.
- [ ] 3.3 Live: run the scenario query against the dev instance and paste the answer in the PR body (REQ-DCC-003). Verify: the pasted answer. (live pass, decision 139)

## 4. Docs

- [ ] 4.1 Document the licence list, the DONL status and the SPARQL endpoint in `docs/`, and the strings in `l10n/` (en, nl). Verify: `npm run check:l10n` and a grep for U+2014 on the changed docs.

## 5. Verification

- [ ] 5.1 `TMPDIR` a sibling directory outside the clone. PHPUnit judged by the `Tests:` line or with `--no-coverage`.
- [ ] 5.2 `run-hydra-gates.sh --base origin/development`, counting the gates that ran; `composer audit` covers the new library.
- [ ] 5.3 Once before push: `COMPOSER_PROCESS_TIMEOUT=0 composer check:strict`, then `npm run lint`, `format`, `check:l10n`, `check:l10n-js`, `check:manifest`, `check:schema-l10n`. The coverage guard needs a test for every added statement.
- [ ] 5.4 One PR with `--base development`; merge development in, never rebase; no `Co-Authored-By` on any commit.

Done when merged on `development` with CI green. Rows 8.5, 8.6 and 8.8 become `production` only once a store release ships it.

## 6. The feeds switch on per catalogue

- [x] 6.1 Declare `hasDcat` (boolean, default false) on the `catalog` schema in `lib/Settings/publication_register.json` and bump its version, so a stored value survives the hard validation (DCAT-001, DCAT-002; rows `od-dcat`, `od-dcat-catalogue`, `od-dcat-formats`).
- [ ] 6.2 Add the DCAT switch to `src/modals/catalog/CatalogModal.vue` next to `hasOoapi`, so an administrator enables the per-catalogue feed and the instance document lists it (DCAT-010). Verify: an e2e test that enables it and reads `GET /api/catalogs/{slug}/dcat` and `GET /api/dcat`.
- [x] 6.3 Show the result of Validate DCAT feed in the admin DCAT settings, per catalogue, with every DCAT-AP-NL mandatory property it checks and what data.overheid.nl would reject (DCAT-010, DCAT-NPF-001; rows `od-dcat-validate`, `od-donl`).
  - Built: `src/views/settings/DcatFeedValidation.vue`, under the data.overheid.nl block in the admin settings, lists the catalogues with `hasDcat` and runs `dcat#validate` and `dcat#donlReport` per catalogue: the DCAT-AP-NL profile, the mandatory properties checked (`checked`, now returned by `dcat#validate` from `DcatService::MANDATORY_DATASET_PROPERTIES`), each dataset that misses one, and what data.overheid.nl would reject, with the harvest source address. Tests: `src/views/settings/DcatFeedValidation.spec.js` (6), `DcatControllerTest::testValidateReportsViolations`. Strings in en and nl.

