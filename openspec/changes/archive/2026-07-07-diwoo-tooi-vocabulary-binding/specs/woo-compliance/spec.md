## ADDED Requirements

### Requirement: `diwoo:informatiecategorie` is bound to the official TOOI value list (WOO-TOOI-001)

The system MUST resolve each `diwoo:informatiecategorie` to an official TOOI
informatiecategorie URI drawn from the bundled 17-category waardelijst, rather
than trusting free-object `tooiCategorieNaam`/`tooiCategorieUri` fields. A
publication's category value MUST resolve to a value-list member; when it does,
the emitted `diwoo:informatiecategorie` MUST carry both the official `@resource`
URI and its canonical label. A category value that does not resolve to a
value-list member MUST NOT be emitted as a free-text `@resource`.

#### Scenario: mapped category emits the official TOOI URI

- **GIVEN** a publication in WOO category "Woo-verzoeken en -besluiten" (infocat014)
- **WHEN** its `diwoo:Document` is generated
- **THEN** `diwoo:informatiecategorie @resource` MUST be the official TOOI URI
  for that category
- **AND** the element text MUST be the category's canonical label

#### Scenario: unresolved category is not leaked as a literal

- **GIVEN** a publication whose category value has no TOOI value-list mapping
- **WHEN** its `diwoo:Document` is generated
- **THEN** the document MUST NOT carry a free-text `diwoo:informatiecategorie @resource`
- **AND** the document MUST be reported by the DIWOO validator (WOO-TOOI-004)

### Requirement: `diwoo:publisher @resource` is a TOOI organisatie URI (WOO-TOOI-002)

The system MUST emit `diwoo:publisher @resource` as a valid TOOI organisatie
identifier URI (`https://identifier.overheid.nl/tooi/id/…`), resolved from the
publication's owning OpenRegister organisation via its `tooiIdentifier`
property, not as the organisation's OpenRegister UUID. When the organisation
carries no `tooiIdentifier`, the `@resource` attribute MUST be omitted (the
human-readable `#text` publisher MAY still be emitted) and the document MUST be
reported by the DIWOO validator.

#### Scenario: organisation with a TOOI identifier

- **GIVEN** a publication whose owning organisation has
  `tooiIdentifier = https://identifier.overheid.nl/tooi/id/gemeente/gm0855`
- **WHEN** its `diwoo:Document` is generated
- **THEN** `diwoo:publisher @resource` MUST be that TOOI organisatie URI

#### Scenario: organisation without a TOOI identifier

- **GIVEN** a publication whose owning organisation has no `tooiIdentifier`
- **WHEN** its `diwoo:Document` is generated
- **THEN** `diwoo:publisher` MUST NOT carry a `@resource` that is the OR UUID
- **AND** the document MUST appear in the DIWOO validator report

### Requirement: `diwoo:soortHandeling` is bound to the DiWoo value list (WOO-TOOI-003)

The system MUST resolve `diwoo:soortHandeling` through the bundled DiWoo
soortHandeling waardelijst rather than emitting a hard-coded constant. The
default MUST remain `ontvangst` (a value-list member) for backwards
compatibility, but a publication or catalog MAY declare a different handling
type, which MUST resolve to a value-list member before it is emitted.

#### Scenario: default handling type resolves through the value list

- **GIVEN** a publication that declares no explicit handling type
- **WHEN** its `diwoo:Document` is generated
- **THEN** `diwoo:soortHandeling` MUST be `ontvangst` resolved as a value-list member

#### Scenario: declared handling type is honoured

- **GIVEN** a publication declaring handling type `vaststelling`
- **WHEN** its `diwoo:Document` is generated
- **THEN** `diwoo:soortHandeling` MUST be `vaststelling` from the value list

### Requirement: Bundled TOOI/DiWoo value lists and a DIWOO validator (WOO-TOOI-004)

The OpenCatalogi register bundle MUST ship the TOOI/DiWoo value lists
(informatiecategorieën, organisatie-identificatoren, soortHandeling) as
reference data, and admin/publisher settings MUST provide a "Validate DIWOO
output" action that runs the sitemap mapping in a dry-run mode and reports, per
document, any axis that could not resolve to an official value-list URI. The
validator MUST be advisory — it MUST NOT prevent the sitemap from being served.

#### Scenario: value lists resolvable at render time

- **GIVEN** the OpenCatalogi register bundle is installed
- **WHEN** a DIWOO sitemap is generated
- **THEN** the informatiecategorie, organisatie, and soortHandeling value lists
  MUST be resolvable for binding

#### Scenario: validator reports an unresolved axis

- **GIVEN** a catalog with one publication whose organisation lacks a `tooiIdentifier`
- **WHEN** the publisher runs "Validate DIWOO output"
- **THEN** the report MUST list that document with the unresolved `publisher` axis
- **AND** the sitemap endpoint MUST still serve XML for the catalog
