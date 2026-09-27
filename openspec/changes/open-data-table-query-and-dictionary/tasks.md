# Tasks: open-data-table-query-and-dictionary

## 1. Store

- [ ] 1.1 Add `publishedTable` and provision the `open-data-tables` register in `lib/Settings/register.d/open-data-tables.json` (REQ-ODT-001). Verify: clean `occ app:enable` imports both.
- [ ] 1.2 Add `PublishedTableService::createFromFile()` that builds the schema from the header and a 200-row sample (REQ-ODT-001). Verify: `tests/Unit/Service/PublishedTableServiceTest.php` with a CSV of mixed column types.
- [ ] 1.3 Drive OpenRegister's import preview and commit, storing `failed` with the first refused row (REQ-ODT-001). Verify: same test class with a fake import service, a clean and a refused file.
- [ ] 1.4 Tie the table schema's read rule to the publication being public (REQ-ODT-002). Verify: API test reads rows anonymously for a public publication and gets nothing for a draft.

## 2. Query

- [ ] 2.1 Add `GET /api/{catalogSlug}/{publicationId}/tables/{tableId}/rows` with filters, search, sort and pages, refusing undeclared properties (REQ-ODT-002). Verify: `tests/Unit/Controller/PublishedTableControllerTest.php`; a Newman request filters a sample table.

## 3. Dictionary

- [ ] 3.1 Add `GET .../tables/{tableId}/dictionary` and list tables on the public publication answer (REQ-ODT-003). Verify: controller test.
- [ ] 3.2 Add `dct:conformsTo` with the dictionary URL to the CSV's DCAT distribution (REQ-ODT-003). Verify: `tests/Unit/Service/DcatMappingServiceTest.php`.

## 4. Screen

- [ ] 4.1 Add Publish as table on CSV attachments and the Tables section with the editable dictionary on `PublicationDetail` (REQ-ODT-001, REQ-ODT-003). Verify: `tests/e2e/open-data-tables.spec.ts` publishes a CSV as a table, edits a column description and reads it from the dictionary endpoint.

## 5. Docs and strings

- [ ] 5.1 Document publishing a table and querying it in `docs/`, and add the strings to `l10n/` in English and Dutch. Verify: `npm run lint` and the l10n check.
