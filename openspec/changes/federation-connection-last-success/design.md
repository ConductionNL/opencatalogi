# Design: federation-connection-last-success

Read at opencatalogi development `9aa54150`.

## Where it lands

| piece | file | what is there now |
|---|---|---|
| listing schema | `lib/Settings/publication_register.json`, schema `listing`: `status` (development, beta, stable, obsolete), `statusMessage`, `statusCode`, `lastSync` (date-time) | no last-success field |
| success write | `lib/Service/DirectoryService.php:725` `syncListing()`: `lastSync` at :862, `statusCode` 200 at :912 | fine |
| failure writes | `DirectoryService.php:995-996` (statusCode 500 and `lastSync` on a failed listing save) and `:1497` `updateDirectoryStatusOnError()` (statusCode and `lastSync` at :1534-1535) | `lastSync` moves on failure too, and no error text is kept |
| staleness | `DirectoryService.php:1383` `isListingDataOutdated()` reads `lastSync` (:1389) | keep as is |
| directory page | `src/manifest.json` page `Directory` (`/directory`, custom component `FederationDirectory`), `src/views/directory/FederationDirectory.vue`: `statusFor()` :149, `statusLabelFor()` :164, HTTP status text :182-185 | no time shown |
| sync one listing | main spec `dashboard` DIR-003, admin-only | not reachable from the page row |

## D1. Two times and an error on the listing

The `listing` schema gains `lastSuccessAt` (date-time) and `lastError` (string, at most 1000 characters). `syncListing()` sets `lastSuccessAt` with `lastSync` on success and clears `lastError`. Both failure paths set `lastSync`, `statusCode` and `lastError` (the exception message, with credentials and query strings stripped from any URL in it) and leave `lastSuccessAt` alone. `lastSync` keeps meaning "last attempt", so DIR-010 is unaffected.

The existing `statusMessage` stays for the peer's own message; `lastError` is ours.

## D2. The row says it in words

Each node in `FederationDirectory.vue` shows "Last successful sync" with a relative time (and the exact time on hover), "Last attempt" when it differs, and the error when the last attempt failed. A listing that never succeeded says "Never synchronised successfully". The screen-reader text beside the status dot (WCAG 4.1.2, already in place at :447) includes the last success.

## D3. Sync now, on the row

For administrators, each row gets a Sync now button that calls the existing single-listing sync (DIR-003) and refreshes the row. Non-administrators do not see it. The button is disabled while a sync runs.

## Declarative or imperative

- `lastSuccessAt` and `lastError` are schema properties.
- The writes stay in `DirectoryService`, which already owns the sync (it calls out to peers, an ADR-031 exception).
- The page is the existing custom component.

## Seed data

None. The register's seed objects (`lib/Settings/publication_register.json`: menus, pages, publications, documents, one catalogue, one theme, one organisation) hold no listing; listings arrive from directory syncs. The new fields start empty and fill on the first sync.

## Risks

- An error message from a peer can carry a URL with a token. D1 strips query strings and credentials before storing.
- Listings synchronised before this change have no `lastSuccessAt`. They show "No successful sync recorded yet" until the next success, which is honest.
