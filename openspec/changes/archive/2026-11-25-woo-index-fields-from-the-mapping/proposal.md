---
kind: code
depends_on: [integriq/mapping-woo-index-field-mapping]
---

# Proposal: woo-index-fields-from-the-mapping

## Summary

The Woo-index sitemap takes four of its fields (publisher, official title, information category, type of handling) from integriq's editable mapping `woo-index-publication`, so an administrator changes where a field comes from on integriq's Mappings page without a release. Without integriq, or when the mapping refuses, the sitemap keeps today's reads. Issue opencatalogi#1672.

## Why

Row `woo-metadata-map`, "Map a source system's metadata onto the Woo-index fields (publisher, official title, category, document action) and change that mapping without a developer." State `specified`, ours `partial`, owner integriq. Re-rated 7 October 2026: "integriq built its half; opencatalogi's half (read the Woo-index fields from the woo-index-publication mapping) is open as issue opencatalogi#1672 and has no opencatalogi change yet."

The delivered change is integriq's `mapping-woo-index-field-mapping` (archived 2026-09-29, 8 of 8 tasks). It ships `OCA\Integriq\Event\MappingExecutionRequestedEvent(mappingSlug, input, sourceApp, correlationId)`, answered synchronously with `isHandled()`, `getOutput()` and `getRefusal()` (`not-found`, `not-allowed`, `failed`), and seeds `woo-index-publication`, callable by `opencatalogi`, with the rules `publisher` from `tooiIdentifier`, `officieleTitel` from `title` or `name`, `informatiecategorie` from `tooiCategorieUri` or `category`, and `soortHandeling` from `soortHandeling`.

opencatalogi does not use it: on development (c3b5d5c21) `git grep MappingExecutionRequested lib src` finds nothing and `SitemapService::mapDiwooDocument()` (lib/Service/SitemapService.php:673) reads these values in code.

## What changes

- `SitemapService` asks integriq to run `woo-index-publication` once per publication, with the publication's fields, its organisation's TOOI identifier and its category, and uses each output field that is present.
- A field the mapping does not answer, an unanswered event (integriq not installed), or a refusal falls back to today's read for that field. The sitemap never fails because of the mapping.
- The value the mapping returns goes through the same checks as today's: the category must resolve to a member of the TOOI informatiecategorieen list, the publisher must be a TOOI URI, the handling a DiWoo value. A failing value is a violation in the readiness report, as now.
- The readiness report names, per field, where its value came from (mapping or built-in) and any refusal code, so an administrator sees whether an edit on integriq's page took effect.

## Rows

| row | name | ours | what this closes |
|---|---|---|---|
| `woo-metadata-map` | Map a source system's metadata onto the Woo-index fields and change that mapping without a developer. | partial | opencatalogi reads the editable mapping |

## Out of scope

- The mapping editor. That is integriq's `/mappings` page; no screen here.
- Fields other than the four. `diwoo:format`, dates and the download URL stay in code.
