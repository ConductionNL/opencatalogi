---
status: proposed
---

# Publication detail for the portal

## ADDED Requirements

### Requirement: An officer orders a publication's documents and the public list keeps that order (REQ-PDP-001)

The publication schema SHALL carry `attachmentOrder`, an array of file ids as strings. The publication page SHALL show the attachments with Move up and Move down buttons on each row, reachable and operable by keyboard, each with an accessible name naming the document, announcing the new position through a live region. Saving SHALL write `attachmentOrder`. `PublicationService::attachments()` and `FederationController::publicationAttachments()` SHALL answer the files in `attachmentOrder` order, then files not in it by upload time, each with `position` from 1. An id in `attachmentOrder` that is no longer attached SHALL be skipped.

#### Scenario: An officer orders the documents by keyboard and the portal gets that order

- **GIVEN** a public publication with the documents "Bijlage 2", "Besluit" and "Bijlage 1" in upload order
- **WHEN** the officer moves "Besluit" to the top and "Bijlage 1" above "Bijlage 2" with the keyboard and saves
- **THEN** `GET /api/{catalogSlug}/{id}/attachments` answers "Besluit", "Bijlage 1", "Bijlage 2" with positions 1, 2 and 3

#### Scenario: A new upload goes last
<!-- @e2e exclude Service ordering rule; proven by AttachmentOrderTest::testAFileNotInTheOrderComesLast, which fails on today's code because no order is read. -->

- **GIVEN** a publication with `attachmentOrder` for two files and a third file uploaded later
- **WHEN** the attachments are listed
- **THEN** the third file has position 3

### Requirement: A document has a stable id and its own metadata (REQ-PDP-002)

Each attachment in the public and federation lists SHALL carry `documentId` (the Nextcloud file id as a string, unchanged by a rename), `title`, `format`, `size`, `published`, `downloadUrl`, `documentsoort` and `creationDate` when stored, and `metadataUrl` and `diwooUrl`. `GET /api/{catalogSlug}/{id}/documents/{documentId}` (public, CORS) SHALL answer that document's metadata with `publication: {id, title, url}`. `GET /api/{catalogSlug}/{id}/documents/{documentId}/diwoo` SHALL answer its `diwoo:Document` as XML, rendered by `SitemapService::mapDiwooDocument()`. Both SHALL answer 404 for a document that is not a public attachment of a publicly readable publication. This is the contract `portaliq/publication-detail-page-complete` reads.

#### Scenario: A document's own metadata links back
<!-- @e2e exclude Public API contract for the portal; proven by PublicDocumentControllerTest::testADocumentAnswersItsMetadataAndItsPublication, which fails on today's code because the route does not exist. -->

- **GIVEN** a public publication with a public file
- **WHEN** a client asks `GET /api/{catalogSlug}/{id}/documents/{documentId}` and its `/diwoo` twin
- **THEN** the first answers the file's metadata with the publication's id, title and URL
- **AND** the second answers one `diwoo:Document`

#### Scenario: A withdrawn file is not served
<!-- @e2e exclude Fail-closed path; proven by PublicDocumentControllerTest::testAWithdrawnFileIs404. -->

- **GIVEN** a public publication whose file was withdrawn
- **WHEN** a client asks for that document
- **THEN** the answer is 404

### Requirement: The publication carries its comment period's state (REQ-PDP-003)

The public publication response and `GET /api/federation/publications/{id}` SHALL carry `commentPeriod`, the `CommentPeriodService::publicView()` of the publication's comment period (state `upcoming`, `open` or `closed`, the dates, the remedy, and the reaction form URL only while open), when the publication has one. When `state()` refuses unreadable dates, the response SHALL carry `commentPeriodError: "unreadable-dates"` and no `commentPeriod`. This is the contract `portaliq/publication-detail-page-complete` reads for 7.20.

#### Scenario: An open period on the federation endpoint
<!-- @e2e exclude Public API contract for the portal; proven by FederationControllerTest::testThePublicationCarriesItsOpenCommentPeriod, which fails on today's code because the endpoint carries no period. -->

