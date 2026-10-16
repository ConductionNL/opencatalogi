# Tasks: publiccode-developer-overheid-harvest

Kind: mixed (see design.md, Mixed-spec rationale). Depends on integriq `sources-developer-overheid`.

## Implementation Tasks

### Task 1: Schema `publiccode` 0.4.0
- **spec_ref**: `openspec/changes/publiccode-developer-overheid-harvest/specs/publiccode-developer-overheid-harvest/spec.md#requirement-req-pdh-007-the-publiccode-schema-records-the-register-listing-additively`
- **files**: `lib/Settings/register.d/publiccode-software.json`
- **acceptance_criteria**:
  - GIVEN the schema WHEN read THEN its version is 0.4.0 and it has `developerOverheid` (object) and no separate flag
  - GIVEN a 0.3.0-shaped component WHEN validated against 0.4.0 THEN it is valid; `required` is still `name`, `url`
- [ ] Implement
- [ ] Test

### Task 2: Flow, mappings and synchronization definition
- **spec_ref**: `openspec/changes/publiccode-developer-overheid-harvest/specs/publiccode-developer-overheid-harvest/spec.md#requirement-req-pdh-001-the-harvest-flow-ships-with-the-app-and-stays-inert-until-adopted`, `#requirement-req-pdh-002-the-register-is-read-page-by-page-through-one-synchronization`, `#requirement-req-pdh-003-each-repository-is-fetched-from-its-own-forge`, `#requirement-req-pdh-004-a-repository-on-another-forge-falls-back-to-the-registers-parsed-publiccode`, `#requirement-req-pdh-005-one-object-per-repository-shared-with-the-github-harvest`, `#requirement-req-pdh-006-a-file-that-cannot-be-read-is-skipped-not-written`
- **files**: `lib/Settings/register.d/publiccode-developer-overheid-harvest.json`, `lib/Settings/publiccode-developer-overheid-sync.json`, `tests/Unit/Settings/DeveloperOverheidHarvestFlowTest.php`, `tests/Unit/Settings/DeveloperOverheidMappingTest.php`, `tests/fixtures/developer-overheid/`
- **acceptance_criteria**:
  - GIVEN the fragment WHEN its flow is read THEN it is disabled, both triggers lead to `list` only, `locate` fans out to three forge filters, branches never meet before `end`, and `end` joins
  - GIVEN every `publicCodeUrl` example in design.md D2 WHEN run through mapping `publiccode-developer-overheid-locate` THEN forge, raw path and file path match the table
  - GIVEN this repository's `publiccode.yml` and a fixture RepositorySummary WHEN mapped THEN the slug equals what `publiccode-github-hit` gives and the object validates against 0.4.0
  - GIVEN a fixture RepositoryDetail WHEN it takes the fallback THEN no `rawPubliccode` is written
  - GIVEN the filters WHEN run through JSON Logic THEN a fork naming the original is dropped and an original with `.git` is kept
- [ ] Implement
- [ ] Test

### Task 3: Demo data
- **spec_ref**: `openspec/changes/publiccode-developer-overheid-harvest/specs/publiccode-developer-overheid-harvest/spec.md#requirement-req-pdh-007-the-publiccode-schema-records-the-register-listing-additively`
- **files**: `lib/Settings/opencatalogi_mock_register.json`
- **acceptance_criteria**:
  - GIVEN the mock register WHEN imported THEN two `publiccode` demo objects carry `developerOverheid` and one does not, as in design.md Seed Data
  - GIVEN the import run twice THEN no duplicate is created (register version raised, slugs unchanged)
- [ ] Implement
- [ ] Test

### Task 4: Generalise `PubliccodeHarvestService` per harvest
- **spec_ref**: `openspec/changes/publiccode-developer-overheid-harvest/specs/publiccode-developer-overheid-harvest/spec.md#requirement-req-pdh-008-the-administrator-sets-the-harvest-up-switches-it-on-and-runs-it-from-opencatalogi`, `#requirement-req-pdh-009-the-api-key-never-touches-opencatalogi`
- **files**: `lib/Service/PubliccodeHarvestService.php`, `lib/Exception/HarvestNotReadyException.php`, `tests/Unit/Service/PubliccodeHarvestServiceTest.php`
- **acceptance_criteria**:
  - GIVEN harvest key `developer-overheid` WHEN set up twice THEN one synchronization exists, updated in place
  - GIVEN harvest key `developer-overheid` WHEN the status is read THEN it reports the source like the GitHub status does, has no credential block and reads no secret
  - GIVEN harvest key `github` WHEN the status is read THEN the payload is identical to the one before this change
