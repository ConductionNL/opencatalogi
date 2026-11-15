---
kind: code
depends_on: []
---

# Proposal: service-catalogue-admin-screens

## Why

The published service and case type catalogue (`openspec/specs/published-service-and-case-type-catalogue`)
has its schemas (`serviceCatalogueEntry`, `caseTypeDefinition`,
`knowledgeArticle`) and its admin endpoints (`GET /api/service-catalogue/unavailable`,
`GET|POST /api/case-types/{id}/resync`), and no screen. Three boards draw them:

- **OcDienstencatalogus**: "Products and services", the entries with cost,
  duration, what each starts and whether it is available, an Add button, a
  row action to resynchronise its case type, and a second list "Needs repair"
  with the reason each unavailable entry carries.
- **OcZaaktypeSynchroniseren**: the resync dialog for one case type: the
  properties that differ, the catalogue value against the case type value,
  and per property Keep or Take.
- **OcKennisartikel**: a knowledge article, title, catalog and body, saved
  as a draft or published.

## What changes

- A manifest page `ServiceCatalogue` (`/service-catalogue`), `type: index`
  over the entry schema, listed from `/api/service-catalogue` so every row
  carries its `available` flag. A side panel lists the entries that need
  repair from `/api/service-catalogue/unavailable`.
- `GET /api/service-catalogue` answers its entries a second time as `results`,
  the list a list page reads. The grouped answer is unchanged.
- A row action "Resync" opens `CaseTypeResyncDialog`: it finds the case type
  definition the entry's `formBinding.caseType` names, shows
  `GET /api/case-types/{id}/resync` as a table with Keep or Take per property,
  and posts the taken properties as `accepted`.
- Manifest pages `KnowledgeArticles` (`/knowledge-articles`, `type: index`)
  and `KnowledgeArticleDetail` over `knowledgeArticle`.
- The register and the three schema ids are provided as initial state so the
  pages resolve them without a request.

## Out of scope

- The board's header action "Resync all case types": there is no endpoint
  that resynchronises every definition.
- Creating a knowledge article from a case answer in pipelinq or dossiq. The
  article keeps `sourceCase`; the case apps own the hand-over.
