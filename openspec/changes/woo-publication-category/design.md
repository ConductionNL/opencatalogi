# Design: woo-publication-category

## Schema

`lib/Settings/publication_register.json`, schema `publication`: add property `wooCategory`, type string, enum `infocat001` to `infocat017` (the code part of the `INFO_CAT` keys), not required, facetable. An optional property with an enum is additive and does not break existing rows. No `format` is added.

## Reading

`SitemapService::isValidSitemapRequest()` today resolves a schema id from the category title. New order for one category request:

1. Query the catalogue's publication schemas for rows whose `wooCategory` equals the code.
2. If the instance has a register titled `woo`, keep the current title lookup and merge its rows.
3. `mapDiwooDocument()` (`SitemapService.php:586`) reads `wooCategory` first, then the three properties it reads today.

## Writing

`WooService.php:973` sets `wooCategory` to `infocat014` (Woo-verzoeken en -besluiten). The publication form (`src/modals` publication modal) gets an `NcSelect` with `inputLabel`, options from a new `GET /api/woo/categories` that returns the 17 codes with Dutch and English names.

## Risks

Existing published rows have no `wooCategory`. They stay in the sitemap through the schema-title fallback or the DiWoo mapper. A backfill is not part of this change.
