---
kind: code
depends_on: [publications-reference-the-shared-organisation, openregister/the-organisation-projection-is-writable]
---

# Proposal: organisations-page-edits-the-shared-organisation

## Summary

The Organizations page lists and edits OpenRegister's shared organisation, so staff can record a catalogue's publishing organisation with its government identifiers (OIN, TOOI, RSIN).

- Row: `cat-organisation` (openspec/parity/capabilities.json).
- Board: OcOrganisatie, OcCatalogus.
- Depends on: `publications-reference-the-shared-organisation` (catalogues and publications already point at `nc-organisation`) and `openregister/the-organisation-projection-is-writable` (the projection accepts create and update; every task there is ticked except other apps' migrations).

## Why

`catalog.organization` and `publication.organization` refer to OpenRegister's `nc-organisation` projection since `publications-reference-the-shared-organisation`. That change removed the app's own `organization` schema.

The two pages that show organisations did not move with it. `src/manifest.json` still points the Organizations index (line 1035) and the OrganizationDetail page (line 1060) at `"register": "@resolve:listing_register"` and `"schema": "organization"`. That schema no longer ships, so the pages show nothing, and staff have no place in the app to enter an OIN, TOOI or RSIN.

The projection carries those identifiers (`oin`, `tooi`, `rsin`, `pki`, declared in openregister `lib/Repair/SeedDirectoryVirtualSchemas.php`) and is writable for the organisation's administrator.

## What changes

- The Organizations index and OrganizationDetail pages read and write the projection through the resolved organisation keys (`organization_register`, `organization_schema`), not through the listing register.
- The detail page's identity block shows name, summary, OIN, TOOI, RSIN and PKI, editable by the organisation's administrator; other users read it.
- Add on the index creates an organisation through the projection. Delete stays off: OpenRegister refuses it and points at merging.
- The publications and catalogues lists on the detail page keep filtering on `organization`.

## Out of scope

- A second, responsible organisation on a publication: `publications-name-their-responsible-organisation`.
- Migrating existing rows of the retired schema: task 4.1 of `publications-reference-the-shared-organisation`.
