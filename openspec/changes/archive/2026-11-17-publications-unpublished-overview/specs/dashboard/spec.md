---
status: proposed
---

# Unpublished publications and documents on the dashboard

## MODIFIED Requirements

### Requirement: Unpublished-content dashboard widgets (DSH-011)

The system SHALL provide two Nextcloud dashboard widgets, `UnpublishedPublicationsWidget` and `UnpublishedAttachmentsWidget` (titled Unpublished documents), that list what the signed-in user can read and is not yet public. A publication is not yet published when it has no publication date or one in the future and has not been withdrawn. A document is not yet published when its file on the publication has no published time or one in the future. Both widgets SHALL read `GET /api/dashboard/unpublished`, which applies the user's read rights, lists the newest first with a total, and bounds the search. A row SHALL open the publication in the app.

#### Scenario: unpublished widgets render their counts

- **GIVEN** an editor who can read three publications with no publication date
- **WHEN** the Nextcloud dashboard renders the two opencatalogi widgets
- **THEN** the Unpublished publications widget shows a total of three, read from `GET /api/dashboard/unpublished`
- **AND** neither widget fetches a full object collection to count client-side

#### Scenario: A scheduled publication shows up

- **GIVEN** an editor who can read a publication with a publication date next week
- **WHEN** the editor opens the Nextcloud dashboard
- **THEN** the Unpublished publications widget lists it
- **AND** clicking the row opens the publication page

#### Scenario: A document not yet published

- **GIVEN** a public publication with one file whose published time is empty
- **WHEN** the editor opens the Nextcloud dashboard
- **THEN** the Unpublished documents widget lists that file with its publication's title

#### Scenario: A withdrawn publication is not listed

- **GIVEN** a publication whose depublication date has passed
- **WHEN** the editor opens the Nextcloud dashboard
- **THEN** it is not in the Unpublished publications widget

#### Scenario: Another catalogue's draft stays hidden

- **GIVEN** a draft publication in a catalogue the user cannot read
- **WHEN** the user opens the Nextcloud dashboard
- **THEN** neither widget lists it and neither total counts it
