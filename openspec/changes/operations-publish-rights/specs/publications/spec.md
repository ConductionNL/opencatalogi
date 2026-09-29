---
status: proposed
---

# Publish rights

## ADDED Requirements

### Requirement: Only publishers change whether a publication is public (REQ-OPR-001)

Changing `publicationDate` or `depublicationDate` on a publication SHALL be allowed only for members of the group `opencatalogi-publishers` and for administrators. Editing any other property SHALL keep the rights it has today.

#### Scenario: An editor tries to publish

- **GIVEN** an editor who is not in `opencatalogi-publishers`
- **WHEN** the editor saves a publication with a new `publicationDate`
- **THEN** the save is refused with an error that names the property
- **AND** the publication is not public

#### Scenario: An editor edits a title

- **GIVEN** the same editor
- **WHEN** the editor saves a publication with a changed title and an unchanged `publicationDate`
- **THEN** the save succeeds

### Requirement: The screen offers publish actions only to publishers (REQ-OPR-002)

`GET /api/publications/{id}/visibility` SHALL include `canPublish`, true for a publisher or an administrator. The Publish now, Withdraw and Publish again actions SHALL be hidden when it is false.

#### Scenario: An editor opens a draft

- **GIVEN** an editor outside the publishers group on a draft publication
- **WHEN** the editor opens the Actions menu
- **THEN** no Publish now action is listed

### Requirement: The publish endpoints refuse a caller without the right (REQ-OPR-003)

`POST /api/publications/{id}/publish` and `POST /api/publications/{id}/withdraw` SHALL answer 403 for a caller who is neither a publisher nor an administrator, with a message that names the group.

#### Scenario: A direct call by an editor

- **GIVEN** an editor outside the group
- **WHEN** the editor calls `POST /api/publications/{id}/withdraw`
- **THEN** the response is 403 and names `opencatalogi-publishers`

### Requirement: The group exists and the administrator can see it (REQ-OPR-004)

The app SHALL create the group `opencatalogi-publishers` when it is missing, on install and on repair, without adding members. The Woo section of the admin settings SHALL show the group and its member count.

#### Scenario: A repair on an existing instance

- **GIVEN** an instance without the group
- **WHEN** the repair step runs twice
- **THEN** the group exists once and has no members
- **AND** the settings section shows 0 members
