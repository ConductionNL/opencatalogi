## MODIFIED Requirements

### Requirement: `diwoo:informatiecategorie` is bound to the official TOOI value list (WOO-TOOI-001)

The system MUST resolve each `diwoo:informatiecategorie` to an official TOOI
informatiecategorie URI drawn from the bundled 17-category waardelijst, rather
than trusting free-object `tooiCategorieNaam`/`tooiCategorieUri` fields. A
publication's own category value MUST resolve to a value-list member; when it does,
the emitted `diwoo:informatiecategorie` MUST carry both the official `@resource`
URI and its canonical label. When a publication carries no per-object category
value at all, the system MUST fall back to the type-level default sourced from
the `woo-category-mapping` capability — an active `WooCategoryMapping` row keyed
by `(app: "opencatalogi", objectType: <the publication's schema slug>)` — before
treating the axis as unresolved. A category value (per-object or type-level
default) that does not resolve to a value-list member MUST NOT be emitted as a
free-text `@resource`.

#### Scenario: mapped category emits the official TOOI URI

- **GIVEN** a publication in WOO category "Woo-verzoeken en -besluiten" (infocat014)
- **WHEN** its `diwoo:Document` is generated
- **THEN** `diwoo:informatiecategorie @resource` MUST be the official TOOI URI
  for that category
- **AND** the element text MUST be the category's canonical label

#### Scenario: unresolved category is not leaked as a literal

- **GIVEN** a publication whose category value has no TOOI value-list mapping
- **AND** no active `WooCategoryMapping` row exists for the publication's schema slug
- **WHEN** its `diwoo:Document` is generated
- **THEN** the document MUST NOT carry a free-text `diwoo:informatiecategorie @resource`
- **AND** the document MUST be reported by the DIWOO validator (WOO-TOOI-004)

#### Scenario: publication with no declared category falls back to the type-level default

- **GIVEN** a publication whose `category`, `tooiCategorieUri`, and `tooiCategorieNaam`
  fields are all absent
- **AND** an active `WooCategoryMapping` row exists for
  `(app: "opencatalogi", objectType: <the publication's schema slug>)`
- **WHEN** its `diwoo:Document` is generated
- **THEN** `diwoo:informatiecategorie @resource` MUST be the TOOI URI resolved from that
  `WooCategoryMapping` row
- **AND** the element text MUST be that row's resolved canonical label

#### Scenario: a publication's own category still wins over the type-level default

- **GIVEN** a publication that declares its own `category` value
- **AND** an active `WooCategoryMapping` row also exists for the publication's schema slug
- **WHEN** its `diwoo:Document` is generated
- **THEN** `diwoo:informatiecategorie` MUST reflect the publication's own declared category
- **AND** MUST NOT be overridden by the type-level default
