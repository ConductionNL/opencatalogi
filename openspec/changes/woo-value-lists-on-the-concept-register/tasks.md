# Tasks: woo-value-lists-on-the-concept-register

Read `openspec/woo-build-rules.md` first. Start once `dossiq/woo-refusal-grounds-list`, `openregister/property-code-list-from-concept-scheme` and `diwoo-metadata-on-the-publication` are merged. Read on their `development` before mocking: dossiq `WooRefusalGrounds::list()`, `byCode()`, `WooRefusalGroundsUnavailable` and the snapshot file; OpenRegister `VocabularyImportService::importJsonLd(array $jsonLd): array`, the concept repository behind the vocabulary routes (`Service/Vocabulary/ConceptRepository`), and `x-openregister-concepts`. For OpenRegister doubles copy `environmentAwareDouble()` from `tests/Unit/Service/SitemapServiceTest.php`. Use recorded TOOI JSON-LD responses in tests; fetch the real source URLs from the TOOI publication, do not reconstruct them.

## 1. Read from the register

- [ ] 1.1 Switch `TooiVocabularyService` to the vocabulary register, remove `tooi_waardelijsten.json`, and keep every existing caller's test green (REQ-WVC-001, WOO-TOOI-004). Verify: `tests/Unit/Service/TooiVocabularyServiceTest.php::testTheCategoryResolvesFromTheVocabularyRegister` (fails today), `::testAnUnreadableRegisterThrows`, `::testADeprecatedConceptResolvesButIsRefusedForANewValue`; `SitemapServiceTest` stays green.
      Not built yet, on purpose (17 Nov): OpenRegister seeds only the informatiecategorieën (17 concepts, without
      `c_816e508d` / infocat018 that version 4 of the list added) and the documenthandelingen; it seeds no documentsoorten
      and no talen scheme. Switching the reader before 2.1 can import those schemes would drop infocat018 and leave every
      document type and language unresolved on a live instance. 1.1 and 2.1 go in together.
- [ ] 1.2 Declare `x-openregister-concepts` on `wooCategory`, `soortHandeling`, `documentsoort` and `language`, bumping versions (REQ-WVC-001). Verify: `tests/Unit/Settings/ConceptPropertyDeclarationTest.php::testListPropertiesDeclareTheirScheme`, and an import test asserting the annotation survives (an unknown `x-openregister-*` key is dropped silently on older OpenRegister).

## 2. Refresh

