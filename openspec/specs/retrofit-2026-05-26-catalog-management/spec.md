---
status: done
---

# retrofit-2026-05-26-catalog-management Specification

## Purpose
Provides the create, edit, view, and detail surfaces for catalogs and their shared entities. A modal lets users create or edit a catalog with organization, register, and schema options and validates required input before saving, a view modal resolves a catalog's register and schema by id, and detail pages load a catalog or entity by route id to present its metadata, configuration, and widget definitions and link through to publications.

> @e2e exclude Whole-spec reverse-engineered catalog modal/detail component-logic capability — every scenario asserts component internals (save blocked until required input is valid, register resolved by id in the view modal, catalog/entity loaded from the route id). These are deterministic prop/route-param assertions verified by vitest over the catalog modal and detail-page components; the user-facing catalog create/edit/detail surfaces are already real-UI covered under catalogs::create-a-new-catalog, ::edit-an-existing-catalog and ::open-a-catalog-detail-page-by-route-id.

## Requirements
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

### Requirement: Catalogue overview (REQ-CATM-005)

The catalogues page MUST list every catalogue the user can see in one
overview. Opening a catalogue MUST lead to its detail page, and from there to
the list of its publications, scoped to the catalogue's registers and schemas.

#### Scenario: open a catalogue's publications from the overview

- **GIVEN** two catalogues
- **WHEN** the user opens the catalogues page and selects one catalogue
- **THEN** its detail page MUST open
- **AND** opening its publications MUST list only publications in that catalogue's registers and schemas

### Requirement: A catalogue has its own title, summary and web address (REQ-CATM-006)

Creating a catalogue MUST ask for a title, a summary and a slug. The slug MUST
become the catalogue's public web address: its publications are served on
`GET /api/{catalogSlug}`. A catalogue MUST hold only the publication schemas
chosen for it: a publication in a schema outside the catalogue MUST be refused
for that catalogue.

#### Scenario: the slug is the public address

- **GIVEN** a new catalogue with title "Woo-besluiten", a summary and slug `woo-besluiten`
- **WHEN** it is saved
- **THEN** its publications MUST be served on `GET /apps/opencatalogi/api/woo-besluiten`

#### Scenario: only the chosen schemas belong to the catalogue

- **GIVEN** a catalogue that holds only the schema "besluit"
- **WHEN** a publication of schema "agenda" is requested through that catalogue
- **THEN** it MUST NOT be served as part of the catalogue
