---
status: done
---

# retrofit-2026-05-26-directory-federation Specification

## Purpose
Provides the directory federation side bar for managing and synchronizing publication types across federated sources. Lets users create, copy, delete, and toggle the enablement of publication types, synchronize the directory and individual types with their source, and view listings through add-directory and view-directory modals with formatted dates and action labels.

> @e2e exclude Whole-spec reverse-engineered directory side-bar component-logic capability — every scenario asserts component/state internals (publication-type enablement toggle updates state, directory synchronization triggered, listing displayed in a modal). The synchronization itself is a backend federation call (covered by federation Newman/PHPUnit), and the toggle/listing rendering are deterministic component-unit assertions verified by vitest; the directory surface is additionally exercised by the dashboard real-UI tests (dashboard::add-an-external-directory / ::edit-a-listing / ::delete-a-listing).

## Requirements
### Requirement: Publication-type management (REQ-DIR-001)
The directory side bar MUST allow creating, copying, and deleting publication types, MUST toggle a publication type's enablement, MUST resolve a publication type's id, and MUST reflect the set of checked/switched publication types.

#### Scenario: Publication type toggled
- **GIVEN** a listed publication type
- **WHEN** its enablement toggle is changed
- **THEN** the publication type's enabled state MUST be updated

### Requirement: Directory synchronization (REQ-DIR-002)
The directory side bar MUST synchronize the directory and individual publication types with their source, and MUST resolve the active listing item and open external links.

#### Scenario: Directory synchronized
- **GIVEN** the directory side bar
- **WHEN** the synchronize-directory action runs
- **THEN** the directory listings MUST be refreshed from their source

### Requirement: Directory listing modals (REQ-DIR-003)
The add-directory and view-directory modals MUST display a listing (with formatted dates and action labels) and MUST close on completion.

#### Scenario: Listing displayed
- **GIVEN** a listing passed to the view-directory modal
- **WHEN** the modal renders
- **THEN** the listing's fields MUST be displayed with formatted dates

### Requirement: Add a directory by its address (REQ-DIR-004)

An administrator MUST be able to add another organisation's directory by its
address from the directory page. Adding MUST synchronise the directory at once
and pull in its catalogues as listings.

#### Scenario: a new directory brings its catalogues

- **GIVEN** a remote installation that offers two catalogues
- **WHEN** an administrator adds its directory address
- **THEN** two listings from that installation MUST appear on the directory page

### Requirement: Scheduled and on-demand synchronisation (REQ-DIR-005)

A background job MUST synchronise every known directory on the interval set in
the admin settings. The admin settings MUST also offer a button that
synchronises all directories now.

#### Scenario: sync now refreshes the listings

- **GIVEN** a remote directory that added a catalogue since the last sync
- **WHEN** an administrator chooses to synchronise directories now
- **THEN** the new catalogue MUST appear as a listing

### Requirement: Edit or remove a listing (REQ-DIR-006)

An administrator MUST be able to edit a listing, including its integration
level (search, sync or none), and remove it. Only listings with integration
level "search" MUST take part in federated search.

#### Scenario: a listing leaves federated search

- **GIVEN** a listing with integration level "search"
- **WHEN** an administrator sets it to "none"
- **THEN** federated search MUST no longer query that listing
