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

## Corrections at build time (30 Sep)

- **Refusal of an unknown code.** The publication schema ships with `hardValidation: false`, so OpenRegister stores an object without validating it. Turning hard validation on for every publication is a breaking change outside this change. REQ-WPC-001 now says an unknown code fails validation against the schema (proven with the Opis validator on the shipped fragment) and appears in no category sitemap, because the sitemap filters on equality.
- **The select.** The publication page is the manifest `detail` page, not a modal. The field is added to the Publication data widget, which renders a schema `enum` as a select and shows the `x-enum-labels` through the app's translations (Dutch names in `l10n/nl.json`). The widget cannot condition a field on the catalogue, so the field shows for every publication; it is optional.
- **`GET /api/woo/categories`** is open to any signed-in user, like `/api/woo/weigeringsgronden`: it returns a constant (`WooService::WOO_CATEGORIES`) and touches no storage.
- **Batch publish** creates no publication object; it stores the publication record on the batch (`wooPublication`). The code there is now `infocat014`.
- **Reading.** A category sitemap has up to two sources: the catalogue's registers and schemas filtered on `wooCategory` (each schema through the read-rule guard), and the old title-matched schema of a `woo` register. Page N lists page N of each source, once per publication id. A title-matched schema outside the catalogue no longer fails the request with 400; it contributes nothing, and the 400 remains only when neither source exists.
