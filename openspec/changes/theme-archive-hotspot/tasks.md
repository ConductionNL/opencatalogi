# Tasks: theme-archive-hotspot

Read `openspec/woo-build-rules.md` first. Start once `openregister/appraisal-inherited-from-a-parent` and `subjects-as-first-class-records` are merged. For OpenRegister doubles copy `environmentAwareDouble()` from `tests/Unit/Service/SitemapServiceTest.php`. Real classes checked in openregister `development`: `ObjectEntity::getRetention()` and `setRetention()` (magic), `OCA\OpenRegister\Service\Archival\Appraisal::RETAIN_PERMANENTLY_ALIASES` (`retain_permanently`, `bewaren`, `blijvend_bewaren`), `OCA\OpenRegister\Service\RetentionService::destructionRefusal(ObjectEntity $object, string $today, array $excludeUuids): ?string`, `ObjectUpdatingEvent`.

## 1. Mark a subject

- [ ] 1.1 Add `archiveHotspot`, `archiveHotspotReason` and `archiveHotspotSince` to `#theme` with a bumped version and labels (REQ-THA-001). Verify: `tests/Unit/Settings/ThemeHotspotSchemaTest.php::testTheThemeDeclaresTheHotspotFields` and `npm run check:schema-l10n`.
- [ ] 1.2 Add `ThemeHotspotListener` on `ObjectCreatingEvent` and `ObjectUpdatingEvent`, registered in `Application::register()`, with the admin check, the reason check, the appraisal write and the audit entry (REQ-THA-001). Verify: `tests/Unit/Listener/ThemeHotspotListenerTest.php` on the REAL events with `::testANonAdministratorCannotMarkAHotspot`, `::testAMarkingWithoutAReasonIsRefused`, `::testMarkingSetsTheThemesAppraisalToRetainPermanently` (fails today: no listener), `::testUnmarkingRestoresThePreviousAppraisal`; an `ApplicationRegisterInvariantTest` case.
- [ ] 1.3 Live: mark a subject on the dev instance and read the theme object back through OpenRegister's object API; paste `@self.retention` in the PR body. If OpenRegister's save resets `retention` set in the pre-save event, stop and raise it on `openregister/appraisal-inherited-from-a-parent`; do not write the appraisal another way (REQ-THA-001). Verify: the pasted read-back.
- [ ] 1.4 Add the toggle and reason to the theme form, shown to administrators only (REQ-THA-001). Verify: `tests/e2e/theme-archive-hotspot.spec.ts` "an administrator marks a subject", carrying `@e2e` REQ-THA-001.

## 2. Destruction

- [ ] 2.1 Declare `x-openregister-retention.inheritAppraisalFrom: ["themes"]` on `#publication` and bump its version (REQ-THA-002). Verify: `tests/Unit/Settings/ThemeHotspotDestructionTest.php::testThePublicationDeclaresInheritanceFromThemes` and `::testAPublicationFiledBeforeTheMarkingIsNotEligible`, which uses OpenRegister's `RetentionService::destructionRefusal()` when it exists.
- [ ] 2.2 Live: on the dev instance give a publication under a marked subject a past destruction date, run `occ openregister:retention:dry-run` (from the OpenRegister change), and paste the held-back line naming the subject (REQ-THA-002). Verify: the pasted line.

## 3. OpenCatalogi retention

- [ ] 3.1 `RetentionService::evaluate()` resolves themes first and holds hotspot publications; `buildReport()` names the subject (REQ-THA-003). Verify: `tests/Unit/Service/RetentionServiceTest.php::testAPublicationUnderAHotspotIsNotDepublished` (fails today), `::testUnresolvableThemesHoldThePublication`, `::testTheReportNamesTheHotspot`; and `tests/Unit/BackgroundJob/RetentionEvaluationTest.php` still drives `evaluate()` from the job, so the rule has its caller.
- [ ] 3.2 Show "Kept permanently: archive hotspot <subject>" on the publication page (REQ-THA-003). Verify: `tests/e2e/theme-archive-hotspot.spec.ts` "the officer sees why".

## 4. Docs

- [ ] 4.1 Document archive hotspots for archivists in `docs/` and the strings in `l10n/` (en, nl). Verify: `npm run check:l10n` and a grep for U+2014 on the changed docs.

## 5. Verification

- [ ] 5.1 `TMPDIR` a sibling directory outside the clone. PHPUnit judged by the `Tests:` line or with `--no-coverage`.
- [ ] 5.2 `run-hydra-gates.sh --base origin/development`, counting the gates that ran. gate-19 wants the `@e2e` references of 1.4 and 3.2.
- [ ] 5.3 Once before push: `COMPOSER_PROCESS_TIMEOUT=0 composer check:strict`, then `npm run lint`, `format`, `check:l10n`, `check:l10n-js`, `check:manifest`, `check:schema-l10n`. The coverage guard needs a test for every added statement.
- [ ] 5.4 One PR with `--base development`; merge development in, never rebase; no `Co-Authored-By` on any commit.

Done when merged on `development` with CI green. Row 11.14 becomes `production` only once store releases ship this and the OpenRegister change.
