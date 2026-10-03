---
kind: code
depends_on: []
---

# Proposal: search-council-document-filters

## Why

A citizen cannot search public council documents with filters, because nothing has shown that the public search answers for the properties decidiq publishes. decidiq feeds title-level publications of decisions, agendas and minutes and marks `documentType`, `bodyName` and `meetingDate` as facetable (`decidiq lib/Settings/register.d/108-publication-papers.json`, schema `PublicationPayload`). The public search is OpenCatalogi's: `GET /api/search` (`lib/Controller/SearchController.php`, `PublicationQueryService::assemblePublicSearchResults()`) already forwards query parameters to OpenRegister and returns `facets` and `facetable`. What is missing is a stated contract for those three properties, a date range on `meetingDate`, and a test that runs the real decidiq fragment through the search.

Row, decidiq matrix, owned by opencatalogi: `pub-09`, "Let citizens search public council documents with filters." decidiq rating partial, state building. The row's note says search and filters are OpenCatalogi's and were not checked in decidiq.

Demand, quoted from the matrix cells for this row: NotuBiz and iBabs are rated yes. Two competitors rated yes, so the decision is build.

## What is already built, and what is not

Built: the public search endpoint with facets, decidiq's facetable properties, the search side bar component in the app's own UI.

Not built here: a documented filter and facet contract for council documents, a `meetingDate` range that a reader can use, and the test of the two apps' fragments together. The page a citizen sees is Portaliq's (`portal-public-search`, rows `srch-facets` and `srch-sort`); this change gives that page a contract to call and does not build the page.

## What changes

- `GET /api/search` is documented for council documents: the filters `documentType`, `bodyName`, and `meetingDate` with `gte` and `lte`, and the facet counts returned for the first two.
- A `meetingDate` range is accepted and passed to OpenRegister's range operators.
- A contract test runs decidiq's real `PublicationPayload` fragment as a publication in a catalogue and reads the facets back through the public endpoint.

## Rows this closes

| matrix | row id | what is missing |
|---|---|---|
| decidiq | `pub-09` | the OpenCatalogi half: a tested filter and facet contract for council documents |

The matrix row is edited in the decidiq repository, not here; it stays `building` with this change named until it is built.
