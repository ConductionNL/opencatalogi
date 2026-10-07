# Shared organisation

## ADDED Requirements

### Requirement: The organisation comes from OpenRegister (REQ-SHO-101)

A publication's and a catalog's organisation reference MUST resolve against
OpenRegister's shared organisation projection, not against a schema this app
ships. Two apps declaring `organization` is a slug collision, and the slug is
global per organisation.

The `organization_source`, `organization_register` and `organization_schema`
configuration keys MUST be resolved from OpenRegister. They MUST keep their
names, so the object store resolves the type exactly as before and no frontend
change is required.

When OpenRegister or its projection is absent the keys MUST be left unset rather
than failing the import. A missing picker option is a smaller failure than a
broken install.

#### Scenario: A publication's organisation resolves to the shared record

- **GIVEN** a publication whose `organization` holds an organisation uuid
- **WHEN** it is read with that property extended
- **THEN** the organisation's identity facet is inlined from OpenRegister.

#### Scenario: The app ships no organisation schema

- **WHEN** the register descriptor is imported
- **THEN** no `organization` schema is created by this app.

#### Scenario: A missing projection leaves the keys unset

- **GIVEN** an instance whose OpenRegister has no `nc-organisation`
- **WHEN** the configuration is resolved
- **THEN** the import succeeds and the organisation keys are absent.

#### Scenario: The app ships no organisation seed

- **GIVEN** stackiq is installed with its own `organization` schema, in either install order
- **WHEN** opencatalogi's register descriptors and demo data are imported
- **THEN** no seed object names schema `organization`
- **AND** no seed publication or document carries an `organization` slug, because the property holds an `nc-organisation` uuid
- **AND** every seed the descriptors ship is saved

## ADDED Requirements

Amendment 2026-10-05, Woo capability programme, row 12.34.

### Requirement: The organisation a publication names is the one its rights follow (REQ-SHO-102)

The publication schema SHALL declare `x-openregister-organisation: {fromProperty: "organization"}` (OpenRegister REQ-OOP-001), with its version bumped. After import the annotation SHALL be present on the stored schema. Every save of a publication SHALL then leave `organization` and `@self.organisation` equal, as OpenRegister enforces. The publication form SHALL offer in its organisation picker only the organisations the user is a member of, or all of them for an administrator. The upgrade notes SHALL tell the operator to run `occ openregister:organisation:reconcile --schema publication` as a dry run, then with `--apply`.

#### Scenario: Naming the unit is what scopes the rights

- **GIVEN** an officer who is a member of "Gemeente Voorbeeld" and "Omgevingsdienst Voorbeeld", with "Gemeente Voorbeeld" active, and a colleague who is only a member of "Gemeente Voorbeeld"
- **WHEN** the officer creates a draft publication with Organisation set to "Omgevingsdienst Voorbeeld"
- **THEN** the publication's `@self.organisation` is "Omgevingsdienst Voorbeeld"
- **AND** the colleague does not see the draft in the publications list

#### Scenario: The annotation survives the import
<!-- @e2e exclude Import contract; proven by PublicationOrganisationAnnotationTest::testTheFromPropertyAnnotationSurvivesTheImport, which imports the descriptor through OpenRegister's import when it exists and reads the stored schema, and fails on today's descriptor because the annotation is absent. -->

- **GIVEN** the register descriptor with the annotation
- **WHEN** it is imported
- **THEN** the stored publication schema carries `x-openregister-organisation.fromProperty` `organization`

#### Scenario: A non-member cannot publish for another unit
<!-- @e2e exclude Refusal by OpenRegister's save path; proven by PublicationOrganisationAnnotationTest::testANonMemberIsRefused, which saves through ObjectService when OpenRegister's change is present. -->

- **GIVEN** an officer who is not a member of "Omgevingsdienst Voorbeeld"
- **WHEN** they save a publication naming it
- **THEN** the save is refused and nothing is stored
