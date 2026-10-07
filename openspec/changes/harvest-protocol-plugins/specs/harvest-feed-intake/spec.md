---
status: proposed
---

# Harvest feed intake

## ADDED Requirements

Amendment 2026-10-05, Woo capability programme, row 1.6. The plugin interface and the other protocols of this change are still authored at pickup (task 0.1); these requirements fix the website and CMS half now.

### Requirement: A website or CMS is a standing harvest source (REQ-HPP-001)

The protocol plugin registry SHALL offer two protocols for a `HarvestFeed`: `website-sitemap`, which reads a sitemap (or sitemap index) at `sourceUrl`, fetches each listed page and reads its schema.org JSON-LD `<script type="application/ld+json">` blocks of the types the feed names (default `Article`, `NewsArticle`, `WebPage`, `GovernmentService`, `DigitalDocument`); and `wordpress-rest`, which reads `{sourceUrl}/wp-json/wp/v2/posts` and `/pages` with paging, and `modified_after` from the feed's last successful run. Each item SHALL be mapped with the feed's JSON-path `itemMapping` into the target schema. Every fetch SHALL go through the same outbound-URL guard, timeouts and backoff as the DCAT JSON-LD protocol of `harvest-feed-intake`, and SHALL be refused for a private, loopback or link-local address.

#### Scenario: A WordPress site feeds draft publications
<!-- @e2e exclude Server-side harvest; proven by WordpressRestPluginTest::testPostsBecomeDraftPublicationsWithTheirSource, against a recorded WordPress REST fixture, which fails on today's code because no such plugin exists. -->

- **GIVEN** a feed with protocol `wordpress-rest`, target the publication schema, and a mapping of `title.rendered` to `title` and `excerpt.rendered` to `summary`
- **WHEN** its run fetches three posts
- **THEN** three draft publications exist with those titles, each carrying the post URL as its source

#### Scenario: A website's pages are read from their JSON-LD
<!-- @e2e exclude Server-side harvest; proven by WebsiteSitemapPluginTest::testPagesWithJsonLdBecomeItemsAndPagesWithoutAreSkipped. -->

- **GIVEN** a sitemap listing four pages, three of which carry `GovernmentService` JSON-LD
- **WHEN** the feed runs
- **THEN** three items are mapped and the run reports one page skipped for having no matching JSON-LD

#### Scenario: An internal address is refused
<!-- @e2e exclude Fail-closed path; proven by WebsiteSitemapPluginTest::testAPrivateAddressIsRefused. -->

- **GIVEN** a feed whose sitemap lists a page on `http://10.0.0.5/`
- **WHEN** the feed runs
- **THEN** that page is not fetched and the run names it as refused

### Requirement: A harvested record stays a draft (REQ-HPP-002)

A publication created or updated by a `website-sitemap` or `wordpress-rest` run SHALL be saved without `publicationDate`, so it is a draft (and in stored state `draft` once `publication-lifecycle-on-or` is merged). A harvest SHALL NOT set or move `publicationDate`, `status` or `unlisted` on an existing publication. An update to a publication an editor has published SHALL be parked as a `conflict` item, as `harvest-feed-intake` parks a locally authored collision, and SHALL NOT change the public record.

#### Scenario: A harvest never publishes
<!-- @e2e exclude Fail-closed contract; proven by HarvestDraftOnlyTest::testAHarvestedPublicationHasNoPublicationDate and HarvestDraftOnlyTest::testAnUpdateToAPublishedRecordIsParkedAsConflict. -->

- **GIVEN** a feed whose source page is updated after an editor published the matching publication
- **WHEN** the feed runs
- **THEN** the public publication is unchanged and the item is a `conflict`

### Requirement: Change detection and provenance per item (REQ-HPP-003)

Each item SHALL be identified by its canonical page or post URL (`externalUri`) and SHALL carry a SHA-256 checksum over its normalised mapped payload. A run SHALL create a draft for a new URL, update the draft when the checksum changed, leave it when unchanged, and set the soft tombstone flag when a URL disappears from the source. The local publication SHALL carry `dct:source` (the URL) and `prov:wasDerivedFrom` (the feed), as the DCAT protocol does.

#### Scenario: A changed page updates its draft, an unchanged one does nothing
<!-- @e2e exclude Server-side harvest; proven by WebsiteSitemapPluginTest::testOnlyAChangedPageUpdatesItsDraft. -->

- **GIVEN** a feed that harvested two pages yesterday
- **WHEN** one page's text changed and the feed runs again
- **THEN** that page's draft is updated and its item is `updated`
- **AND** the other item is `unchanged` and its draft untouched
