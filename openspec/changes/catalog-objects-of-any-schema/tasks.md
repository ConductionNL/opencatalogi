# Tasks: catalog-objects-of-any-schema

## 1. Detail page

- [x] 1.1 `CatalogObjectDetail` page in `src/manifest.json`, register, schema and id from the route, with a read-only Data card.
- [x] 1.2 `CatalogPublicationsIndex` opens rows of other pairs on it, also from a catalog without the publication pair.

## 2. Index page

- [x] 2.1 `sidebar` on the Publications page.
- [x] 2.2 `otherPairConfig` with Name, Summary and Updated, passed only when the page's pair is not the publication pair.

## 3. publiccode schema

- [x] 3.1 `widget: "json"` on `description`.
- [x] 3.2 `configuration.objectSummaryField` and `objectDescriptionField` as Dutch-then-English fallback chains; schema version 0.3.0.

## 4. Tests

- [x] 4.1 Jest: row targets, the other-pair config and the sidebar pass-through in `CatalogPublicationsIndex.spec.js`.
- [x] 4.2 Vitest: manifest and schema shape in `tests/vitest/catalogObjectDetailPage.spec.js`.
- [x] 4.3 Playwright: `tests/e2e/workflows/catalog-other-schema-objects.spec.ts`.

## 5. Dependencies

- [ ] 5.1 Bump `@conduction/nextcloud-vue` to the release that contains ConductionNL/nextcloud-vue#1623, so the Data card shows the per-language description readably (COS-001).
