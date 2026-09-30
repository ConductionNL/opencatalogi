---
kind: code
depends_on: []
---

# Proposal: woo-publication-category

## Why

A publication cannot be filed under one of the 17 information categories of the Woo. The 17 categories exist only as sitemap file names (`SitemapService::INFO_CAT`, `lib/Service/SitemapService.php:77-93`). The sitemap finds a category's publications by looking for a schema whose title equals the category name inside a register titled `woo` (`SitemapService.php:400-450`). The app provisions no such register, and the publication schema in `lib/Settings/publication_register.json` has no category property. A batch publish from a Woo request hardcodes `wooCategory` to `verzoek` (`WooService.php:973`).

Row, opencatalogi matrix: `woo-categories`, "File each publication under one of the 17 information categories of the Woo." Own rating partial, state building. Note in the matrix: `openspec/changes/woo-category-mapping-intake` specifies a `WooCategoryMapping` schema that is not built.

Demand, quoted from the matrix cells for this row: xxllnc Publiceren, iprox.open and Decos JOIN Woo Portaal are rated yes. Three competitors rated yes, and the row is in the core Woo area, so the decision is build.

## What is already built, and what is not

Built: the 17 codes and names, the sitemap index per category, the DiWoo mapper that reads `category`, `tooiCategorieUri` or `tooiCategorieNaam` from a publication (`SitemapService.php:586`), and the TOOI vocabulary in `TooiVocabularyService`.

Not built: a category the editor can choose, a stored value the sitemap reads, and a sitemap that works without a hand-made `woo` register. `woo-category-mapping-intake` gives a per-type default; this change gives the per-publication value and the read path both use.

## What changes

- The publication schema gets an optional `wooCategory` property that holds one of the 17 category codes.
- The publication form shows a category select for publications in a Woo-enabled catalogue.
- Each category sitemap lists the publications whose `wooCategory` matches its code. The old title-based schema lookup stays as a fallback for instances that already run a `woo` register.
- Batch publish from a Woo request sets `wooCategory` to the Woo request and decision code instead of the string `verzoek`.

## Rows this closes

| matrix | row id | what is missing |
|---|---|---|
| opencatalogi | `woo-categories` | a stored category per publication and a sitemap that reads it |

## Out of scope

The per-type default table (`woo-category-mapping-intake`). This change reads that table as a fallback when it exists and does not build it.
