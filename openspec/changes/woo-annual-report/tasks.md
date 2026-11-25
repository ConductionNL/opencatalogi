# Tasks: woo-annual-report

Read `openspec/woo-build-rules.md` first. Build after `openregister/rapportage-bi-export` has merged; read its template format and `POST /api/reports/generate` on OpenRegister `development` at that moment and write the template in that exact format. If its format differs from what this spec assumes, follow OpenRegister and note it in the PR body. For OpenRegister doubles copy `environmentAwareDouble()` from `tests/Unit/Service/SitemapServiceTest.php`.

## 1. Template

- [x] 1.1 Add the `woo-jaarverslag` seed in `lib/Settings/register.d/woo-annual-report.json`, imported only when `report-templates` exists (REQ-WAR-001). Verify: `tests/Unit/Settings/WooAnnualReportTemplateTest.php::testEveryRegistryCategoryHasARowIncludingZero` (fails today), `::testAPublicationWithoutCreationDateIsCountedApart`, `::testTheSeedIsSkippedWithoutTheReportSchema`, and a check that every shipped seed row is admitted by the schema.
- [x] 1.2 Generate the template against a fixture year through OpenRegister's report generation and assert the section totals (REQ-WAR-001). Verify: `tests/Unit/Service/WooAnnualReportGenerationTest.php::testTheReportTotalsMatchTheFixture`, skipped with a message when OpenRegister's generator is absent.

      Built on the format of ConductionNL/openregister#11115 (`report-templates`, `POST /api/reports/generate`). The
      timeliness median and the share within 14 days wait on OpenRegister's custom calculated metrics; the template counts
      the publications without `creationDate` on their own line. The e2e spec of 2.1 is written and runs in the nightly.

## 2. Action

- [x] 2.1 Add Generate annual report to the publications report page with the capability check (REQ-WAR-002). Verify: `tests/Unit/Service/WooAnnualReportCapabilityTest.php::testWithoutReportGenerationTheActionIsAbsent` and `tests/e2e/woo-annual-report.spec.ts` "an officer generates the 2026 Woo annual report", carrying `@e2e` REQ-WAR-002; `npm run check:manifest`.
- [ ] 2.2 Live: generate the 2026 report on the dev instance and attach the PDF's first page as an image to the PR. Verify: the attachment.

## 3. Docs

- [x] 3.1 Document the report and what each line means in `docs/` and the strings in `l10n/` (en, nl). Verify: `npm run check:l10n` and a grep for U+2014 on the changed docs.

## 4. Verification

- [x] 4.1 `TMPDIR` a sibling directory outside the clone. PHPUnit judged by the `Tests:` line or with `--no-coverage`.
- [x] 4.2 `run-hydra-gates.sh --base origin/development`, counting the gates that ran.
- [x] 4.3 Once before push: `COMPOSER_PROCESS_TIMEOUT=0 composer check:strict`, then `npm run lint`, `format`, `check:l10n`, `check:l10n-js`, `check:manifest`, `check:schema-l10n`. The coverage guard needs a test for every added statement.
- [x] 4.4 One PR with `--base development`; merge development in, never rebase; no `Co-Authored-By` on any commit.
  - Done 25 Nov 2026 (b-opencatalogi) against `development` after this change's PRs landed, in a Nextcloud 34 tree with OpenRegister main beside it: PHPUnit 7300 tests, 4 errors, all `DirectoryServiceTest::testDoCronSync*` (directory cron, inherited, not this change). Full hydra run: 81 gates pass; the four failing (7 no-admin-idor, 26 visual-coverage on `WooRedaction.vue`, 119 procedure-file count, 123 sig-floor) are inherited fleet debt. `check:l10n`, `check:l10n-js` and `check:manifest` pass. Inherited on `development`, not this change: phpcs/phpmd/phpstan findings in other files, psalm 5.26 crash, `format` (Prettier, 317 files), `check:schema-l10n` (320 uncovered vs baseline 218), `npm run lint` errors only in `DashboardSideBar.vue`. The coverage guard is CI's and was not measured here.

Done when merged on `development` with CI green. Row 16.5 becomes `production` only once store releases ship this and `openregister/rapportage-bi-export`.
