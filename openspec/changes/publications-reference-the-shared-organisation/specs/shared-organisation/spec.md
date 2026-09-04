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
