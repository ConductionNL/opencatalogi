## Purpose

Owns the cross-app configuration mapping from a publishing app's schema slug to a canonical Woo
informatiecategorie, so every app that publishes Woo documents resolves the same official TOOI URI for the
same kind of publishable object, sourced from a single vocabulary instead of app-local copies.

## ADDED Requirements

### Requirement: WooCategoryMapping is keyed by (app, objectType) (WOO-CAT-001)

The system MUST store each Woo category mapping row as an `(app, objectType)` pair bound to one
informatiecategorie. `app` identifies the owning app whose schema is being mapped (e.g. `"decidesk"`,
`"opencatalogi"`); `objectType` identifies that app's schema slug or payload kind. The pair MUST be
unique — the same `(app, objectType)` MUST NOT appear in more than one active mapping row.

#### Scenario: two apps map the same objectType string independently

- **GIVEN** decidesk has an active mapping for `(app: "decidesk", objectType: "decision")`
- **AND** a different app has an active mapping for `(app: "other-app", objectType: "decision")`
- **WHEN** either app's publications are resolved
- **THEN** each app's mapping MUST be resolved independently by its own `(app, objectType)` pair
- **AND** neither app's resolution MUST be affected by the other's mapping for the same `objectType` string

### Requirement: Mapped informatiecategorie MUST resolve to a real TOOI value-list member (WOO-CAT-002)

Every `WooCategoryMapping` row MUST store an informatiecategorie value that resolves to a member of the
bundled TOOI informatiecategorieen value list. A row whose informatiecategorie value does not resolve to a
value-list member MUST NOT be treated as active for resolution purposes — it MUST be surfaced as invalid
rather than silently emitting a non-canonical URI downstream.

#### Scenario: seeded row resolves to a canonical URI

- **GIVEN** a `WooCategoryMapping` row seeded from decidesk's `woo-map-besluitenlijst` intake, mapping
  `(app: "decidesk", objectType: "decision-list")` to the informatiecategorie
  "Vergaderstukken decentrale overheden"
- **WHEN** the mapping is resolved
- **THEN** it MUST resolve to the TOOI URI `https://identifier.overheid.nl/tooi/def/thes/kern/c_db4862c3`
- **AND** the resolved value MUST NOT be a `c_PLACEHOLDER_...` segment

#### Scenario: a row with an unresolvable value is not used

- **GIVEN** a `WooCategoryMapping` row whose informatiecategorie value does not match any of the 17 bundled
  value-list members
- **WHEN** a publishing app resolves its `(app, objectType)` pair
- **THEN** the resolution MUST report no default (behave as if no active mapping exists for that pair)
- **AND** MUST NOT emit the unresolved value as a free-text TOOI URI

### Requirement: Mapping rows are per-type defaults, never per-object overrides (WOO-CAT-003)

A `WooCategoryMapping` row MUST only ever supply the *default* informatiecategorie for objects of its
`(app, objectType)` pair. Any individual publication's own declared category (set at publish time on the
publication object itself) MUST take precedence over the type-level default; the mapping row itself MUST NOT
be written to by a publish action.

#### Scenario: a publication's own category overrides the type-level default

- **GIVEN** a `WooCategoryMapping` row maps `(app: "decidesk", objectType: "decision")` to
  informatiecategorie "Vergaderstukken decentrale overheden"
- **AND** a specific decision publication declares its own category as
  "Woo-verzoeken en -besluiten" (infocat014)
- **WHEN** that publication's Woo metadata is resolved
- **THEN** the resolved informatiecategorie MUST be "Woo-verzoeken en -besluiten", not the type-level default
- **AND** the `WooCategoryMapping` row for `(decidesk, decision)` MUST remain unchanged

### Requirement: A mapping for a not-yet-installed schema is inert, never blocking (WOO-CAT-004)

A `WooCategoryMapping` row whose `objectType` names a schema that is not installed in the target app (a
sibling capability not yet shipped) MUST be inert: it MUST NOT block import of the mapping data, MUST NOT
block any publication flow, and MUST NOT be reported as a compliance failure. It simply resolves to nothing
until the named schema exists.

#### Scenario: mapping for an unshipped schema does not block intake

- **GIVEN** a `WooCategoryMapping` row for `(app: "decidesk", objectType: "motion")` is seeded
- **AND** decidesk's `motion` schema has not yet been installed
- **WHEN** the mapping data is imported
- **THEN** the import MUST succeed
- **AND** no error or compliance-gap report MUST be raised for that row until the `motion` schema exists

### Requirement: Publishing apps read the mapping through OpenRegister, not a bespoke API (WOO-CAT-005)

A publishing app MUST resolve its `(app, objectType)` default informatiecategorie by querying OpenRegister
objects of the `woo-category-mapping` schema directly (the same generic object-query abstraction every app
already uses for shared OR-owned data), filtered by `app`, `objectType`, and `active: true`. OpenCatalogi
MUST NOT expose a bespoke controller/REST endpoint solely to serve this lookup to other apps.

#### Scenario: a publishing app resolves its default via a direct OpenRegister query

- **GIVEN** a publishing app knows its own app identifier and a publication's schema slug
- **WHEN** it needs the type-level default informatiecategorie
- **THEN** it MUST query OpenRegister objects of schema `woo-category-mapping` filtered by
  `app`, `objectType`, and `active: true`
- **AND** MUST NOT call an opencatalogi-specific HTTP endpoint or PHP service class to obtain the same data

### Requirement: Mapping rows carry no secrets or credentials (WOO-CAT-006)

`WooCategoryMapping` rows MUST contain only public configuration data (app identifier, object type,
informatiecategorie reference, active flag, optional notes). No credential, token, or connection-string
value MUST ever be stored on a mapping row.

#### Scenario: mapping schema rejects credential-shaped fields

- **GIVEN** the `WooCategoryMapping` schema definition
- **WHEN** its property list is reviewed
- **THEN** it MUST contain no property intended to hold a secret, API key, or credential
