# Catalogs

## Purpose

An administrator limits which staff see and edit the publications of a catalogue; OpenRegister enforces it through conditional rules OpenCatalogi writes. Design: `../../design.md`. Edit page board `OcCatalogusBewerken` on canvas 5NkFW28vZUUij43xzxHg5a (no board draws the section yet).

## ADDED Requirements

### Requirement: A catalogue declares its staff access (REQ-CSA-001)

The catalog schema SHALL have `staffAccess` with `restricted` (boolean, default false), `readGroups` and `editGroups` (lists of Nextcloud group ids). An unrestricted catalogue SHALL behave as today.

#### Scenario: Existing catalogues are untouched
<!-- @e2e exclude Upgrade path; proven by CatalogAccessServiceTest::testAnUnrestrictedCatalogueKeepsTodaysRules. -->
- **GIVEN** an install with three catalogues and no staff access set
- **WHEN** the upgrade runs
- **THEN** the schemas' authorization grants every signed-in user as before

### Requirement: Staff access becomes OpenRegister rules on the covered schemas (REQ-CSA-002)

When a catalogue's `staffAccess`, `schemas` or `filters` change, or a catalogue is deleted, the system SHALL recompute the `authorization` of every schema involved from all catalogues that cover it: a restricted catalogue SHALL contribute rules for its read groups to `read` and for its edit groups to `read`, `create`, `update` and `delete`, each with the catalogue's filters as `match`; an unrestricted catalogue SHALL contribute the signed-in rule, narrowed to its filters when it has filters. The public read rules SHALL be kept. OpenCatalogi SHALL NOT check staff access itself.

#### Scenario: Only the Woo team edits Woo requests
<!-- @e2e exclude Enforcement is OpenRegister's; proven by CatalogAccessEnforcementTest::testAUserOutsideTheEditGroupsIsRefused against OpenRegister. -->
- **GIVEN** a catalogue "Woo requests" over schema publication with filter `wooCategory: infocat014`, restricted, edit group `woo-team`
- **WHEN** a signed-in user outside `woo-team` updates a draft publication with that category
- **THEN** OpenRegister refuses the update
- **AND** a member of `woo-team` may update it

#### Scenario: Two catalogues on one schema
<!-- @e2e exclude Rule computation; proven by CatalogAccessServiceTest::testTwoCataloguesOnOneSchemaAreUnited. -->
- **GIVEN** two restricted catalogues over the same schema with different filters and different groups
- **WHEN** either is saved
- **THEN** the schema's rules hold both groups, each with its own catalogue's filters

### Requirement: The administrator sets staff access on the catalogue edit page (REQ-CSA-003)

The catalogue edit page SHALL offer a Staff access section with the restricted switch and the two group pickers, and SHALL list the other catalogues that cover the same publications. The setup wizard's private choice SHALL set `staffAccess.restricted` with the groups it asks for.

#### Scenario: Restricting a catalogue
- **GIVEN** an administrator on the edit page of catalogue "Council papers"
- **WHEN** they switch on restricted access, pick the group Griffie for edit and save
- **THEN** the catalogue page shows "Staff access: restricted to Griffie"

#### Scenario: The wizard's private choice is real
<!-- @e2e exclude Setup path; proven by SetupControllerTest::testThePrivateChoiceRestrictsTheCatalogue. -->
- **GIVEN** the setup wizard with the scope "private, granted groups only" and group Griffie
- **WHEN** the first catalogue is created
- **THEN** it is unlisted and restricted to Griffie
