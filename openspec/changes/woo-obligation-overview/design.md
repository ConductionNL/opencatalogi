# Design: woo-obligation-overview

## Sources

`obligationSource` rows are the registry. A source app registers by writing a row with its `appId`. The reader is an event, in the style of the other cross-app contracts (a typed event with a result slot): `ObligationsRequestedEvent` (new, `lib/Event`) carries the source `appId` and collects a list of obligations set by the listener in that app. A source app that has no listener leaves the slot empty, and the service records that as unread with the reason "no reader answered". The harvest intake registers itself as source `harvest`.

## Service and controller

`ObligationOverviewService::assemble()` stays as is. A new `ObligationReadService` loads enabled `obligationSource` rows, dispatches one event per source inside a try/catch that turns a Throwable into the value `assemble()` already understands, updates `lastReadAt`, and returns the assembled array. `GET /api/obligations` in `PublicationRulesController` (admin, `#[AuthorizedAdminSetting]` style as its siblings) calls it. The docblock that says there is no overview is corrected in the same change.

## Page

`src/manifest.json` gets an `Obligations` page (type custom, admin only) with `src/views/obligations/ObligationsIndex.vue`: totals, a table with state chips, and the unread sources. Late rows are marked with text as well as colour.

## Risks

A slow source blocks the page. Each read gets a 5 second limit, and a timeout counts as unread.
