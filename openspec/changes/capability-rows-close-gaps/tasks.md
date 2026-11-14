# Tasks: capability-rows-close-gaps

## 1. Requirements that describe built behaviour

- [x] 1.1 Name or add the PHPUnit test for REQ-CRG-001 on `WooService::canMarkReadyForReview()` and `markReadyForReview()` (an unassessed document and an empty batch are refused) and sync the requirement into `openspec/specs/woo-transparency`.
- [ ] 1.2 Name or add the e2e test for REQ-CRG-002 (add, edit and delete a term on the Glossary page, then read it from `GET /api/glossary`) and sync the requirement into `openspec/specs/content-management`.

## 2. Rows whose built state was not true

These rows lost `built` on 12 November 2026 (decision 389): their requirement is
written, the code does not do it yet. Set each row back to `built` when its task
is done and its test is green.

- [ ] 2.1 `ops-usage`: write `usageCounter_register` and `usageCounter_schema` on import and repair, the way `KEYED_SCHEMA_GROUPS` in `lib/Service/SettingsService.php` writes the other keyed schemas, so counting is on for a default install; count reads of the federation endpoint in `FederationController` too (ANA-001, ANA-002).
- [ ] 2.2 `ops-usage-series`: show the dashboard usage card with counted data on a default install once 2.1 is done (ANA-009).
- [ ] 2.3 `ops-most-viewed`: list the most viewed publications across every catalogue the officer can see, by title, with a row link that opens the publication (ANA-005).
- [ ] 2.4 `pub-versions`: on the publication detail page in `src/manifest.json`, list the publication's versions from OpenRegister's content versioning and compare two of them field by field (openregister `content-versioning`).
- [ ] 2.5 `pub-quality`: show a publication's metadata-quality score and the missing fields on its detail page before it is published (PQM-001).
- [ ] 2.6 `lc-queue`: add the retention review queue page that lists the publications flagged expiring or expired and opens each one, using `src/services/retentionStatus.js` or removing it (RET-007).
- [ ] 2.7 `lc-decide`: declare `retentionDecisionLog` on the `publication` schema in `lib/Settings/publication_register.json`, and make `dispose` in `RetentionService::recordHumanDecision()` remove the publication as the disposal decision says, not archive it (RET-006, RET-007).
- [ ] 2.8 `fed-harvest-removed`: when integriq's disappearance policy for a harvested record says unpublish, depublish the opencatalogi publication made from it; when it says keep, flag the publication for an editor (integriq `source-owned-records` REQ-SOR-002).

## 3. Not-built rows that had no plan

- [ ] 3.1 `lc-defaults`: add a retention defaults section to the catalogue edit page that reads and saves `GET`/`POST /api/retention/defaults` per Woo category (RET-004).
- [ ] 3.2 `ops-reports`: point the report widgets in `src/manifest.json` (Reports, PublicationsReport, UsageReport, FederationReport) at the register the app ships (slug `publication`) instead of `opencatalogi`, and show each report with data on a default install (REP-001).

