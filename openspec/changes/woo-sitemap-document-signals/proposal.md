---
kind: code
depends_on: []
---

# Proposal: woo-sitemap-document-signals

## Why

The Woo-index learns two things from each document in a DiWoo sitemap that OpenCatalogi does not tell it today: that the document's metadata changed, and which documents belong together.

Both rows come from one tender, TenderNed 407973 (https://www.tenderned.nl/aankondigingen/overzicht/407973).

- opencatalogi matrix, row `woo-sitemap-lastmod`, "Mark a document as changed in the sitemap when its metadata changes, so the Woo-index harvests it again." Origin note: "TenderNed 407973 requirement POW-03 (eis), per the Woo Index Sitemaps Handleiding". Own rating partial. Own evidence: "each document's own <lastmod> is the file's published time, falling back to the publication's updated time only when the file has none (lib/Service/SitemapService.php:510-512,526), so a metadata-only change leaves the document's lastmod unchanged and the Woo-index has no per-document signal."
- opencatalogi matrix, row `woo-sitemap-parts`, "Tell the Woo-index which documents belong together, such as a decision and its annexes, through hasPart and isPartOf in the sitemap." Origin note: "TenderNed 407973 wish POW-06". Own rating no. Own evidence: "buildSitemap maps every attached file to a separate diwoo:Document (lib/Service/SitemapService.php:343-349, 'Map each file to a separate DIWOO Document'), and mapDiwooDocument emits loc, lastmod, creatiedatum, publisher, format, informatiecategorie and documenthandeling only (:508-590). grep hasPart, isPartOf (any case) in lib, src and in the portaliq and openregister workspace checkouts finds nothing".

What the competitors show, quoted from the matrix:

| row | system | rating | evidence |
|---|---|---|---|
| `woo-sitemap-lastmod` | xxllnc Publiceren | partial | "https://vught.woopublicaties.nl/sitemap-00001.xml gives every DiWoo entry a lastmod date (e.g. 2026-05-20) and the index lists lastmod 2026-09-04 ... whether lastmod moves when only metadata changes is not documented" |
| `woo-sitemap-lastmod` | iprox.open | partial | "https://open.waterschaplimburg.nl/sitemap/sitemap-infocat014-1.xml gives every entry a lastmod timestamp such as 2025-11-27T10:09:13.833Z with changefreq always ... whether lastmod moves when only metadata changes is not documented" |
| `woo-sitemap-parts` | xxllnc Publiceren | partial | "every entry in https://vught.woopublicaties.nl/sitemap-00001.xml carries <isPartOf resource="https://vught.woopublicaties.nl/publication/<uuid>"/> and an aggregatiekenmerk grouping the documents of one publication ... no hasPart element is emitted" |

Both rows are `build` under the rule "a tender demand row", and `woo-sitemap-parts` is also in the core area (woo).

## What changes

- A document's `lastmod` moves when its file changes or when the publication's metadata changes, whichever is later.
- Every document of a publication with more than one file says which publication it belongs to (`isPartOf`).
- When an editor marks one file as the main document, that document lists its parts (`hasPart`) and each part points at it.

## Rows this closes

| matrix | row id | row name | own rating | what is missing |
|---|---|---|---|---|
| opencatalogi | `woo-sitemap-lastmod` | Mark a document as changed in the sitemap when its metadata changes, so the Woo-index harvests it again. | partial | a metadata-only change leaves the document's `lastmod` unchanged |
| opencatalogi | `woo-sitemap-parts` | Tell the Woo-index which documents belong together, such as a decision and its annexes, through hasPart and isPartOf in the sitemap. | no | no relation between documents in the sitemap |

## Existing work it builds on

- Main spec `woo-compliance`, WOO-002, WOO-006 and WOO-010: the DiWoo document mapping in `SitemapService::mapDiwooDocument()`.
- Open change `woo-compliance` states the current `lastmod` rule in its scenarios REQ-WOO-002-B and REQ-WOO-002-C (file published time, publication updated time as fallback). This change replaces that rule. Whichever of the two lands second updates those two scenarios.
- Open change `attachments-are-files`: a document is a file on its publication, with labels and its own publication window. The main document marker is a file label, so no schema changes.

## Out of scope

- The sitemap index's own `lastmod` per page. It already follows the newest publication `updated` time (`SitemapService.php:230-239`).
- Relations between documents of different publications.
- robots.txt and the registration, covered by `woo-index-harvester-connection`.
