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

