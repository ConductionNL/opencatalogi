# service-catalogue-admin-screens Specification

## Purpose
Administrators manage the service catalogue in the app. They list the entries with cost, duration, form and availability, see the entries that need repair, resynchronise a case type property by property, and keep knowledge articles in a list with a page each. Created by archiving change service-catalogue-admin-screens (2026-10-30); holds SCA-001 to SCA-003.

## Requirements

### Requirement: An administrator lists the catalogue entries with their availability (SCA-001)

The app SHALL have a "Products and services" page listing every service
catalogue entry with its cost, its duration, the form it starts and whether it
is available. Entries that are not available MUST also be listed apart, each
with the reason it carries, so an administrator sees what to repair.

@e2e exclude The list reads the public catalogue endpoint, whose availability is covered by ServiceCatalogueControllerTest; the page is manifest configuration validated by check:manifest.

#### Scenario: An entry whose case type ended

- **GIVEN** the entry "Afvalcontainer voor inwoners" bound to case type "Afvalcontainer", which is no longer published
- **WHEN** an administrator opens Products and services
- **THEN** the entry is listed as not available
- **AND** "Needs repair" lists it with its reason

### Requirement: A case type is resynchronised property by property (SCA-002)

From an entry, an administrator SHALL open the resync of the case type the
entry starts. The dialog MUST show every property that differs from the
source with the local and the source value, MUST let the administrator keep or
take each, and MUST post only the taken properties as `accepted`. A case type
that was never imported, or a source that cannot be reached, MUST be said in
the dialog and apply nothing.

@e2e exclude The source is an external catalogue a test instance does not reach; CaseTypeResyncDialog.spec.js drives the preview and the apply against the endpoint contract.

#### Scenario: Taking the new duration and keeping the description

- **GIVEN** the case type "Evenementenvergunning" whose source changed the duration from 8 to 10 weeks and the description
- **WHEN** the administrator takes the duration, keeps the description and confirms
- **THEN** one request posts `accepted: ["duration"]`

#### Scenario: An entry without a case type

- **GIVEN** an entry whose form binding names no case type
- **WHEN** the administrator chooses Resync
- **THEN** the dialog says the entry starts no case type and offers nothing to apply

### Requirement: Knowledge articles have a list and a page (SCA-003)

The app SHALL list knowledge articles with their title, whether they are a
draft and when they changed, and SHALL let an editor add one with a title, a
catalog and a body, saved as a draft.

@e2e exclude Manifest configuration over the library's index and detail pages; check:manifest validates it.

#### Scenario: A new draft article

- **WHEN** an editor adds "Parkeren in de blauwe zone" as a draft
- **THEN** the list shows it as a draft
