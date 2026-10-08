# Design: federation-open-remote-publication

Read at opencatalogi development `9aa54150`.

## Where it lands

| piece | file | what is there now |
|---|---|---|
| search page | `src/manifest.json` page at `/search` (custom component `FederationSearch`), `src/views/search/FederationSearch.vue:220-268` | a federated result (`@self.directory` not `local`) opens `{peerOrigin}/index.php/apps/opencatalogi/#/publications/{slug}/{id}` with `window.open` (:266-267) |
| app routes | `appinfo/routes.php:262` history-mode page routes | the hash link does not match them |
| one publication | `lib/Controller/FederationController.php:135` `publication()` (public) to `PublicationService::getFederatedPublication()` (`lib/Service/PublicationService.php:2609`): local first, then `DirectoryService::getPublication()` | metadata works |
| peer fetch | `lib/Service/DirectoryService.php:2290` `getPublication()`: for each available directory, `assertSafeOutboundUrl()`, then GET `{directory with /api/directory replaced by /api/publications}/{id}` | reaches the peer through its `publications` catalogue slug, which exists only when the peer kept the seed catalogue |
| attachments | `FederationController.php:255` `publicationAttachments()` returns `PublicationService::attachments()` (`:917`) | local only |

## D1. Read the peer through its federation endpoints

A new `DirectoryService::getRemoteAttachments(string $publicationId, string $directoryUrl)` calls the peer's public `GET /api/federation/publications/{id}/attachments` with `_aggregate=false`, so the peer answers with its own attachments and never fans out again. `getPublication()` moves to the peer's `GET /api/federation/publications/{id}?_aggregate=false` as well, which removes the dependency on a catalogue slugged `publications` on the peer. Both go through `assertSafeOutboundUrl()` and the existing timeout options.

## D2. Attachments fall back to the peer

`FederationController::publicationAttachments()` returns the local attachments when the publication is local. When it is not, it looks up the directory the publication came from (the `@self.directory` of `getFederatedPublication()`) and returns the peer's list through D1. Each attachment keeps the peer's `downloadUrl`, and gets `source: {organisation, directory}` so the page can say where it lives.

## D3. A page inside the app

A new manifest page `FederatedPublication` at `/federation/publications/:id` (custom component `FederatedPublicationView`, registered in `src/registry.js`, with a matching `ui#federatedPublication` page route beside `ui#search` at `appinfo/routes.php:263` so a reload or a shared link works in history mode) reads `GET /api/federation/publications/{id}` and `/attachments`. It shows the title, summary, description, organisation, publication date, the source instance, and the attachments with download links to the peer. An "Open at the source" link uses the peer's history-mode route `/index.php/apps/opencatalogi/publications/{slug}/{id}`, or the peer's app root when the slug is unknown. `FederationSearch.vue` routes a federated result to this page instead of `window.open`.

## Declarative or imperative

The page reads two existing public endpoints; nothing is stored. Calling a peer is imperative by nature (ADR-031 exception: external integration). No schema change.

## Seed data

None. Federation needs a second instance, so the e2e spec of task 2.1 starts two instances and registers one as the other's directory.

## Risks

- A peer that is down: the page shows the metadata error from `getFederatedPublication()` and says the source could not be reached, with the time.
- A peer on an older version without `_aggregate`: it aggregates once more but answers the same data. The call carries a short timeout.
