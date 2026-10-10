# Tasks: the-public-and-community-surface

> Archive pass 2026-10-07: not archived; open: 2.2 (no page renders the banner: `GET /api/banners` has no caller under `src/`), 3.3 (`CommunityController::feed()` filters publications on `catalog`, a property the publication schema does not declare, and the feed lists every notice, not only the catalogue's).

## 1. The status page

- [x] 1.1 `serviceStatus` schema: component, state, message, updated at (REQ-PCS-101)
- [x] 1.2 The public status page, rendering components with their history and the date each state was set (REQ-PCS-101)
- [x] 1.3 Subscriptions to the status page through the notification dialect, with a confirmed address for an anonymous reader (REQ-PCS-102) Removed 2026-09-29 by remove-decided-no-dead-code (decided no).

## 2. The banner

- [x] 2.1 `instanceBanner`: body, period, severity, dismissable (REQ-PCS-103)
- [x] 2.2 Render it to every user between its dates, and remember a dismissal per user (REQ-PCS-103). `src/components/InstanceBanners.vue` mounted in `src/App.vue` reads `GET /api/banners` (period and per-user dismissal decided server side, `NoticeBoardController::banners`, `BannerService::forUser`) and posts `POST /api/banners/dismiss`; client half pinned in `tests/vitest/instanceBanners.spec.js` (6/6). Live look owed: see STATE Still owed.

## 3. The notice board and the feed

- [x] 3.1 `notice` schema per catalogue, with an optional link to a publication (REQ-PCS-104)
- [x] 3.2 Comments per notice board, off by default, with a named moderator when enabled (REQ-PCS-104)
- [x] 3.3 An Atom feed per catalogue over published records and notices, access-checked per entry (REQ-PCS-105). Q-opencatalogi-1 answered (decision 138): the records are what an anonymous reader may read under the publication schema's own read rules, inside the catalogue, the same check as `/api/{catalogSlug}`; the rule projection is dropped. `PublicationQueryService::readCatalogueAsAnonymous()` (read rules for every caller, inside `runAsAnonymous()`), `AtomFeedService::entries()` / `noticesOfCatalogue()`, `CommunityController::feed()` (404 for an unknown catalogue, only the catalogue's own boards' notices). Tests: `PublicationQueryServiceTest::testTheCatalogueFeedReadsAsAnAnonymousReaderInsideTheCatalogue`, `::testTheFeedDropsASchemaWithoutReadRulesEvenForASignedInCaller`, `::testAFeedWithNoReadableSchemaIsEmptyAndReadsNothing`, `::testThePublicationSchemaKeepsADraftFromAnAnonymousReader`; `CommunityControllerTest::testTheFeedCarriesWhatAnAnonymousReaderMayReadInTheCatalogue`, `::testASchemaWithoutReadRulesNeverReachesTheFeed`, `::testAnUnknownCatalogueHasNoFeed`; `CommunitySurfaceTest::testAFeedCarriesOnlyTheNoticesOfItsOwnCatalogue`.

## 4. The reader's answer

- [x] 4.1 `vote` on a published record: one per reader token, distribution readable, individual votes not (REQ-PCS-106) Removed 2026-09-29 by remove-decided-no-dead-code (decided no).
- [x] 4.2 Throttle the vote and comment endpoints per address under ADR-082 (REQ-PCS-106) Removed 2026-09-29 by remove-decided-no-dead-code (decided no).

## 5. The markup endpoint

- [x] 5.1 A public render endpoint that takes our markup and returns our HTML, with no side effect (REQ-PCS-107)

## 6. Quality

- [x] 6.1 PHPUnit: the feed omits a draft, one vote per token, a subscription needs a confirmation, the banner period
- [x] 6.2 Playwright `tests/e2e/public-and-community-surface.spec.ts`: read the status page, subscribe, see a banner, vote on a published record
- [x] 6.3 CORS headers on every new public endpoint, per the app's public endpoint rule
- [x] 6.4 Dutch and English strings; docs; `openspec validate the-public-and-community-surface --strict`

## What this change does not do, and why it says so

- **This app sends nothing itself.** The `serviceStatus` schema declares its
  state change under `x-openregister-notifications` with the `nc-notification`
  channel, and `SubscriptionService` decides who is eligible. Delivering to an
  external email address is the engine's, not this app's, and no mailer is added
  here. What is implemented and tested is the property that matters: an
  unconfirmed address is never in the recipient list.
- **The render endpoint is the contract, and the two `marked`-based Vue views
  have not been migrated to it.** `ViewObject.vue` and `WooRedactionView.vue`
  still render markdown in the browser with `marked`, so byte-parity between
  those two views and this endpoint is **not** claimed. The endpoint renders a
  documented subset, listed in `docs/features/public-and-community-surface.md`,
  and migrating those views to call it is follow-up work.
- **Comment bodies are not built.** A board declares whether comments are
  offered and who moderates them, and the save is refused when comments are on
  and nobody moderates. Storing and moderating the comments themselves is not
  in this change.
- **The sitemap exclusion is available, not retrofitted.**
  `NoticeBoardService::excludeNotices()` is the rule, and a notice is its own
  schema rather than a publication, so the existing sitemap and DiWoo builders
  never see one. No existing builder was changed.