- [ ] Implement
- [ ] Test

### Task 5: Controller and routes
- **spec_ref**: `openspec/changes/publiccode-developer-overheid-harvest/specs/publiccode-developer-overheid-harvest/spec.md#requirement-req-pdh-008-the-administrator-sets-the-harvest-up-switches-it-on-and-runs-it-from-opencatalogi`
- **files**: `lib/Controller/DeveloperOverheidHarvestController.php`, `appinfo/routes.php`, `tests/Unit/Controller/DeveloperOverheidHarvestControllerTest.php`
- **acceptance_criteria**:
  - GIVEN every method WHEN inspected THEN it carries `#[AuthorizedAdminSetting(settings: OpenCatalogiAdmin::class)]` and no CSRF opt-out
  - GIVEN a `HarvestNotReadyException` WHEN raised THEN the answer is 409 `{message, error}` with a static localised message
  - GIVEN any other exception WHEN raised THEN it is logged and the answer is 500 with a static message and no exception text
- [ ] Implement
- [ ] Test

### Task 6: Admin section and strings
- **spec_ref**: `openspec/changes/publiccode-developer-overheid-harvest/specs/publiccode-developer-overheid-harvest/spec.md#requirement-req-pdh-008-the-administrator-sets-the-harvest-up-switches-it-on-and-runs-it-from-opencatalogi`
- **files**: `src/views/settings/PubliccodeHarvest.vue`, `src/views/settings/Settings.vue`, `l10n/en.js`, `l10n/en.json`, `l10n/nl.js`, `l10n/nl.json`
- **acceptance_criteria**:
  - GIVEN no integriq WHEN the section opens THEN it says integriq is needed and shows no actions
  - GIVEN the source switched off WHEN the section opens THEN it says so, that the key is entered on the source, and links to the integriq source and to the key request page
  - GIVEN everything ready WHEN Set up, Switch on, Run now are chosen THEN the section shows the synchronization present, the harvest on and a started run
  - GIVEN the GitHub section WHEN opened THEN it looks and behaves as before
- [ ] Implement
- [ ] Test

### Task 7: Manual
- **spec_ref**: `openspec/changes/publiccode-developer-overheid-harvest/specs/publiccode-developer-overheid-harvest/spec.md#requirement-req-pdh-011-the-manual-describes-the-second-source-and-the-key`
- **files**: `docs/handleidingen/Publiccode.md`
- **acceptance_criteria**:
  - GIVEN the manual WHEN read THEN it describes the second source, the shared component, the set-up steps, the key request at https://apis.developer.overheid.nl/apis/key-aanvragen and the credential `developer-overheid-oss` (`generic-apikey`), and no longer says only GitHub is read
- [ ] Implement

### Task 8: Live run on a dev instance
- **spec_ref**: `openspec/changes/publiccode-developer-overheid-harvest/specs/publiccode-developer-overheid-harvest/spec.md#requirement-req-pdh-010-harvested-components-are-found-in-search-and-in-the-public-api`
- **files**: none (evidence in the PR description)
- **acceptance_criteria**:
  - GIVEN integriq's mock of the register WHEN set up, switched on and run THEN one component per repository is written and a second run updates them
  - GIVEN a 404 and a broken file in the mock page WHEN run THEN both are skipped and the run completes
  - GIVEN a repository the GitHub harvest also wrote WHEN this harvest runs THEN it is one object with both the GitHub fields and `developerOverheid`
  - GIVEN a real key WHEN `GET /repositories` is called through `developer-overheid-oss` THEN it answers 200 (or the OAuth2 fallback in proposal Risk 1 is taken)
  - GIVEN the run WHEN `/api/search` and `/api/componenten` are called anonymously THEN the components are returned
- [ ] Verify

## Verification

- All tasks checked off
- `openspec validate` passes (by hand: the CLI here lacks the `conduction` schema)
- Manual testing against the acceptance criteria
- Code review against the spec requirements

## Quality checklist

- New and changed business logic covered by PHPUnit (`tests/Unit/`)
- The five endpoints covered by Newman/Postman tests in the app's collection
- The section covered by a Playwright browser test (states: needs integriq, needs key, ready, on)
- All tests pass (`composer test`, `newman run`)
- `docs/handleidingen/Publiccode.md` updated (ADR-010); screenshot of the section in `docs/images/`
- Dutch (`nl`) and English (`en`) strings for every new user-facing string (ADR-007)
- Hydra gates: route-auth, semantic-auth, exception translation and config fail-mode green for the new controller
- Parity row `svc-software` in `openspec/parity/capabilities.json` names the second source
