# Design: saved-searches-and-alerts

## D1 · The `savedSearch` schema

Register `publication`, schema slug `savedSearch`, fragment `lib/Settings/register.d/saved-searches-and-alerts.json`.

| Property | Type | Notes |
|---|---|---|
| `title` | string, required, max 200 | |
| `owner` | string, required | `subjectRef`, server-set |
| `query` | object, required | `{ text, filters: { informatiecategorie[], organisation[], periodFrom, periodTo }, catalog }` |
| `frequency` | enum `immediate`, `daily`, `weekly` | default `daily` |
| `active` | boolean | default true |
| `lastRunAt` | date-time | job only |
| `lastNotifiedAt` | date-time | job only, the notice trigger |
| `lastMatches` | array, max 20 | job only, `{ publication, title, url, publicationDate }` |
| `matchCount` | integer | job only, all matches of the last notice |

`authorization` allows everything to the `admin` group only, as `collection` does.

## D2 · From the saved query to the search

`SearchQueryTranslator::toSearchParams()` turns a saved query into the parameters `/api/search` takes:

| Saved query | Search parameter |
|---|---|
| `text` | `_search` |
| `filters.informatiecategorie[]` | `wooCategory[]` (the publication's information category, see `woo-dossier-publication`) |
| `filters.organisation[]` | `organization[]` |
| `filters.periodFrom` / `periodTo` | `publicationDate[gte]` / `publicationDate[lte]` |
| `catalog` | `_catalog` |

`/api/search` accepts the same saved-query names directly, through the same translator (`woo-dossier-publication`, REQ-WDP-002), so the portal block and the job speak one vocabulary.

## D3 · The matching job

`SavedSearchMatchingJob` is a `TimedJob` with a 15 minute interval. Times are judged in the instance's `default_timezone`, else Europe/Amsterdam.

- `immediate`: due every run.
- `daily`: due when it is after 07:00 today and `lastRunAt` is before 07:00 today.
- `weekly`: due when it is Monday after 07:00 and `lastRunAt` is before this Monday 07:00.
- A saved search with no `lastRunAt` is baselined: `lastRunAt` is set to now and nothing is sent, so a new search does not announce the whole archive.
- `active: false` is skipped.

For a due search the window is `(lastRunAt, now]`. The job calls `PublicationQueryService::assemblePublicSearchResults()` with the translated query, the window on `publicationDate` and `_order[publicationDate]=asc`. That method runs inside OpenRegister's anonymous scope. The job then keeps only rows of the `publication` schema that `isObjectPublic()` accepts and whose `publicationDate` lies in the window. A resident is never told about something they could not have found.

The job does nothing when portaliq is not installed. Nobody can own a saved search without it.

## D4 · The notice (C3)

The manifest declares, for both audiences:

```php
'notifications' => [
    ['ruleKey' => 'opencatalogi.savedSearch.matched', 'collection' => 'mySavedSearches',
     'on' => ['field' => 'lastNotifiedAt', 'operator' => 'changed'], 'titleField' => 'title'],
],
```

portaliq's `PortalRecordChangeListener` sees the update, writes the inbox message linking to the saved search, and dispatches the rule key, which sends email and push by the resident's preferences.

- `immediate`: one save per publication, each with `lastMatches: [that one]`, `matchCount: 1` and a new `lastNotifiedAt`.
- `daily`, `weekly`: one save with the first 20 matches in `lastMatches`, `matchCount` the total, a new `lastNotifiedAt`.
- `lastNotifiedAt` carries microseconds, so two notices in one second are still two changes.
- No match: only `lastRunAt` moves, so no notice.
- `lastRunAt` moves to the end of the window in the same save as the last notice. A crash before it sends late, never not at all.

## D5 · Portal actions

| Action | Kind | Endpoint or target | Fields |
|---|---|---|---|
| `saveSearch` | endpoint, id-addressed | `POST /index.php/apps/opencatalogi/api/portal/saved-searches` | `title`, `query`, `frequency` |
| `updateSavedSearch` | `type: update` | `savedSearch` | `title`, `frequency`, `active` |
| `pauseSavedSearch` | endpoint row action, `rowField: savedSearch` | `POST /index.php/apps/opencatalogi/api/portal/saved-searches/pause` | none |
| `deleteSavedSearch` | endpoint row action, `rowField: savedSearch` | `POST /index.php/apps/opencatalogi/api/portal/saved-searches/delete` | none |

`saveSearch` is an endpoint so the public search block can call it through `/portal/api/actions/opencatalogi/saveSearch` (C7). It validates the query shape and the frequency, caps each owner at 25 saved searches, and sets `owner` from the assertion. The row actions check `owner` like the dossier endpoints and answer 404 on a mismatch.

Collection `mySavedSearches`: `scopeField: owner`, fields `title`, `query`, `frequency`, `active`, `lastNotifiedAt`, `lastMatches`, `matchCount`. Page `zoekopdrachten` ("Mijn zoekopdrachten").

## Risks

- Many saved searches, each one a search. Each run handles at most 200 due searches and asks for at most 100 rows each.
- A backdated publication (publication date set in the past when it is created) never falls in a window. It is also never "new" in search order, so this matches what a resident would see.
