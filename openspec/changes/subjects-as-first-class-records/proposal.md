---
kind: code
depends_on: []
---

# Proposal: subjects-as-first-class-records

## Why

A citizen often looks for a subject, not a document: "parkeren", "de nieuwe sporthal". OpenCatalogi has subjects (the `theme` schema), but they are a vocabulary for filtering, not records a reader can land on. The portal cannot feature one on its home page either.

Rows, from `opencatalogi/_round1/compare/M1-rows.md`, with our column from `baseline/openwoo.tsv`:

- **6.28** "An administrator features a subject on the portal's home page from the register". Ours: no. Evidence: "opencatalogi's theme schema (lib/Settings/publication_register.json) has a sort field but no featured or home-page flag; the 'featured' boolean in src/entities/publication/publication.ts is a front-end entity field the publication schema does not declare; portaliq has no block that lists subjects (themes) from the register". This change is the opencatalogi half; `portaliq/home-and-theme-landing-pages` is the portal half.
- **16.9** "A subject, distinct from a publication and a document, is a first-class searchable record". Ours: partial, production. Evidence: "opencatalogi #theme is a vocabulary entry, searchable as a facet; it is not a first class searchable record with its own content".

Read on development at 35999c296. The `theme` schema (version 0.0.4) already carries `title`, `summary`, `description`, `image`, `content`, `link`, `url`, `icon`, `isExternal` and `sort`, and is publicly readable. So the content exists; what is missing is that public search returns it as a result, that a subject answers with what is filed under it, and the featured flag. Public search is `SearchController::index()` through `PublicationQueryService::assemblePublicSearchResults()`, which runs as anonymous over the catalogue schemas. `ThemesController::index()` and `show()` serve `/api/themes`.

## What changes

- The theme schema gains `featured` (boolean, default false), `featuredOrder` (integer) and `slug` (string, unique), with a bumped version.
- `GET /api/themes?featured=true` answers the featured subjects in `featuredOrder`, each with its landing data and the count of public publications under it.
- `GET /api/themes/{id}/publications` lists the public publications filed under a subject, paginated, through the same anonymous public read as search.
- Public search returns subjects as results beside publications and documents. Every result carries `resultType`: `publication`, `document` or `subject`. A subject matches on its title, summary, description and content.
- The officer's theme editor gets the featured toggle and order, and the subject page in OpenCatalogi lists what is filed under it.

## Fail closed

- The publication list and the count under a subject run through the anonymous public read path. A draft, scheduled, withdrawn or non-public publication is never listed or counted, even for a signed-in caller on the public route.
- A subject with no public publications is still a result, with count 0. It never shows titles from non-public publications.
- An external subject (`isExternal: true`) is returned with its `url` and is never featured without its own title and image; the featured list refuses to save a featured subject without a title.

## Out of scope

- The portal blocks: the featured subjects on the home page and the subject landing page are `portaliq/home-and-theme-landing-pages`.
- Filtering results by kind on the portal (`portaliq/search-filter-by-kind`). This change gives every result its `resultType` so that change can filter on it.
- Hierarchical subjects.

## Dependencies

- None to build first. Uses `ThemesController`, `SearchController`, `PublicationQueryService::assemblePublicSearchResults()` as they are on development.
- Consumers in this programme: `portaliq/home-and-theme-landing-pages` (wave 2) and `portaliq/search-filter-by-kind` (wave 2) call the routes above. The contract they must match is in REQ-SUB-002 and REQ-SUB-003.
- `theme-archive-hotspot` (opencatalogi, wave 2) adds a property to the same schema.

## Wave

Wave 1. Two portaliq changes in wave 2 need it, and it needs nothing new.

## Decisions

- D11 ("a content hit resolves to the document's own public page") is respected: a `document` result keeps resolving to its own page. This change adds the `subject` type beside it and does not change how a document hit resolves.

## Rows

| row | text | rating today | what makes it yes |
|---|---|---|---|
| 6.28 | An administrator features a subject on the portal's home page from the register | no | opencatalogi half: REQ-SUB-001 and REQ-SUB-002, scenario "An administrator features a subject"; the row is yes once `portaliq/home-and-theme-landing-pages` renders it |
| 16.9 | A subject, distinct from a publication and a document, is a first-class searchable record | partial | REQ-SUB-003 and REQ-SUB-004, scenarios "Search finds a subject" and "A subject lists what is filed under it" |
