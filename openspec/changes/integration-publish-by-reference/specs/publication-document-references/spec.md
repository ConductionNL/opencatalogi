---
status: proposed
---

# Publication document references

## ADDED Requirements

### Requirement: An editor adds a document by its public URL (REQ-PBR-001)

An editor SHALL be able to add a document reference to a publication with a public https URL, a title and a format, and the same publication window a file has. The app SHALL NOT download or store the referenced file. A URL that points at a loopback, private or link-local address SHALL be refused with a reason.

#### Scenario: An editor points at the decision in the document system

- **GIVEN** an editor on a publication's page
- **WHEN** they add a reference to `https://documenten.voorbeeldgemeente.nl/besluit-2026-001.pdf` with format pdf
- **THEN** the reference appears in the references list of the publication
- **AND** no file is added to the publication's attachments

#### Scenario: A private address

- **GIVEN** an editor adding a reference
- **WHEN** they enter `https://10.0.0.12/besluit.pdf`
- **THEN** the save is refused and the form says the address is not public

### Requirement: A reference is published like a file, pointing at its source (REQ-PBR-002)

Inside its publication window, a reference SHALL appear as a document in the DiWoo category sitemap, as a distribution in the DCAT feed and in the public attachments list of the publication, each with the source URL as its location. Outside its window it SHALL appear in none of them.

#### Scenario: The Woo-index reads a referenced document

- **GIVEN** a published publication with one reference whose window is open
- **WHEN** the harvester reads the category sitemap page
- **THEN** a document for the reference is listed with the source URL as its `loc`

#### Scenario: A reader asks for the attachments

- **GIVEN** the same publication
- **WHEN** a reader calls `GET /api/{catalogSlug}/{id}/attachments`
- **THEN** the reference is in the list with `kind` reference and the source URL

### Requirement: Broken references are shown before readers find them (REQ-PBR-003)

The app SHALL check once a day whether each reference inside its window still answers, and record when it checked and what it got. The references list and the DiWoo validator report SHALL show references that did not answer.

#### Scenario: The document system moved a file

- **GIVEN** a reference whose URL now answers 404
- **WHEN** the daily check has run
- **THEN** the reference shows as unreachable with the status 404 and the check time on the publication page

#### Scenario: A server that refuses HEAD

- **GIVEN** a source system that answers HEAD with 405 and a ranged GET with 206
- **WHEN** the daily check runs
- **THEN** the reference is recorded as reachable
