---
status: proposed
---

# Harvest feed intake

## Purpose

Six more inbound protocols for OpenCatalogi harvest feeds, each a fetcher registered with OpenRegister (`openregister/app-harvest-fetchers-and-flow-node`). The pipeline, sync records, tombstones, provenance and outbound guard are OpenRegister's; draft-only and identity come from the source settings `harvest-feed-intake` writes.

## ADDED Requirements

### Requirement: OpenCatalogi registers one fetcher per protocol (REQ-HPP-001)

The system SHALL register, through OpenRegister's `RegisterSourceFetchersEvent`, the fetchers `opencatalogi.dcat-rdf`, `opencatalogi.oai-pmh`, `opencatalogi.ckan-api`, `opencatalogi.schema-org-dataset`, `opencatalogi.website-sitemap` and `opencatalogi.wordpress-rest`, each with a display name and a config schema that requires `targetCatalog` and `sourceUrl`. Each SHALL implement `IBatchSourceFetcher`, read only through the `HarvestHttpClient` OpenRegister hands it, return items keyed by a stable external id, and return `complete: false` when any page failed. The feed form SHALL offer exactly the OpenCatalogi types `GET /api/sources/types` lists. The app MUST NOT keep a protocol registry, checksum, tombstone or URL guard of its own.

#### Scenario: The six types are offered
<!-- @e2e exclude Registry contract; proven by RegisterSourceFetchersListenerTest against the real event class. -->
- **WHEN** an administrator opens the feed form
- **THEN** the type list holds the six types above beside `opencatalogi.dcat-jsonld`

#### Scenario: A failed page leaves nothing tombstoned
<!-- @e2e exclude Fetcher contract; proven by CkanApiFetcherTest::testAFailedPageMakesTheBatchIncomplete. -->
- **GIVEN** a CKAN feed whose second result page answers 500
- **WHEN** the feed runs
- **THEN** the batch is `complete: false` and OpenRegister tombstones no record of the feed

### Requirement: A website or CMS is a standing harvest source (REQ-HPP-002)

`opencatalogi.website-sitemap` SHALL read a sitemap or sitemap index at `sourceUrl`, fetch each listed page and return each page's schema.org JSON-LD block of the types in `config.types` (default `Article`, `NewsArticle`, `WebPage`, `GovernmentService`, `DigitalDocument`), keyed by the page's canonical URL; a page without such a block SHALL be reported in `errors` as skipped. `opencatalogi.wordpress-rest` SHALL read `{sourceUrl}/wp-json/wp/v2/posts` and `/pages`, follow the `X-WP-TotalPages` header, pass `modified_after` from the `since` OpenRegister hands in, and key each item by its `link`.

#### Scenario: A WordPress site feeds draft publications
<!-- @e2e exclude Server-side harvest; proven by WordpressRestFetcherTest::testPostsBecomeItemsKeyedByLink and HarvestDraftOnlyTest::testAHarvestedPublicationHasNoPublicationDate, against a recorded WordPress REST fixture. -->
- **GIVEN** a `wordpress-rest` feed on the publication schema, with a mapping of `title.rendered` to `title` and `excerpt.rendered` to `summary`
- **WHEN** its run reads three posts
- **THEN** three draft publications exist with those titles, each carrying the post URL as its source

#### Scenario: A website's pages are read from their JSON-LD
<!-- @e2e exclude Server-side harvest; proven by WebsiteSitemapFetcherTest::testPagesWithJsonLdBecomeItemsAndPagesWithoutAreSkipped. -->
- **GIVEN** a sitemap listing four pages, three of which carry `GovernmentService` JSON-LD
- **WHEN** the feed runs
- **THEN** three items are returned and the run summary names one page skipped for having no matching JSON-LD

#### Scenario: An internal address is refused
<!-- @e2e exclude Fail-closed path; proven by WebsiteSitemapFetcherTest::testAPrivateAddressIsRefusedByTheGuard. -->
- **GIVEN** a feed whose sitemap lists a page on `http://10.0.0.5/`
- **WHEN** the feed runs
- **THEN** the `HarvestHttpClient` refuses that page and the run summary names it

### Requirement: The other protocols read whole sources incrementally (REQ-HPP-003)

`opencatalogi.oai-pmh` SHALL follow resumption tokens to the end, pass `from` from `since`, key each record by its OAI identifier, and return a record whose header has `status="deleted"` in `errors` with the reason "deleted at the source", so it is not harvested again. `opencatalogi.ckan-api` SHALL page `package_search` and key each package by its `id`. `opencatalogi.dcat-rdf` SHALL parse Turtle and RDF/XML into the same dataset nodes the JSON-LD fetcher returns, keyed by the dataset IRI. `opencatalogi.schema-org-dataset` SHALL do what `website-sitemap` does with type `Dataset` only.

#### Scenario: OAI-PMH paging to the end
<!-- @e2e exclude Fetcher contract; proven by OaiPmhFetcherTest::testResumptionTokensAreFollowedToTheEnd against a recorded fixture. -->
- **GIVEN** a recorded OAI-PMH repository answering three pages
- **WHEN** the feed runs
- **THEN** the batch holds the records of all three pages and is complete

### Requirement: A harvested record stays a draft (REQ-HPP-004)

A source of any of these types SHALL be saved by `HarvestFeedService` with the same `protectedFields`, `provenance`, `identityProperty`, `deleteStrategy: flag` and `conflictStrategy: manual` as a DCAT JSON-LD source, so no harvest sets `publicationDate`, `depublicationDate` or `status`, and an update to a publication an editor changed is held as a conflict by OpenRegister.

#### Scenario: A harvest never publishes
<!-- @e2e exclude Fail-closed contract; proven by HarvestFeedServiceTest::testEveryTypeGetsTheDraftOnlySettings. -->
- **GIVEN** a `website-sitemap` feed whose page changed after an editor published the matching publication
- **WHEN** the feed runs
- **THEN** the public publication is unchanged and its sync record is `conflict`
