# Design — Retrofit search

**Retrofit change. Tasks describe retroactive annotation, not new implementation work.**

No code behavior changes. Documents already-shipped search behavior (frontend federation
search + facets + UI, and the internal SearchController endpoint) and attaches `@spec`
annotations.

## Code units → REQ map
| REQ | Code units |
|---|---|
| SCH-016 | src/store/modules/search.ts::searchPublications |
| SCH-017 | src/store/modules/search.ts::discoverFacetableFields, buildFacetQuery |
| SCH-018 | sidebars/search/SearchSideBar.vue, components/SearchResults.vue, components/FacetComponent.vue |
| SCH-019 | lib/Controller/SearchController.php::index |

## Notes
- `src/store/modules/search.js` is dead/orphaned (the `.ts` is the imported one); not annotated.
- Only `SearchController::index` is annotated; the other delegating methods stay plumbing.
