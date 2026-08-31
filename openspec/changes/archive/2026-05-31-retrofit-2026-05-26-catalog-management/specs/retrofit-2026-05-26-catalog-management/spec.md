# Capability: catalog-management

## ADDED Requirements

### Requirement: Catalog create/edit modal (REQ-CATM-001)
The catalog modal MUST let a user create or edit a catalog, offering organization, register, and schema options, validating required input before save, and closing on completion.

#### Scenario: Save blocked on invalid input
- **GIVEN** a catalog form with a missing required field
- **WHEN** input validation runs
- **THEN** the save MUST be blocked until the form is valid

### Requirement: Catalog view modal (REQ-CATM-002)
The catalog view modal MUST display the active catalog, resolve its register and schema by id for display, and offer edit, delete, view, and navigate-to-organization actions.

#### Scenario: Register resolved by id
- **GIVEN** a catalog referencing a register by id
- **WHEN** the view modal renders
- **THEN** the register name MUST be resolved from that id for display

### Requirement: Catalog detail page (REQ-CATM-003)
The catalog detail page MUST load the catalog by its route id, present metadata and configuration items and widget definitions, offer edit and back navigation, and link through to the catalog's publications.

#### Scenario: Catalog loaded by route id
- **GIVEN** a catalog id in the route
- **WHEN** the detail page mounts
- **THEN** the matching catalog MUST be loaded and its metadata items rendered

### Requirement: Shared entity detail page (REQ-CATM-004)
The shared entity detail page MUST load an entity by its route id, present its metadata items and widget definitions, and offer edit, delete, and back navigation.

#### Scenario: Entity loaded by route id
- **GIVEN** an entity id in the route
- **WHEN** the detail page mounts
- **THEN** the matching entity MUST be loaded and its metadata items rendered
