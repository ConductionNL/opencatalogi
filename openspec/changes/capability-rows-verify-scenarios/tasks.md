# Tasks: capability-rows-verify-scenarios

## 1. Requirement that describes built behaviour

- [ ] 1.1 Add an e2e test for REQ-CVS-001: add a property to the publication schema through OpenRegister's schema API, open Add on the Publications page, fill the property, save, and read it back from the public publication endpoint. Then sync the requirement into `openspec/specs/publications`.
- [ ] 1.2 Add a PHPUnit or e2e test for REQ-CVS-002 (adding and removing a schema on the catalogue form changes what `GET /api/{catalogSlug}` lists) and sync the requirement into `openspec/specs/catalogs`.
- [ ] 1.3 Add a PHPUnit test for REQ-CVS-005 (`GET /api/directory` carries this installation's public catalogues) and sync the requirement into `openspec/specs/federation`.
- [ ] 1.4 Add a PHPUnit test for REQ-CVS-006 (the public `id` and `dct:identifier` are the UUID, and the UUID address answers after a title and slug edit) and sync the requirement into `openspec/specs/publications`.

## 2. Requirements not built yet

- [ ] 2.1 `woo-rules`: add `wooCategory` to the `publicationRule` schema in `lib/Settings/register.d/publication-inspection-and-the-national-indexes.json`, refuse a rule without a served category, write it on the records the rule publishes and show it in the preview (REQ-CVS-003).
- [ ] 2.2 `wr-batch`: let Add on the Woo batches page take a case reference and create the batch through `WooService::createBatch()` with the documents delivered for that case, refusing a second open batch for the same reference (REQ-CVS-004). Today the page creates a bare batch through the generic form.
