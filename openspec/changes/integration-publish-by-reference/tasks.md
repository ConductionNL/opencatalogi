# Tasks: integration-publish-by-reference

## 1. Schema

- [x] 1.1 Add `documentReference`, its aggregation and the seed reference in `lib/Settings/register.d/publication-document-references.json` (REQ-PBR-001). Verify: clean `occ app:enable` imports it; `tests/Unit/Settings/RegisterFragmentTest.php`. Built: the seed reference lives in `opencatalogi_mock_register.json` beside the other demo rows, and the schema test is `tests/Unit/Settings/DocumentReferenceSchemaTest.php`.

## 2. Publishing a reference

- [x] 2.1 Add `DocumentReferenceMapper::asFileEntry()` (REQ-PBR-002). Verify: `tests/Unit/Service/DocumentReferenceMapperTest.php`.
- [x] 2.2 Append references inside their window to the sitemap page in `SitemapService::buildSitemap()` (REQ-PBR-002). Verify: `SitemapServiceTest` with one file and one reference, asserting the reference's `loc`. The validator report gets the same references through `publicationsWithFiles()`.
- [x] 2.3 Append references to the DCAT distributions (REQ-PBR-002). Verify: `tests/Unit/Service/DcatMappingServiceTest.php`. Verified in `tests/Unit/Service/DcatServiceDocumentReferenceTest.php`.
- [x] 2.4 Return references with `kind: reference` from `PublicationService::attachments()` (REQ-PBR-002). Verify: controller test on `GET /api/{catalogSlug}/{id}/attachments`. Verified on `PublicationService::withReferences()`, which `attachments()` returns through (`tests/Unit/Service/PublicationServiceReferencesTest.php`).

## 3. Adding a reference

- [x] 3.1 Add the `pub-references` widget to `PublicationDetail` in `src/manifest.json` and refuse private URLs on save (REQ-PBR-001). Verify: `tests/e2e/publish-by-reference.spec.ts` adds a public and a private URL. Built: `pub-references` (object-list) on `PublicationDetail`; `DocumentReferenceListener` refuses a non-public or non-https URL before the write, with the reason (`tests/Unit/Listener/DocumentReferenceListenerTest.php`). The e2e is not written.

## 4. Reachability

- [x] 4.1 Add `lib/BackgroundJob/DocumentReferenceCheck.php` and register it (REQ-PBR-003). Verify: `tests/Unit/BackgroundJob/DocumentReferenceCheckTest.php` with a 200, a 404 and a 405-then-206 fake. With `DocumentReferenceProbe` (HEAD, ranged GET on 405/501) and `DocumentReferences::recordCheck()`.
- [x] 4.2 List unreachable references in the DiWoo validator report (REQ-PBR-003). Verify: `SitemapServiceTest`. As `unreachableReferences` in `validateDiwooOutput()`, present when there is one; verified in `SitemapServiceTest::testTheUnreachableReferencesAreListed`.

## 5. Docs and strings

- [x] 5.1 Document references for editors in `docs/` and add the strings to `l10n/` in English and Dutch. Verify: `npm run lint` and the l10n check. Built: `docs/features/document-references.md`; en and nl strings for the widget.
