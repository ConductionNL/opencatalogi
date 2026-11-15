# Tasks: capability-rows-verify-scenarios

## 1. Requirement that describes built behaviour

- [ ] 1.1 Add an e2e test for REQ-CVS-001: add a property to the publication schema through OpenRegister's schema API, open Add on the Publications page, fill the property, save, and read it back from the public publication endpoint. Then sync the requirement into `openspec/specs/publications`.
- [x] 1.2 Add a PHPUnit or e2e test for REQ-CVS-002 (adding and removing a schema on the catalogue form changes what `GET /api/{catalogSlug}` lists) and sync the requirement into `openspec/specs/catalogs`.
- [x] 1.3 Add a PHPUnit test for REQ-CVS-005 (`GET /api/directory` carries this installation's public catalogues) and sync the requirement into `openspec/specs/federation`.
- [x] 1.4 Add a PHPUnit test for REQ-CVS-006 (the public `id` and `dct:identifier` are the UUID, and the UUID address answers after a title and slug edit) and sync the requirement into `openspec/specs/publications`.

## 2. Requirements not built yet

- [x] 2.1 `woo-rules`: add `wooCategory` to the `publicationRule` schema in `lib/Settings/register.d/publication-inspection-and-the-national-indexes.json`, refuse a rule without a served category, write it on the records the rule publishes and show it in the preview (REQ-CVS-003).
- [x] 2.2 `wr-batch`: let Add on the Woo batches page take a case reference and create the batch through `WooService::createBatch()` with the documents delivered for that case, refusing a second open batch for the same reference (REQ-CVS-004). Today the page creates a bare batch through the generic form.
  - Add by case reference: the Woo batches index's Add asks only for `caseReference` (`includeFields`) and saves through the `createWooBatch` create override (`src/services/wooBatches.js`), which posts to `POST /api/woo/batches`. With no documents posted, `WooController::createBatch()` gathers them with `CaseDocuments::forCase()`: the Woo request carrying that reference (`WooRequestStore::byReference()`), and the files on its OpenRegister record, one assessment each. An unknown reference opens no batch and answers 409 naming it; a second open batch is refused as before. Tests: `tests/Unit/Service/Woo/CaseDocumentsTest.php` (6), `src/services/wooBatches.spec.js` (3). Left: the e2e.