- **GIVEN** a public publication with a comment period open until next Friday
- **WHEN** a client asks the federation endpoint for it
- **THEN** `commentPeriod.state` is `open` with next Friday as end and the reaction form URL

### Requirement: A catalogue may show that documents were withheld and why (REQ-PDP-004)

The catalogue schema SHALL gain `showWithheld` (boolean, default `false`). `wooAssessment` SHALL gain `titlePublic` (boolean, default `false`). When the publication's catalogue has `showWithheld` true, the public publication response SHALL carry `withheld: [{position, grounds, title?}]` for every `wooAssessment` with `assessment` `niet_openbaar` in the batch whose `wooPublication.publication` is this publication, with `grounds` the stored `weigeringsgronden` article references, and `title` only when `titlePublic` is true. Nothing else of the assessment SHALL be returned. When `showWithheld` is false or the catalogue cannot be resolved, `withheld` SHALL be absent. This is the contract `portaliq/publication-error-reports-and-withheld-notices` reads for 6.16.

#### Scenario: A catalogue that opted in shows the withheld documents
<!-- @e2e exclude Public API contract; proven by WithheldDocumentsTest::testAnOptedInCatalogueListsWithheldDocumentsWithGroundsOnly. -->

- **GIVEN** a catalogue with `showWithheld` true and a publication from a batch with one document withheld on art. 5.1 lid 2 sub e, its title not public
- **WHEN** a reader fetches the publication
- **THEN** `withheld` holds one entry with its position and the ground, and no title

#### Scenario: By default nothing is shown
<!-- @e2e exclude Fail-closed default; proven by WithheldDocumentsTest::testWithoutOptInThereIsNoWithheldKey, which fails if the key is ever present by default. -->

- **GIVEN** a catalogue that did not opt in
- **WHEN** a reader fetches the same publication
- **THEN** the response has no `withheld` key

### Requirement: A catalogue may take error reports from anyone, throttled and moderated (REQ-PDP-005)

The catalogue schema SHALL gain `acceptErrorReports` (boolean, default `false`) and `errorReportModerators` (Nextcloud group ids). A schema `errorReport` SHALL hold `publication`, `message` (at most 2,000 characters), `contact` (optional e-mail), `status` (`new`, `handled`, `dismissed`) and `createdAt`, with no public read rule. `POST /api/publications/{id}/error-reports` (public, `#[AnonRateLimit(limit: 5, period: 3600)]`) SHALL store a report and answer `{reference}` when the publication is publicly readable and its catalogue has `acceptErrorReports` true with at least one moderator group; otherwise it SHALL answer 404. It SHALL store no IP address. The `errorReport` schema SHALL declare an `x-openregister-notifications` rule on `created` to the moderator groups. The officer side SHALL list reports per catalogue with their status. This is the contract `portaliq/publication-error-reports-and-withheld-notices` posts to for 6.15.

#### Scenario: A report reaches the moderators
<!-- @e2e exclude Public write endpoint and a notification; proven by ErrorReportControllerTest::testAReportIsStoredAndTheModeratorsAreNotified, which fails on today's code because the route does not exist. -->

- **GIVEN** a catalogue that accepts error reports with moderator group `woo-redactie`
- **WHEN** an anonymous visitor posts "De bijlage hoort bij een ander besluit" on a public publication
- **THEN** an `errorReport` with status `new` is stored and the answer carries its reference
- **AND** members of `woo-redactie` get a notification

#### Scenario: Not opted in, or too many
<!-- @e2e exclude Fail-closed path; proven by ErrorReportControllerTest::testWithoutOptInTheRouteIs404 and ErrorReportControllerTest::testTheSixthReportInAnHourIsThrottled. -->

- **GIVEN** a catalogue that did not opt in
- **WHEN** a visitor posts a report
- **THEN** the answer is 404 and nothing is stored
