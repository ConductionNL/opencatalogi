# Design: search

## Context

The search feature exposes a single authenticated HTTP endpoint (`GET /api/search`) that queries publications across all available catalogs. `SearchController::index()` collects the request parameters and delegates immediately to `PublicationService::index()`. `PublicationService` uses OpenRegister's `ObjectService::buildSearchQuery()` to normalise the parameter map and `ObjectService::searchObjectsPaginated()` to execute the query against the configured register/schema magic tables. For federated requests, `PublicationService::getAggregatedPublications()` issues async GuzzleHttp calls to every directory listing marked `default: true`, then merges results and facets.

There is no `SearchService` or `ElasticSearchService` in the OpenCatalogi codebase. All filter parsing, pagination, and relevance scoring is handled inside OpenRegister.

## Goals / Non-Goals

**Goals:**
- Remove five dead-code methods from `SearchController` that have no routes
- Confirm `SearchController::index()` carries the correct Nextcloud auth annotation (authenticated, no `#[PublicPage]`, no CORS)
- Add `@spec` PHPDoc traceability tags to `SearchController` and the `PublicationService` search methods
- Ensure all user-visible strings in the four search Vue components are wrapped with `t('opencatalogi', '...')`

**Non-Goals:**
- Implementing ElasticSearch (no `ElasticSearchService` class exists, deferred)
- Adding new HTTP routes for publication detail, attachments, download, or relations via `SearchController` (covered by `PublicationsController`)
- Modifying OpenRegister's `ObjectService` or its SQL/MongoDB query generation

## Decisions

1. **Delete dead code, do not route it.** `show()`, `attachments()`, `download()`, `uses()`, and `used()` in `SearchController` duplicate functionality already present on `PublicationsController`. Removing them shrinks the attack surface and eliminates gate-5 / gate-9 failures on unreachable methods.
2. **`SearchController` stays a thin delegator.** All business logic remains in `PublicationService`. The controller's only job is to extract the `IRequest` parameter map, call `PublicationService::index()`, and wrap the result in a `JSONResponse`.
3. **No CORS on the search endpoint.** `/api/search` is for authenticated Nextcloud users only. Public frontends (tilburg-woo-ui) use `PublicationsController`'s catalog-scoped endpoints, which carry `@CORS` and `@PublicPage`.
4. **Federation is pull-based via GuzzleHttp.** Remote directories with `default: true` are queried asynchronously on each search request. No background sync queue is introduced.

## File Changes

| File | Change |
|------|--------|
| `lib/Controller/SearchController.php` | Remove `show()`, `attachments()`, `download()`, `uses()`, `used()`. Add `@spec` PHPDoc tags to class and `index()`. |
| `lib/Service/PublicationService.php` | Add `@spec` PHPDoc tags to `index()` and `getAggregatedPublications()`. |
| `src/views/SearchIndex.vue` | Wrap any bare strings with `t('opencatalogi', '...')`. |
| `src/components/SearchResults.vue` | Wrap any bare strings with `t('opencatalogi', '...')`. |
| `src/components/SearchSideBar.vue` | Wrap any bare strings with `t('opencatalogi', '...')`. |
| `src/components/FacetComponent.vue` | Wrap any bare strings with `t('opencatalogi', '...')`. |
| `l10n/en.js` + `l10n/nl.js` | Add translation keys for any newly wrapped strings. |

## Search Response Structure

```json
{
  "results": [ /* Publication objects */ ],
  "facets": {
    "themes": [
      { "_id": "milieu", "count": 8 },
      { "_id": "energie", "count": 4 }
    ],
    "organization": [
      { "_id": "gemeente-amsterdam", "count": 5 }
    ]
  },
  "count": 20,
  "total": 47,
  "limit": 20,
  "page": 1,
  "pages": 3
}
```

## Seed Data — Example Publications (Dutch values)

The following example records illustrate the data that `GET /api/search` returns. These are `Publication` objects stored in OpenRegister magic tables; search has no schema of its own.

