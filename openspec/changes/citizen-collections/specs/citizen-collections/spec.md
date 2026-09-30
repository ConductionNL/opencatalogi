---
status: proposed
---

# Citizen collections

## ADDED Requirements

### Requirement: A dossier belongs to one resident and answers 404 to everyone else (REQ-CCOL-001)

Implements hydra `woo-citizen-journey`: A resident's dossier MUST be owned by the resident and readable by nobody else unless shared.

A `collection` SHALL carry the resident's `subjectRef` as `owner`, taken from the verified `X-Portal-Subject` assertion and never from the request body. Every portal endpoint SHALL compare the collection's `owner` with the assertion's subject and SHALL answer 404 on a mismatch, the same answer as for an id that does not exist. A request without a valid assertion SHALL answer 401.

#### Scenario: A resident opens their own dossier

- **GIVEN** a signed-in resident who owns a collection with two items
- **WHEN** portaliq forwards `viewDossier` for that collection
- **THEN** the answer holds both items with their notes

#### Scenario: A resident guesses another resident's dossier id

- **GIVEN** a collection owned by someone else
- **WHEN** a resident's assertion asks for it
- **THEN** the answer is 404, the same as for an id that does not exist

#### Scenario: The body names another owner

- **GIVEN** a resident adding to a new dossier
- **WHEN** the body carries `owner` set to another subject
- **THEN** the stored `owner` is the assertion's subject

### Requirement: A resident adds a public publication or one of its documents to a dossier (REQ-CCOL-002)

Implements hydra `woo-citizen-journey` journey step J3.1.

The `addToDossier` action SHALL append an item `{ id, publication, attachment, note, addedAt, addedBy: resident, title }` to the named collection, or to a new collection with the given `title` when no collection is named. The publication SHALL be public at that moment; an unknown or non-public publication SHALL answer 404. Adding the same publication and attachment twice SHALL NOT create a second item.

#### Scenario: Add to a new dossier

- **GIVEN** a signed-in resident and a public publication
- **WHEN** they add it with the title "Windpark" and no collection
- **THEN** a collection "Windpark" owned by them holds one item for that publication, `addedBy: resident`

#### Scenario: A publication that is not public yet

- **GIVEN** a publication with a publication date tomorrow
- **WHEN** a resident tries to add it
- **THEN** the answer is 404 and nothing is written

### Requirement: A resident removes items and writes notes (REQ-CCOL-003)

Implements hydra `woo-citizen-journey` journey steps J3.2 and J3.3.

`removeFromDossier` SHALL remove one item by its id. `noteOnDossier` SHALL set the note of one item, or the dossier's `description` when no item is named. An item id that is not in the dossier SHALL answer 404.

#### Scenario: Remove an item

- **GIVEN** a dossier with two items
- **WHEN** the owner removes the first
- **THEN** the dossier holds only the second

### Requirement: The owner sees a depublished item as no longer public (REQ-CCOL-004)

Implements hydra `woo-citizen-journey`: A shared dossier MUST show only what is public at the moment it is read (the owner half).

The owner's view SHALL return every item with `public: true` or `public: false`, judged at the moment of reading. An item whose publication is no longer public SHALL stay in the owner's view, with its stored title.

#### Scenario: A depublished item stays in the owner's view

- **GIVEN** a dossier with an item whose publication was depublished yesterday
- **WHEN** the owner opens the dossier
- **THEN** the item is listed with `public: false` and its title

### Requirement: A shared dossier shows only what is public now, and a revoked link answers 404 (REQ-CCOL-005)

Implements hydra `woo-citizen-journey`: A shared dossier MUST show only what is public at the moment it is read.

`shareDossier` SHALL set `share` to `{ token, createdAt }` with at least 128 random bits in the token, and answer the absolute share URL as `link`. `GET /api/collections/shared/{token}` SHALL be readable without sign-in and SHALL return the title, description and only the items whose publication is public at that moment, with their notes. It SHALL NOT return `owner`, `share` or `sourceOf`. `unshareDossier` SHALL set `share` to null, after which the old token SHALL answer 404.

#### Scenario: A depublished item disappears from the shared view

- **GIVEN** a shared collection with one public item and one item whose publication was depublished yesterday
- **WHEN** an anonymous visitor opens the share link
- **THEN** only the public item is listed
- **AND** the answer has no `owner`

#### Scenario: The owner revokes the link

- **GIVEN** a shared collection
- **WHEN** the owner revokes the share
- **THEN** the old link answers 404

### Requirement: opencatalogi contributes dossiers to the portal for citizen and client (REQ-CCOL-006)

Implements hydra `woo-citizen-journey` design C1 and C7 (the Mijn dossiers page).

`OCA\OpenCatalogi\Portal\PortalContributionProvider` SHALL answer a manifest for the audiences `citizen` and `client`, and null for any other. The manifest SHALL declare the `myDossiers` collection scoped by `owner`, the actions `createDossier`, `addToDossier`, `viewDossier`, `removeFromDossier`, `noteOnDossier`, `shareDossier`, `unshareDossier` and `deleteDossier`, and a page `dossiers` labelled "Mijn dossiers". The `myDossiers` entry SHALL declare `itemList` with the provider method `dossierItems`, which returns the dossier's items as `{ id, title, url, note, public, addedAt }`, and `removeAction: removeFromDossier`, whose item field is `itemId`.

#### Scenario: A DigiD resident in the client audience

- **GIVEN** a subject with audience `client`
- **WHEN** portaliq asks for opencatalogi's contribution
- **THEN** the manifest offers `addToDossier` and the page "Mijn dossiers"

#### Scenario: portaliq lists a dossier's items

- **GIVEN** a dossier with one public and one depublished item
- **WHEN** portaliq calls `dossierItems` with the dossier id
- **THEN** both items come back, the second with `public: false`

#### Scenario: A supplier

- **GIVEN** a subject with audience `supplier`
- **WHEN** portaliq asks for the contribution
- **THEN** the answer is null

### Requirement: Removing a portal account deletes that resident's dossiers (REQ-CCOL-007)

Implements hydra `woo-citizen-journey`: Removing a portal account MUST remove the resident's dossiers and saved searches.

When the `portalAccount` object of register `portaliq` changes to `status: removed`, opencatalogi SHALL delete every `collection` and every `savedSearch` whose `owner` is that account's `subjectRef`. A failure SHALL be logged and SHALL NOT fail the account's save.

#### Scenario: A resident removes their account

- **GIVEN** a resident with two dossiers and one saved search
- **WHEN** portaliq marks their account removed
- **THEN** none of those objects exists afterwards
- **AND** another resident's dossier is untouched
