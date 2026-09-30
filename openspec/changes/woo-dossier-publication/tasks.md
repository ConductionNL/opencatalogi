# Tasks: woo-dossier-publication

## 1. Schema

- [x] 1.1 Fragment `lib/Settings/register.d/woo-dossier-publication.json` with `publicationKind`, `caseReference`, `period`; bump the publication schema and register versions (REQ-WDP-001). Verify: `tests/Unit/Settings/WooJourneyRegisterTest.php` validates real payloads against the merged schema.

## 2. Search

- [ ] 2.1 `SearchQueryTranslator::translateSearchParams()` wired into `SearchController::index()` (REQ-WDP-002). Verify: `tests/Unit/Service/SearchQueryTranslatorTest.php`.

## 3. Docs

- [ ] 3.1 `openspec validate woo-dossier-publication --strict`.
