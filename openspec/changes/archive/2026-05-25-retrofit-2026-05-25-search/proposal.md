# Retrofit — search

## Why
The search backend is partly specified by SCH-001..015 (Bucket 1), but the search
**frontend** (federation-search store, facet discovery, UI components) and the internal
`SearchController` endpoint had no spec coverage. This reverse-spec retroactively documents
that observed behavior.

## What Changes
Adds 4 ADDED requirements (SCH-016..019) to the `search` capability and annotates the
implementing code units with `@spec` tags. No code behavior changes.

## Affected code units
- src/store/modules/search.ts (searchPublications) (SCH-016)
- src/store/modules/search.ts (discoverFacetableFields / buildFacetQuery) (SCH-017)
- src/sidebars/search/SearchSideBar.vue, src/components/SearchResults.vue, src/components/FacetComponent.vue (SCH-018)
- lib/Controller/SearchController.php::index (SCH-019)

## Approach
- For each unit: describe observed inputs, outputs, pre/postconditions, failure modes
- Draft REQs that match behavior (not aspirational)

## Observed notes
- `src/store/modules/search.js` is an orphaned copy of the live `.ts` store
  (`store.js` imports the `.ts`). It is dead code (report flags it as "possible
  duplicate") and is **not** annotated; removal is a separate code change.
- `SearchController` is documented as an internal/admin endpoint; only its `index` method
  (the bucket_2a-listed unit) is annotated; the other delegating methods remain plumbing.

## Coverage-report drift
Report generated on `feature/declarative-annotation-pilot`. On `development`,
`src/views/search/SearchIndex.vue` (in the report) no longer exists (frontend refactor).
The 6 present units form the basis of these REQs. `SearchController::index` still
delegates to `PublicationService` and carries no reference to the removed
`ElasticSearchService` surface (#665).

Source: openspec/coverage-report.md generated 2026-05-24. See [retrofit playbook](../../../../.github/docs/claude/retrofit.md).
