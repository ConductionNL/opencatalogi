# Tasks: catalogue-staff-access

Read `design.md`; D1 says why this uses OpenRegister's conditional rules and not `rbac-inherits-to-children`. OpenRegister's `rbac-scopes` is on openregister `development`.

- [ ] 1.1 Add `staffAccess` to the catalog schema in `lib/Settings/publication_register.json`, version bumped (REQ-CSA-001). Verify: schema test; clean `occ app:enable`.
- [ ] 1.2 `lib/Service/CatalogAccessService.php::recompute(int $schemaId)` per design D2, written through OpenRegister's schema service, no write when unchanged, previous set kept in IAppConfig (REQ-CSA-002). Verify: `tests/Unit/Service/CatalogAccessServiceTest.php::testAnUnrestrictedCatalogueKeepsTodaysRules`, `::testARestrictedCatalogueWritesGroupRulesWithItsFilters`, `::testTwoCataloguesOnOneSchemaAreUnited`, `::testThePublicReadRulesStayFirst`, `::testNoWriteWhenNothingChanged`.
- [ ] 1.3 Call it from `CatalogSchemaEventListener` on save and delete for the old and new schemas, without re-saving the catalogue (CAT-012); repair step running it once per schema (REQ-CSA-002). Verify: listener test on the real OpenRegister event class; repair step test.
- [ ] 1.4 Enforcement test against OpenRegister (skipped with a named reason without it) (REQ-CSA-002). Verify: `tests/Unit/Service/CatalogAccessEnforcementTest.php::testAUserOutsideTheEditGroupsIsRefused`.
- [ ] 1.5 Setup wizard private choice sets `staffAccess` (REQ-CSA-003). Verify: `tests/Unit/Controller/SetupControllerTest.php::testThePrivateChoiceRestrictsTheCatalogue`.
- [ ] 2.1 Staff access section on the catalogue edit page and the line on the catalogue page (design D4), `NcSelect` with `inputLabel`, the overlap list, the revert link (REQ-CSA-003). Verify: e2e `tests/e2e/catalogue-staff-access.spec.ts` restricts a catalogue and reads the line, carrying `@e2e` for "Restricting a catalogue".
- [ ] 3.1 nl and en strings; `docs/` page on staff access with the overlap limits; diff check while building; once before push `COMPOSER_PROCESS_TIMEOUT=0 composer check:strict`, `npm run lint`, `format`. One PR `--base development`.
