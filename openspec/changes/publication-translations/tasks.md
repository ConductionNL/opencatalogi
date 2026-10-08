# Tasks: publication-translations

Read `design.md`; D1 lists what OpenRegister already does on its `development`. Read `openspec/woo-build-rules.md` for branch and test rules.

- [ ] 1.1 Mark `title`, `summary`, `description` translatable with source `nl` on `publication` and `catalog` in `lib/Settings/publication_register.json`, versions bumped (REQ-PTR-001). Verify: `tests/Unit/Settings/PublicationTranslationsSchemaTest.php::testTheThreeFieldsAreTranslatable`; clean `occ app:enable` imports it.
- [ ] 1.2 Pass the visitor's language through `PublicationQueryService` and the public catalogue reads; set `Content-Language`; pass `X-Source-Language`; forward the language on federation reads (REQ-PTR-002). Verify: `tests/Unit/Service/PublicationQueryServiceTest.php::testTheVisitorsLanguageIsPassedToOpenRegister`, `::testAnInvalidTagFallsBackToDutch`.
- [ ] 1.3 Language-tagged literals in `DcatMappingService` (REQ-PTR-002). Verify: `tests/Unit/Service/DcatMappingServiceTest.php::testEachLanguageIsATaggedLiteral`.
- [ ] 1.4 IAppConfig key `publication_languages` (default `["nl","en"]`) in the settings service and the admin-settings inventory, with the `NcSelect` on the settings page (design D6).
- [ ] 2.1 Language switch on the publication and catalogue edit pages, saving with `X-Translation-Target-Language`, read-only non-translatable fields, outdated marks (REQ-PTR-003).
- [ ] 2.2 Languages card on the publication page from `GET /apps/openregister/api/translations/object/{uuid}` (REQ-PTR-004).
- [ ] 3.1 e2e `tests/e2e/publication-translations.spec.ts`: add an English summary, read the publication anonymously with `Accept-Language: en` and get English title and summary with a Dutch description, see the Languages card name the missing description. Carries `@e2e` for "An English visitor reads a partly translated publication", "Adding an English summary" and "A missing description is named".
- [ ] 3.2 nl and en strings; `docs/` page on translating a publication; diff check while building; once before push `COMPOSER_PROCESS_TIMEOUT=0 composer check:strict`, `npm run lint`, `format`, `check:l10n`. One PR `--base development`.
