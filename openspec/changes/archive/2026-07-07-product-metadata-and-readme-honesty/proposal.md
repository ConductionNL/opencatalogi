---
kind: config
depends_on: []
---

# Proposal: product-metadata-and-readme-honesty

## Summary

Correct four product-metadata/documentation defects found in the readiness audit
so OpenCatalogi's declared identity matches its actual code and licence:

1. **Licence mismatch** (`PKG-001`): `appinfo/info.xml` declares
   `<licence>agpl</licence>`, but `LICENSE`, `composer.json`, and `publiccode.yml`
   all declare **EUPL-1.2**. The app-store-facing licence is wrong.
2. **Nextcloud baseline** (`PKG-002`): `info.xml` declares
   `<nextcloud min-version="28" max-version="34"/>`; NC 28–30 are past
   end-of-life and the fleet baseline is **NC ≥ 31**.
3. **README over-promises** (`PKG-003`): the README claims an **ElasticSearch**
   backend (none exists; the real optional backend is OpenRegister **SOLR**) and
   claims **document-content full-text search** as shipped (it is the still-pending
   `add-public-fulltext-search` change).
4. **Stale API doc** (`PKG-004`): `README_AGGREGATED_PUBLICATIONS.md` documents
   `GET /api/publications/aggregated` and `DirectoryService::getPublications` as
   the entry point, but that path is **not routed** — the reachable endpoint is
   `GET /api/federation/publications` (`FederationController` →
   `PublicationService::getAggregatedPublications`), specified by the `federation`
   capability (FED-001).

## Motivation

Dimension-3 (product-readiness honesty) of the audit. All four are verified
against code at HEAD `4d8b395`:

- `appinfo/info.xml` line ~29: `<licence>agpl</licence>`. `LICENSE` line 1:
  "EUROPEAN UNION PUBLIC LICENCE v. 1.2"; `composer.json`: `"license": "EUPL-1.2"`;
  `publiccode.yml`: `license: EUPL-1.2`. The store therefore advertises the wrong
  licence for a fleet that is uniformly EUPL-1.2 (company policy).
- README claims an ElasticSearch backend; grep finds no ElasticSearch in `lib/`
  (only an unused frontend `useElastic` config flag no backend reads). The actual
  optional high-performance backend is OR SOLR (`PublicationService.php:994`,
  `CatalogiService.php:788`).
- README claims "Search across publication content and attached documents"; that
  is `openspec/changes/add-public-fulltext-search` — in-progress, with
  document-content indexing a HARD-blocked pending decision (owner: Ruben).
- `README_AGGREGATED_PUBLICATIONS.md` lines 7/20/30/124 document a path
  (`/api/publications/aggregated`) that `grep` confirms is absent from
  `appinfo/routes.php`; the real route is `federation#publications`
  (`/api/federation/publications`, routes.php:150).

Wrong licence metadata is a genuine compliance/packaging defect; the README
over-promises mislead evaluators and future implementers. These are cheap,
high-trust fixes.

## Goals

1. `info.xml` `<licence>` reflects the actual EUPL-1.2 licence, consistent with
   `LICENSE` / `composer.json` / `publiccode.yml`.
2. `info.xml` `<nextcloud min-version>` is `31` (fleet NC ≥ 31 baseline).
3. README describes the real search backend (OR SOLR, not ElasticSearch) and
   marks document-content full-text search as *planned* (pointing at
   `add-public-fulltext-search`) rather than shipped.
4. `README_AGGREGATED_PUBLICATIONS.md` documents the reachable endpoint
   (`GET /api/federation/publications`) and its real entry point, cross-referring
   the `federation` capability.

## Non-Goals

- **No behavioural code change.** This change touches only `appinfo/info.xml`
  (metadata) and the two README documents. It does not add/remove any endpoint or
  backend.
- **No new search backend.** Documenting SOLR is not adopting ElasticSearch; the
  `add-public-fulltext-search` change owns the document-content search work.
- **No licence *text* change.** `LICENSE` is already EUPL-1.2; only the `info.xml`
  declaration is corrected to match it.

## High-Level Approach

Edit `appinfo/info.xml` (`<licence>`, `<nextcloud min-version>`), the README
Features / Tech-Stack sections (ElasticSearch→SOLR, document-content search →
planned), and `README_AGGREGATED_PUBLICATIONS.md` (endpoint path + entry point).
All changes are declarative metadata/documentation; validated by re-reading the
files against the four other sources of truth (LICENSE/composer/publiccode/routes).
