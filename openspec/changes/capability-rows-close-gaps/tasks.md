# Tasks: capability-rows-close-gaps

## 1. Requirements that describe built behaviour

- [ ] 1.1 Name or add the PHPUnit test for REQ-CRG-001 on `WooService::canMarkReadyForReview()` and `markReadyForReview()` (an unassessed document and an empty batch are refused) and sync the requirement into `openspec/specs/woo-transparency`.
- [ ] 1.2 Name or add the e2e test for REQ-CRG-002 (add, edit and delete a term on the Glossary page, then read it from `GET /api/glossary`) and sync the requirement into `openspec/specs/content-management`.

## 2. Requirements still to build

- [ ] 2.1 In `lib/Settings/publication_register.json`, add `relatedPublications` to the `publication` schema as an array of `$ref: publication` with a relation annotation and inverse label, and bump the schema version (REQ-CRG-003).
- [ ] 2.2 In `src/manifest.json`, show the related publications in both directions on the publication detail page and let an editor add and remove them (REQ-CRG-003).
- [ ] 2.3 In `lib/Service/PublicationQueryService.php`, return only the related publications the reader may read on the public read (REQ-CRG-003). Set row `pub-relations` to `built` when the tests are green.
