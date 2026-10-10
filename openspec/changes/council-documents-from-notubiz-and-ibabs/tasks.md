# Tasks: council-documents-from-notubiz-and-ibabs

Read `lib/Settings/register.d/publiccode-github-harvest.json` and `lib/Service/PubliccodeHarvestService.php` first: this change copies their shape. Check on integriq `development` that `openconnector.fetch-file` (`lib/Flow/FetchFileNode.php`) and the sources `notubiz-ris-v1` and `ibabs-ris-v1` still exist; if one is gone, stop and name it in the PR body. Check the Notubiz documents endpoint against the source's API version (`query.version` in the source) before writing the synchronization; do not guess field names.

## 1. Flow, mapping and catalogue

- [ ] 1.1 Add `lib/Settings/register.d/council-documents-harvest.json` with the flow of design D1 (`enabled: false`), the mapping `council-document-to-publication` of design D2, and the seeded catalogue `raadsinformatie` (REQ-CDH-001, REQ-CDH-002). Verify: `tests/Unit/Settings/CouncilHarvestFragmentTest.php::testTheFlowShipsDisabled`, `::testTheMappingSetsCategoryInfocat008`.
- [ ] 1.2 Add `lib/Settings/council-documents-sources.json` with the Notubiz and iBabs synchronization and `fetch_file` rule templates that `setUp()` writes (REQ-CDH-001). Verify: a fixture test that every template names an existing integriq source slug.

## 2. Service and routes

- [ ] 2.1 Add `CouncilHarvestService` with `status()`, `setUp(string $sourceSlug)`, `setEnabled(bool)`, `runNow()` and the refusals of design D5 risks (REQ-CDH-001). Verify: `tests/Unit/Service/CouncilHarvestServiceTest.php::testSetUpWritesTheSynchronizationAndRuleForNotubiz`, `::testSetUpRefusesAnIbabsSourceWithoutAKey`, `::testSetUpRefusesWithoutIntegriq`.
- [ ] 2.2 Add `CouncilHarvestController` and the admin-only routes `/api/settings/council-harvest`, `/setup`, `/enable`, `/disable`, `/run` next to the publiccode harvest routes. Verify: a controller test per route and a route-table test.
- [ ] 2.3 Upsert on the source identifier (REQ-CDH-003). Verify: `tests/Unit/Flow/CouncilHarvestFlowTest.php::testASecondRunUpdatesAndCreatesNothing`, `::testAPublicDocumentBecomesAConceptPublicationWithItsFile`, `::testADocumentNotMarkedPublicIsNotWritten`, run over a recorded Notubiz page fixture.

## 3. Settings section

- [ ] 3.1 Add the "Council documents" section to the admin settings after "GitHub harvest" (board `OcInstellingen`), with the fields and actions of design D5 and the decidiq warning (REQ-CDH-001). Verify: `tests/e2e/council-documents-harvest.spec.ts` "an administrator sets up the council harvest", carrying `@e2e` REQ-CDH-001.
- [ ] 3.2 Add the strings to `l10n/` (en, nl) and document the harvest for administrators in `docs/`. Verify: `npm run check:l10n` and a grep for U+2014 on the changed docs.

## 4. Verification

- [ ] 4.1 `openspec validate council-documents-from-notubiz-and-ibabs --strict`, `composer check:strict`, `npm run lint`.
- [ ] 4.2 Live: point the Notubiz source at a municipality with a public feed, run the harvest once, and paste the run counts and one publication in the PR body. (live pass, decision 139)
- [ ] 4.3 Set `int-council` in `openspec/parity/capabilities.json` to `built` with this change.