**Publication 1 — Klimaatakkoord verslag**
```json
{
  "@self": {
    "uuid": "a1b2c3d4-0001-0001-0001-000000000001",
    "created": "2024-03-15T09:00:00Z",
    "updated": "2024-09-01T12:30:00Z",
    "published": "2024-09-01T12:30:00Z",
    "schema": 1,
    "register": 1
  },
  "title": "Klimaatakkoord voortgangsverslag 2024",
  "summary": "Jaarlijks verslag over de uitvoering van het gemeentelijk klimaatakkoord.",
  "description": "Dit verslag beschrijft de voortgang van duurzaamheidsmaatregelen in het kader van het gemeentelijk klimaatakkoord, inclusief CO2-reductie en energietransitie.",
  "organization": "gemeente-amsterdam",
  "themes": ["milieu", "duurzaamheid"]
}
```

**Publication 2 — Subsidieregeling zonnepanelen**
```json
{
  "@self": {
    "uuid": "a1b2c3d4-0002-0002-0002-000000000002",
    "created": "2024-05-20T08:00:00Z",
    "updated": "2024-05-20T08:00:00Z",
    "published": "2024-05-20T08:00:00Z",
    "schema": 1,
    "register": 1
  },
  "title": "Subsidieregeling zonnepanelen 2024",
  "summary": "Regeling voor subsidie op aanschaf en installatie van zonnepanelen voor particulieren.",
  "description": "Inwoners van de gemeente kunnen subsidie aanvragen voor de aanschaf van zonnepanelen. De subsidie bedraagt maximaal € 750 per woning.",
  "organization": "gemeente-rotterdam",
  "themes": ["energie", "duurzaamheid"]
}
```

**Publication 3 — WOB-besluit vergunningsaanvraag**
```json
{
  "@self": {
    "uuid": "a1b2c3d4-0003-0003-0003-000000000003",
    "created": "2023-11-10T14:00:00Z",
    "updated": "2024-01-05T10:15:00Z",
    "published": "2024-01-05T10:15:00Z",
    "schema": 2,
    "register": 1
  },
  "title": "WOB-besluit vergunningsaanvraag bedrijventerrein Noord",
  "summary": "Besluit op het verzoek om openbaarmaking van documenten inzake de vergunningsaanvraag.",
  "description": "Op grond van de Wet openbaarheid van bestuur zijn de gevraagde documenten met betrekking tot de vergunningsaanvraag voor bedrijventerrein Noord gedeeltelijk openbaar gemaakt.",
  "organization": "gemeente-utrecht",
  "themes": ["juridisch", "ruimtelijke-ordening"]
}
```

**Publication 4 — Afvalstoffenheffing tarieven**
```json
{
  "@self": {
    "uuid": "a1b2c3d4-0004-0004-0004-000000000004",
    "created": "2024-01-01T00:00:00Z",
    "updated": "2024-01-01T00:00:00Z",
    "published": "2024-01-01T00:00:00Z",
    "schema": 1,
    "register": 2
  },
  "title": "Tarieventabel afvalstoffenheffing 2024",
  "summary": "Overzicht van de tarieven voor afvalstoffenheffing in het belastingjaar 2024.",
  "description": "De gemeenteraad heeft de tarieven voor afvalstoffenheffing voor het jaar 2024 vastgesteld. Het tarief voor een eenpersoonskuishouding bedraagt € 187,00 per jaar.",
  "organization": "gemeente-den-haag",
  "themes": ["financien", "milieu"]
}
```

**Publication 5 — Begroting gemeentelijke diensten**
```json
{
  "@self": {
    "uuid": "a1b2c3d4-0005-0005-0005-000000000005",
    "created": "2023-09-15T09:30:00Z",
    "updated": "2023-10-01T16:00:00Z",
    "published": "2023-10-01T16:00:00Z",
    "schema": 2,
    "register": 2
  },
  "title": "Programmabegroting 2024 — gemeente Eindhoven",
  "summary": "Begroting voor het begrotingsjaar 2024, inclusief alle gemeentelijke programma's en diensten.",
  "description": "De programmabegroting 2024 beschrijft de financiële kaders en doelstellingen voor alle gemeentelijke programma's. Totale begroting: € 742 miljoen.",
  "organization": "gemeente-eindhoven",
  "themes": ["financien", "bestuur"]
}
```
