---
status: proposed
---

# Open a remote publication in the app

## ADDED Requirements

### Requirement: A federated result opens inside the app (REQ-FOR-001)

Selecting a publication of another organisation in the federated search SHALL open a page inside this app with the publication's title, summary, description, organisation, publication date and source instance. It SHALL NOT open a new browser tab.

#### Scenario: A reader opens a neighbouring municipality's decision

- **GIVEN** a federated search result from another instance
- **WHEN** a signed-in user selects it on the Search page
- **THEN** the remote publication's page opens in the app with its metadata and the name of the source organisation
- **AND** no new tab opens

### Requirement: The attachments of a remote publication are listed from the source (REQ-FOR-002)

`GET /api/federation/publications/{id}/attachments` SHALL return the local attachments for a local publication and the source instance's attachments for a remote one, read from the source at the time of the request. Each remote attachment SHALL keep the source's download URL. The call to the source SHALL pass the outbound URL guard and SHALL ask the source not to aggregate further.

#### Scenario: Attachments of a remote publication

- **GIVEN** a publication held by another instance with two attachments
- **WHEN** a reader calls `GET /api/federation/publications/{id}/attachments` on this instance
- **THEN** the answer lists both attachments with the other instance's download URLs

#### Scenario: A directory entry that points at a private address

- **GIVEN** a listing whose directory URL resolves to a private address
- **WHEN** the attachments of a publication from that listing are requested
- **THEN** no request is sent to that address and the answer says the source could not be read

### Requirement: The page links correctly to the source (REQ-FOR-003)

The remote publication's page SHALL offer an "Open at the source" link that uses the source app's page route for the publication when its catalogue is known, and the source app's root otherwise. The link SHALL NOT use a hash route.

#### Scenario: A reader wants the original page

- **GIVEN** a remote publication whose catalogue slug is `besluiten` on the source
- **WHEN** the reader follows Open at the source
- **THEN** the browser opens `/index.php/apps/opencatalogi/publications/besluiten/{id}` on the source instance