- [x] 2.1 Add `TooiSchemeRefresh`, registered in `appinfo/info.xml`, with the source config and the mass-deprecation guard (REQ-WVC-002). Verify: `tests/Unit/BackgroundJob/TooiSchemeRefreshTest.php::testANewConceptFromTheSourceIsImported` (fails today), `::testAMassDeprecationIsRefused`, `::testAFailedDownloadKeepsTheScheme`, `::testTheJobIsRegistered`.
      Blocked (17 Nov): the TOOI and DiWoo source downloads (identifier.overheid.nl, standaarden.overheid.nl,
      repository.officiele-overheidspublicaties.nl) are refused by the build session's network, and the task asks for the
      real source URLs and recorded responses, not reconstructed ones. Needs a session that can reach those hosts.
      Built 25 Nov 2026 (b-opencatalogi); the source hosts answer from this session. `BackgroundJob/TooiSchemeRefresh`
      (daily, in `appinfo/info.xml`) runs `Service/Vocabulary/ValueListRefresh` over app config `tooi_scheme_sources`,
      seeded with the TOOI JSON downloads of the versions DiWoo 0.9.8 binds (informatiecategorieën 4, documentsoorten 7,
      documenthandelingen 2, talen 1), and stores `{lastRefreshAt, created, updated, deprecated, error}` per scheme in
      `tooi_scheme_refresh`. The TOOI repository serves expanded JSON-LD (a top-level node list, `@type` lists) that
      OpenRegister's `importJsonLd()` cannot read, so `TooiValueListGraph` rewrites it; a collection-based list (`ccw_`)
      has no ConceptScheme, so its list node becomes the scheme and grouping concepts are left out. The import runs in
      OpenRegister's `SystemOperationContext` (cron has no user; RBAC refused it as Anonymous). Recorded answers in
      `tests/fixtures/tooi/`. Tests: `TooiSchemeRefreshTest` (the four named, plus the one-third boundary and the seeded
      sources), `TooiValueListGraphTest` (OpenRegister's own `SkosNodeReader` on the rewrite), `OpenRegisterVocabularyTest`.
      Run through OpenRegister main's real importer on Nextcloud 34 + PostgreSQL 16 (sandbox): informatiecategorieën
      created 1 (infocat018) updated 17, documentsoorten created 91, documenthandelingen 3, talen 27; a second run
      unchanged. Not built here: 2.4's audit-trail refusal. 1.1 (switching the reader) can follow now that the schemes import.
- [x] 2.2 Live: run the job once on the dev instance and paste the per-scheme counts in the PR body (REQ-WVC-002). Verify: the pasted counts. (live pass, decision 139)
  - Done 26 Nov 2026 (live lane, sandbox: Nextcloud 34, MariaDB, php -S, OpenRegister main 01e805cd, opencatalogi development 38125c5, clean install). `TooiSchemeRefresh` ran on its own on the first cron tick against the live TOOI endpoints and stored, per scheme (created / updated / deprecated, no errors): informatiecategorieen 1 / 17 / 0, documentsoorten 91 / 0 / 0, soortHandeling 0 / 3 / 0, talen 27 / 0 / 0, organisaties-gemeenten 341 / 0 / 0, organisaties-provincies 12 / 0 / 0, organisaties-waterschappen 21 / 0 / 0, organisaties-ministeries 12 / 0 / 0, organisaties-zbo 173 / 0 / 0. A forced second run (`occ background-job:execute --force-execute`, 9 s) answered 0 / 0 / 0 for every scheme, so the refresh is idempotent.
- [ ] 2.3 Make `WooCategoryRegistry` read the categories from the vocabulary register instead of `WooCategory::ALL`, switching `SavedSearchService` and `BatchPublicationWriter` to `WooCategoryRegistry::find()`, taking the English label from the concept or `l10n/`, and keeping operator-added categories and every caller (the sitemap included) green (REQ-WVC-005, row 12.30). Verify: `tests/Unit/Service/Woo/WooCategoryRegistryTest.php::testTheCategoriesComeFromTheRegisterNotTheConstant` (fails today), and `git grep -n "const ALL" lib/Service/WooCategory.php` returns nothing.
- [ ] 2.4 Prove that every category change is audited: a refresh that renames or deprecates a category, an administrator edit, and an operator-added category edit each leave an audit trail row with actor, time, before and after; the job refuses the categories scheme when audit trails are off (REQ-WVC-005, row 12.30). Verify: `TooiSchemeRefreshTest::testACategoryChangeIsOnTheAuditTrailWithBeforeAndAfter` (fails today), `::testWithoutAuditTrailsTheCategoriesAreNotRefreshed`, and `WooCategoryRegistryTest::testAnOperatorCategoryEditIsAudited`, each reading the row back through OpenRegister's `AuditTrailMapper`, not a double of it.

## 3. Refusal grounds (D3, D12)

  - 25 Nov 2026 (b-opencatalogi, part): the refusal is built. `ValueListRefresh` refuses the informatiecategorieën scheme (`CATEGORIES_SCHEME`) while OpenRegister keeps no audit trails (`ValueListRefreshState::auditTrailsOn()` reads OpenRegister's `auditTrailsEnabled`; unreadable means OpenRegister's own default, on). The scheme stays as it is and the refusal is raised like any failed refresh; the other schemes refresh as usual. Tests: `TooiSchemeRefreshTest::testWithoutAuditTrailsTheCategoriesAreNotRefreshed`, `::testTheAuditTrailSwitchIsReadFromOpenRegister`.
    Live 25 Nov 2026 on Nextcloud 34.0.6 + PostgreSQL 16 with OpenRegister main, against the real TOOI downloads:
    - The job imported informatiecategorieën 1 created / 17 updated, documentsoorten 91, soortHandeling 3, talen 27 and the five organisation registers. OpenRegister wrote the rows itself, for example `update | System | 2026-11-25 22:31:01` on a category whose source label changed, with old "Wetten en algemeen verbindende voorschriften" and new "wet of algemeen verbindend voorschrift".
    - With OpenRegister's `retention.auditTrailsEnabled` false, the categories ended with "OpenRegister keeps no audit trails, ... the categories are not refreshed" and 0 created / 0 updated, while documentsoorten refreshed without error.
    Left: the rows for an administrator's edit and an operator-added category edit, and the named tests that read the row back through OpenRegister's `AuditTrailMapper` (they need a database, which the unit suite here has not).
- [x] 3.1 Remove `WooService::WEIGERINGSGRONDEN`; add `RefusalGrounds::list()` with the dossiq path and the vendored snapshot; validate in `updateAssessment()` and offer the list in the assessment form (REQ-WVC-003). Verify: `tests/Unit/Service/Woo/RefusalGroundsTest.php::testWithDossiqTheListIsDossiqs`, `::testAnUnreadableDossiqRefusesTheSaveAndDoesNotUseTheSnapshot`, `::testWithoutDossiqTheSnapshotIsUsedForRedactionOnly`, `::testTheSnapshotIsNeverOfferedToARequestPath`; `git grep -n WEIGERINGSGRONDEN lib` returns nothing.
      Built as `Service/Woo/RefusalGrounds` (`list()`, `citable()`, `forRequestPath()`, which refuses the snapshot).
      `getWeigeringsgronden()` and `updateAssessment()` read it, so the assessment form offers dossiq's list. The
      constant itself stays on `Contract\TransparencyWorkflowServiceInterface`, marked deprecated and read by nothing:
      it is a published contract, so `git grep` still finds that one line.
- [x] 3.2 Vendor dossiq's snapshot into `lib/Settings/woo-refusal-grounds.snapshot.json` with its version, and add a test that fails when its version is older than the one dossiq ships, when dossiq is checked out beside the clone (REQ-WVC-003). Verify: `RefusalGroundsTest::testTheVendoredSnapshotMatchesDossiqsShape`.
- [x] 3.3 Add the repair step mapping stored codes, registered post-migration (REQ-WVC-003). Verify: `tests/Unit/Repair/MapStoredRefusalGroundsTest.php::testAKnownCodeIsMapped`, `::testAnUnmappableCodeIsFlaggedAndListed`, `::testASecondRunChangesNothing`, `::testTheStepIsRegisteredPostMigration`.
      `Repair/MapStoredRefusalGrounds`. dossiq's REQ-WRG-006 table maps dossiq's own earlier codes and would
      mis-map OpenCatalogi's, so the step maps OpenCatalogi's earlier codes by meaning (five shifted codes; 5.2.e has
      no single meaning and is flagged). `wooAssessment` 0.4.0 carries `groundsListVersion` and `unmappedGrounds`;
      `updateAssessment()` sets the marker on every save so the step never shifts a settled code.
- [x] 3.4 Say in the Woo settings when the snapshot is in use (REQ-WVC-003). Verify: `tests/e2e/value-lists.spec.ts` "without dossiq, grounds can be picked for redaction and not edited", on a CI instance without dossiq, carrying `@e2e` REQ-WVC-003.
      `src/views/settings/RefusalGroundsSource.vue` in the Woo settings, with a jest spec; the e2e test is written, not
      run here (no live stack).

## 4. One page

- [ ] 4.1 Add `GET /api/value-lists` with CORS and the public page `/value-lists` (REQ-WVC-004). Verify: `tests/Unit/Controller/ValueListsControllerTest.php::testEveryUsedSchemeIsListedWithUris` (fails today) and `tests/e2e/value-lists.spec.ts` "an integrator copies a URI", carrying `@e2e` REQ-WVC-004; `OpenApiParityTest` stays green.
      The JSON route is built (`ValueListsController`, `ValueListsService`, documented in `openapi.json`) with its unit
      test and the e2e API tests. The public page is not: the app has no public page bundle yet, so the page test is a
      `test.fixme` until it ships. Left unticked for the page.

## 5. Docs

- [ ] 5.1 Document the lists, their sources and the refresh for administrators and integrators in `docs/`, and the strings in `l10n/` (en, nl). Verify: `npm run check:l10n` and a grep for U+2014 on the changed docs.

## 6. Verification

- [ ] 6.1 `TMPDIR` a sibling directory outside the clone. PHPUnit judged by the `Tests:` line or with `--no-coverage`. The vocabulary feeds the sitemap: run the full unit suite once.
- [ ] 6.2 `run-hydra-gates.sh --base origin/development`, counting the gates that ran.
- [ ] 6.3 Once before push: `COMPOSER_PROCESS_TIMEOUT=0 composer check:strict`, then `npm run lint`, `format`, `check:l10n`, `check:l10n-js`, `check:manifest`, `check:schema-l10n`. The coverage guard needs a test for every added statement.
- [ ] 6.4 One PR with `--base development`; merge development in, never rebase; no `Co-Authored-By` on any commit.

Done when merged on `development` with CI green. Rows 13.16 and 13.17 become `production` only once a store release ships it.
