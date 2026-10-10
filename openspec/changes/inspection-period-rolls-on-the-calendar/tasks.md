# Tasks: inspection-period-rolls-on-the-calendar

Read `openspec/woo-build-rules.md` first. Mirror the existing `CommentPeriodService` use of `TermRoll` and its tests (`tests/Unit/Service/Publication/CommentPeriodServiceTest.php`). Use real dates in the tests: Sunday 13 December 2026, Saturday 12 December 2026, Christmas Friday 25 December 2026; a fixed clock, never "now". For OpenRegister doubles copy `environmentAwareDouble()` from `tests/Unit/Service/SitemapServiceTest.php`; the calendar calculator's `add()` and `roll()` are the real methods behind `TermRoll`, check their signatures in openregister before mocking.

## 1. Inspection opens on a rolled end

- [ ] 1.1 `InspectionService` takes `TermRoll` and computes `endDate` through `endDate()`, storing `unrolledEndDate` and `rolledBy`; add both to the `inspection` schema with a bumped version (REQ-IPR-001, REQ-PIN-103). Verify: `tests/Unit/Service/Publication/InspectionServiceTest.php::testAnEndOnASundayRollsToMonday` (fails today), `::testAnEndOnChristmasRollsPastBothHolidays`, `::testAnUnreachableEngineRefusesToOpen`, `::testTheLinkStillWorksOnTheRolledDay`.
- [ ] 1.2 Assert the wiring from the caller: `InspectionController::open()` reaches the rolled computation. Verify: `tests/Unit/Controller/InspectionControllerTest.php::testOpenStoresTheRolledEndDate`.

## 2. API dates are rolled

- [ ] 2.1 Add `TermRoll::roll()` (REQ-IPR-002). Verify: `tests/Unit/Service/Publication/TermRollTest.php::testAGivenDateOnASaturdayRollsToMonday` and `::testAWorkingDayIsUnchanged`.
- [ ] 2.2 Add `TermRollListener` on `ObjectCreatingEvent` and `ObjectUpdatingEvent` for `inspection` and `commentPeriod`, registered in `Application::register()` (REQ-IPR-002). Verify: `tests/Unit/Listener/TermRollListenerTest.php::testAnApiEndDateOnASaturdayIsRolledToMonday` (fails today), `::testAnUnreachableEngineRefusesTheSave`, `::testAnUnchangedEndDateIsNotRolledAgain`, on the REAL events; an `ApplicationRegisterInvariantTest` case.
- [ ] 2.3 Live: save a comment period through OpenRegister's object API on the dev instance with a Saturday end date and paste the stored dates in the PR body (REQ-IPR-002). Verify: the pasted read-back. (live pass, decision 139)

## 3. Verification

- [ ] 3.1 `TMPDIR` a sibling directory outside the clone. PHPUnit judged by the `Tests:` line or with `--no-coverage`.
- [ ] 3.2 `run-hydra-gates.sh --base origin/development`, counting the gates that ran.
- [ ] 3.3 Once before push: `COMPOSER_PROCESS_TIMEOUT=0 composer check:strict`, then `npm run lint`, `format`, `check:l10n`, `check:l10n-js`, `check:manifest`, `check:schema-l10n`. The coverage guard needs a test for every added statement.
- [ ] 3.4 One PR with `--base development`; merge development in, never rebase; no `Co-Authored-By` on any commit.

Done when merged on `development` with CI green. This change closes no row itself; row 10.9 becomes `production` once a store release ships this and `dossiq/woo-term-is-computed-and-reported-right`.
