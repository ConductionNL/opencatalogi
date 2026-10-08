# Design: woo-obligation-overview

## Sources

`obligationSource` rows are the registry. A source app registers by writing a row with its `appId`. The reader is an event, in the style of the other cross-app contracts (a typed event with a result slot): `ObligationsRequestedEvent` (new, `lib/Event`) carries the source `appId` and collects a list of obligations set by the listener in that app. A source app that has no listener leaves the slot empty, and the service records that as unread with the reason "no reader answered". The harvest intake registers itself as source `harvest`.

## The harvest source

Harvesting runs on OpenRegister (`openregister/app-harvest-fetchers-and-flow-node`); OpenCatalogi keeps no harvest schema. `lib/Listener/HarvestObligationsListener.php` answers `ObligationsRequestedEvent` for `appId` `harvest`. It lists OpenRegister sources with `application: opencatalogi` through `HarvestFeedService`, and per source the sync records with status `imported` or `conflict` that are not tombstoned (`GET /api/sources/{id}/sync-records`, read through OpenRegister's service in PHP). For each record whose local publication is not public (stored state not `published`, or no `publicationDate`) it returns an obligation: title from the publication, record reference the publication uuid, published false, due date the record's first import time plus `config.publishWithinDays` of the source when set, else null (state unknown). A record whose publication is public counts as published. A source whose records cannot be read makes the whole `harvest` source unread with the reason, as for any other source. `publishWithinDays` (integer, optional) is added to the config schema of the OpenCatalogi fetchers in `harvest-feed-intake`; the feed modal shows it as "Publish within (days)".

## Service and controller

`ObligationOverviewService::assemble()` stays as is. A new `ObligationReadService` loads enabled `obligationSource` rows, dispatches one event per source inside a try/catch that turns a Throwable into the value `assemble()` already understands, updates `lastReadAt`, and returns the assembled array. `GET /api/obligations` in `PublicationRulesController` (admin, `#[AuthorizedAdminSetting]` style as its siblings) calls it. The docblock that says there is no overview is corrected in the same change.

## Page

`src/manifest.json` gets an `Obligations` page (type custom, admin only) with `src/views/obligations/ObligationsIndex.vue`: totals, a table with state chips, and the unread sources. Late rows are marked with text as well as colour.

## Risks

A slow source blocks the page. Each read gets a 5 second limit, and a timeout counts as unread.
