---
kind: mixed
depends_on: [publiccode-github-harvest]
---

# Proposal: publish-from-stackiq

## Why

Rotterdam keeps its application landscape in stackiq and wants to publish it through OpenCatalogi. OpenCatalogi can publish any OpenRegister register and schema through a catalogue. Nobody has set that up for stackiq, so today an administrator has to find stackiq's register and schema ids by hand.

Three things stand in the way of doing it safely:

- A catalogue scope holds numeric ids. A seeded catalogue can only name stackiq by slug, because the ids exist after the import.
- The slug resolver from `publiccode-github-harvest` looks a schema slug up across every app. OpenRegister lets two apps own a schema with the same slug (`module`, `usage`, `organization`). A catalogue unions its registers and schemas, so a slug that resolves to another app's schema publishes that app's objects.
- stackiq may be installed after OpenCatalogi. OpenCatalogi's import has run by then, and nothing resolves the scope again.

## What changes

1. **The Applicatielandschap catalogue.** A register fragment seeds catalogue `applicatielandschap` over register `stackiq` and schemas `module`, `moduleVersion`, `suite`, `catalogService`, `connection` and `usage`, all by slug. It ships unpublished. An administrator publishes it by giving it a publication date: one click in the catalogue form.
2. **Schema slugs resolve inside the catalogue's own registers.** Both the import backfill and the pre-save catalogue listener resolve a schema slug only among the schemas the catalogue's registers list. A slug found nowhere there stays a slug, and a slug matches no object.
3. **Installing stackiq later completes the scope.** The backfill records which register slugs and ids a catalogue still waits for. A listener on OpenRegister's register created and updated events runs the backfill again when one of those registers appears or changes.
4. **What is public stays stackiq's decision.** The catalogue adds no field filter of its own. Which stackiq objects an anonymous visitor sees follows stackiq's object read rules (`publicationDate <= now` on module, catalogService, connection). Which fields they see follows OpenRegister property-level read rules on stackiq's schemas, which strip a field from the API, from search results and from facets. stackiq has to declare those rules; this change names the fields and tests the outcome against a live response.

## What stackiq provides (lane sq)

- Never public, whole schema: `catalogContract`, `contactPerson`, `aiSystem`, `technologyComponent`. None of them is in this catalogue.
- `usage` is private unless it has a `publicationDate` in the past (sq adds the field and the read rule).
- Property-level `authorization.read: ["authenticated"]` on the fields sq listed as excluded. Until those rules ship in stackiq, OpenRegister's own anonymous API returns those fields for every published stackiq object, with or without this catalogue.

## Out of scope

- A per-catalogue field filter in OpenCatalogi. It would hide fields in OpenCatalogi while OpenRegister's own public API kept serving them.
- Publishing `organization` and the GEMMA reference register. stackiq already exposes both through OpenRegister; they are not the application landscape.
- Publishing the catalogue automatically. Publishing a landscape is an administrator's decision.

## Impact

- New: `lib/Settings/register.d/publish-from-stackiq.json`, `lib/Listener/CatalogScopePendingListener.php`.
- Changed: `CatalogScopeSlugResolver` (scoped scope resolution), `SettingsService::backfillCatalogScopes()` (public, scoped, records what is pending, reads catalogues past RBAC), `CatalogiService::computeRewrittenRegistersAndSchemas()` (scoped schema lookup), `Application` (two listener registrations), the catalogue manual.
- No migration. Without stackiq the catalogue exists, unpublished, and resolves to nothing.

## Rollback

Revert the change and delete catalogue `applicatielandschap`. No stackiq data changes.
