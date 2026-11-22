# Tasks: council-documents-from-notubiz-and-ibabs

Read `lib/Settings/register.d/publiccode-github-harvest.json` and `lib/Service/PubliccodeHarvestService.php` first: this change copies their shape. Check on integriq `development` that `openconnector.fetch-file` (`lib/Flow/FetchFileNode.php`) and the sources `notubiz-ris-v1` and `ibabs-ris-v1` still exist; if one is gone, stop and name it in the PR body. Check the Notubiz documents endpoint against the source's API version (`query.version` in the source) before writing the synchronization; do not guess field names.

## 1. Flow, mapping and catalogue

- [x] 1.1 Add `lib/Settings/register.d/council-documents-harvest.json` with the flow of design D1 (`enabled: false`), the mapping `council-document-to-publication` of design D2, and the seeded catalogue `raadsinformatie` (REQ-CDH-001, REQ-CDH-002). Verify: `tests/Unit/Settings/CouncilHarvestFragmentTest.php::testTheFlowShipsDisabled`, `::testTheMappingSetsCategoryInfocat008`.
- [x] 1.2 Add `lib/Settings/council-documents-sources.json` with the Notubiz and iBabs synchronization and `fetch_file` rule templates that `setUp()` writes (REQ-CDH-001). Verify: a fixture test that every template names an existing integriq source slug.

## 2. Service and routes

- [x] 2.1 Add `CouncilHarvestService` with `status()`, `setUp(string $sourceSlug)`, `setEnabled(bool)`, `runNow()` and the refusals of design D5 risks (REQ-CDH-001). Verify: `tests/Unit/Service/CouncilHarvestServiceTest.php::testSetUpWritesTheSynchronizationAndRuleForNotubiz`, `::testSetUpRefusesAnIbabsSourceWithoutAKey`, `::testSetUpRefusesWithoutIntegriq`.
- [x] 2.2 Add `CouncilHarvestController` and the admin-only routes `/api/settings/council-harvest`, `/setup`, `/enable`, `/disable`, `/run` next to the publiccode harvest routes. Verify: a controller test per route and a route-table test.
- [x] 2.3 Upsert on the source identifier (REQ-CDH-003). Verify: `tests/Unit/Flow/CouncilHarvestFlowTest.php::testASecondRunUpdatesAndCreatesNothing`, `::testAPublicDocumentBecomesAConceptPublicationWithItsFile`, `::testADocumentNotMarkedPublicIsNotWritten`, run over a recorded Notubiz page fixture.
  - Built: `tests/Unit/Flow/CouncilHarvestFlowTest.php` replays the shipped Notubiz flow's tail over the recorded page `tests/fixtures/notubiz/documents-page.json`: items at the synchronization's `resultsPosition`, the flow's own JSON Logic filter, its own mapping rendered with Twig, and the upsert on the write node's own `match` (`caseReference`). A changed filter or match key fails all three tests.

## 3. Settings section

- [ ] 3.1 Add the "Council documents" section to the admin settings after "GitHub harvest" (board `OcInstellingen`), with the fields and actions of design D5 and the decidiq warning (REQ-CDH-001). Verify: `tests/e2e/council-documents-harvest.spec.ts` "an administrator sets up the council harvest", carrying `@e2e` REQ-CDH-001.
  - Round 2: `tests/e2e/council-documents-harvest.spec.ts` is written (admin sets up from `notubiz-ris-v1`, sees Run now and Switch on/off, switches it off again; a context with an empty storage state is refused on the status and set-up routes). Not run: needs a live instance with integriq and its Notubiz source.
- [x] 3.2 Add the strings to `l10n/` (en, nl) and document the harvest for administrators in `docs/`. Verify: `npm run check:l10n` and a grep for U+2014 on the changed docs.

## 4. Verification

- [ ] 4.1 `openspec validate council-documents-from-notubiz-and-ibabs --strict`, `composer check:strict`, `npm run lint`. The change's own files are clean on 17 Nov: phpcs, phpmd (CouncilHarvestService split into `CouncilSourceTemplates`, `RegisterObjectsBySlug` and `LatestFlowRun` to stay under the complexity and coupling limits, error-control operator removed) and phpstan, eslint on the settings section and its specs. Left unticked: `check:strict` and `npm run lint` still fail app-wide on findings outside this change (psalm cannot see OpenRegister main's value classes in a bare checkout, phpcs/phpmd in other files, eslint in `DashboardSideBar.vue`), and `openspec validate` was not run in the cloud session.
- [ ] 4.2 Live: point the Notubiz source at a municipality with a public feed, run the harvest once, and paste the run counts and one publication in the PR body. (live pass, decision 139)
- [x] 4.3 Set `int-council` in `openspec/parity/capabilities.json` to `built` with this change.
  - Done 22 Nov: `built`, owner `ConductionNL/opencatalogi`, `opencatalogi: yes`, evidence naming the fragment, the service, the admin routes and the settings section; `reachedOn` names the Council documents section.
