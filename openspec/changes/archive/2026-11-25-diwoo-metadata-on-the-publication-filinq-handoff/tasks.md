# Tasks: diwoo-metadata-on-the-publication-filinq-handoff

Read `openspec/woo-build-rules.md` before the first command. Start once `diwoo-metadata-on-the-publication` is merged on `development`: this change tests against its `documentsoort` field, its `GET /api/woo/documentsoorten` route and its `DiwooCompletenessListener`. Read filinq's `OCA\FilinQ\Service\Publication\OpenCatalogiPublicationMap::toPublication()` on `ConductionNL/filinq` branch `development` before writing the payload, and the paired filinq change `diwoo-documentsoort-to-opencatalogi` (https://github.com/ConductionNL/filinq/issues/1344) for the shape it will write.

## 1. Cross-app contract with filinq (decision D8)

- [x] 1.1 On this side, save the exact payload filinq's `OCA\FilinQ\Service\Publication\OpenCatalogiPublicationMap::toPublication()` will produce after its paired change (`title`, `publicationDate`, `documentsoort` as label or URI, no `summary`) (REQ-DWP-006). Verify: `DiwooCompletenessListenerTest::testTheFilinqHandoffPayloadIsAccepted` and `::testAFilinqHandoffWithAnUnknownTypeIsRefused`.
- [x] 1.2 Open an issue on `ConductionNL/filinq` (or comment on the filinq lane's change) stating the contract of REQ-DWP-006 and that filinq needs its own test that `toPublication()` writes `documentsoort` and never `summary`. Link it in the PR body. With filinq absent nothing in this change depends on it. Verify: the issue link in the PR body.
  - Done 25 Nov 2026 without an issue: the filinq lane built the paired change, archived on filinq `development` as `openspec/changes/archive/2026-11-24-diwoo-documentsoort-to-opencatalogi`. Its own test of the contract is `tests/unit/Service/Publication/OpenCatalogiPublicationMapTest.php::testTheDocumentTypeGoesToItsOwnField` (title and `documentsoort`, `assertArrayNotHasKey('summary')`) and `::testNoDocumentTypeWritesNoTypeField`, on filinq `development` at 932a955.

## 2. Verification

- [x] 2.1 `TMPDIR` set to a sibling directory outside the clone. PHPUnit judged by the `Tests:` line or run with `--no-coverage`; a green suite exits 1 without a coverage driver.
- [x] 2.2 `run-hydra-gates.sh --base origin/development`, counting the gates that ran; NOT APPLICABLE is not a pass.
- [x] 2.3 Once before push: `COMPOSER_PROCESS_TIMEOUT=0 composer check:strict`, then `npm run lint`, `format`, `check:l10n`, `check:l10n-js`, `check:manifest`, `check:schema-l10n`. The coverage guard needs a test for every added statement.
- [x] 2.4 One PR with `--base development`; merge development in, never rebase; no `Co-Authored-By` on any commit.
  - Done 25 Nov 2026 (b-opencatalogi) against `development` after this change's PRs landed, in a Nextcloud 34 tree with OpenRegister main beside it: PHPUnit 7300 tests, 4 errors, all `DirectoryServiceTest::testDoCronSync*` (directory cron, inherited, not this change). Full hydra run: 81 gates pass; the four failing (7 no-admin-idor, 26 visual-coverage on `WooRedaction.vue`, 119 procedure-file count, 123 sig-floor) are inherited fleet debt. `check:l10n`, `check:l10n-js` and `check:manifest` pass. Inherited on `development`, not this change: phpcs/phpmd/phpstan findings in other files, psalm 5.26 crash, `format` (Prettier, 317 files), `check:schema-l10n` (320 uncovered vs baseline 218), `npm run lint` errors only in `DashboardSideBar.vue`. The coverage guard is CI's and was not measured here.

Done when merged on `development` with CI green, and filinq's own test of the same contract exists on filinq `development`. Row 2.25 for filinq handoffs reads `production` only once a store release ships both sides.
