---
kind: code
depends_on: []
---

# Proposal: diwoo-sitemap-structure

## Why

The Woo-index harvester (KOOP) reads a DiWoo sitemap as a sitemaps.org `urlset`: one `url` per document, with `loc` and `lastmod` of the sitemap and a `diwoo:Document` after them. OpenCatalogi serves a `diwoo:Documents` root instead, puts `loc` and `lastmod` inside `diwoo:DiWoo`, leaves out the mandatory `diwoo:titelcollectie`, and writes `diwoo:atTime` and `lastmod` as `2024-02-02 08:00:00`, which is neither an `xs:dateTime` nor a W3C datetime. Validated against the official DiWoo 0.9.8 XSD, no document of a sitemap page is valid today (`tests/Unit/Service/fixtures/sitemap-diwoo-xsd-errors.json`, recorded by #2342). The harvester can refuse such a page as a whole.

The authority is the standard itself: the 0.9.8 XSD at https://standaarden.overheid.nl/diwoo/metadata/0.9.8/xsd/diwoo/diwoo-metadata.xsd and the example sitemaps the standard publishes at https://standaarden.overheid.nl/diwoo/metadata/0.9.8/xml/ (`sitemap-alleenverplicht.xml`, `sitemap-uitgebreid.xml`).

## What changes

- **BREAKING (public XML shape):** a category sitemap page is a `urlset` in the sitemaps.org namespace, with the `diwoo` and `xsi` namespaces and the schema locations of both schemas. Each downloadable file is one `url` holding, in this order, `loc` (the download URL), `lastmod` and `diwoo:Document`. `diwoo:DiWoo` no longer holds `loc` or `lastmod`. The set of documents on a page does not change.
- `lastmod` and `diwoo:atTime` are written as `xs:dateTime` with an offset (`2024-02-02T08:00:00+00:00`). A value without a zone is read as UTC, so the output does not depend on the server's time zone.
- `diwoo:titelcollectie/diwoo:officieleTitel` carries the publication's `title`. A publication without a title gets a violation `officieleTitel` in the DiWoo validator (WOO-TOOI-004), the same way an unresolved axis does.
- The sitemap golden validates every `diwoo:Document` of a page green against the vendored, hash-checked 0.9.8 XSD, and checks the `url` structure. The two official example sitemaps are vendored next to the XSD (hash-checked, verbatim) and validate under the same harness with no error but the fictitious municipalities they use (`gm9999`, `gm9998`, not in the organisation list), so the harness is shown to accept what the standard accepts and to reject what it rejects.

## Capabilities

### New Capabilities

None.

### Modified Capabilities

- `woo-compliance`: WOO-002 (the page structure), WOO-006 (the field list and where `loc` and `lastmod` sit), WOO-TOOI-004 (the title violation).

## Impact

- `lib/Service/SitemapService.php` (root and attributes), `lib/Service/SitemapDocumentMapper.php` (the `url` entry, title, datetimes). `XMLResponse` is unchanged.
- `WooReadinessService` reads `<loc>` anywhere in a page and looks for `<diwoo:Document`; both still hold.
- Harvesters that parsed the old `diwoo:Documents` shape must read the `urlset` shape. That shape is the one the standard documents.
- Overlap with `diwoo-metadata-on-the-publication`: this change does its task 5.3 (a rendered page validates against the XSD) and the `officieleTitel` part of 5.1. Omitting a document that stays invalid (its REQ-DWP-004) is not done here: this change leaves the set of published documents as it is.

## Out of scope

- New stored fields (documentsoort, language, validity dates): `diwoo-metadata-on-the-publication`.
- The publisher label. It is the owning user id today (`file.owner` or `@self.owner`), not the organisation's name. Changing it changes what is published about a person, so it goes to a separate held REVIEW change.
- Validation at generation time: `woo-national-output-assurance`.
