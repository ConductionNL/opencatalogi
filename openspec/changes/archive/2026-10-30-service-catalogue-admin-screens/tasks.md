# Tasks: service-catalogue-admin-screens

- [x] **T01**: `ServiceCatalogueController::index()` answers the entries as `results` too. Verify: `ServiceCatalogueControllerTest::testTheEntriesAreAnsweredAsResultsWithTheirAvailability`.
- [x] **T02**: Provide `service_catalogue_register`, `service_catalogue_entry_schema`, `case_type_definition_schema` and `knowledge_article_schema` as initial state. Verify: `ManifestSentinelParityTest`, `ProvideManifestConfigStateListenerTest`.
- [x] **T03**: `src/manifest.d/service-catalogue-admin-screens.json`: pages ServiceCatalogue, KnowledgeArticles, KnowledgeArticleDetail and their menu entries. Verify: `npm run check:manifest`.
- [x] **T04**: `src/dialogs/caseType/CaseTypeResyncDialog.vue` and the `openCaseTypeResync` row handler. Verify: `CaseTypeResyncDialog.spec.js`, `caseTypeActions.spec.js`.
- [x] **T05**: en and nl strings.
