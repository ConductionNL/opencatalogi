---
status: proposed
---

# Publication tells its source

## ADDED Requirements

### Requirement: A publication carries its public page and its officer page (REQ-PTS-001)

The publication schema SHALL carry two read-only string properties, `publicUrl` and `officerUrl`. A listener on OpenRegister's `ObjectCreatingEvent` for the publication schema, `OCA\OpenCatalogi\Listener\PublicationLinksListener`, SHALL set them through `setModifiedData()`: `publicUrl` from `PublicationLinker::url($uuid)`, and `officerUrl` as the absolute URL of the route `opencatalogi.ui.publicationsPage` with the slug of the first catalogue whose `schemas` include the publication's schema, resolved through `CatalogiService`. When no catalogue lists the schema, `officerUrl` SHALL be the absolute URL of `opencatalogi.ui.publicationsIndex` for the default catalogue setting, or empty when there is none. When `PublicationLinker` can build no public link, `publicUrl` SHALL be empty. A value a caller sends for either property SHALL be ignored.

`PublicationsController::show()` and the publication create path of OpenCatalogi SHALL return both values computed fresh. When the app config `publication_url_template` or `publication_url_catalog` changes, a queued job SHALL rewrite both properties on every publication.

#### Scenario: A handoff gets both pages back
<!-- @e2e exclude Cross-app API contract; proven by PublicationLinksListenerTest::testACreateThroughOpenRegisterReturnsBothPages, built on the real ObjectCreatingEvent, which fails on today's code because no listener sets the properties. -->

- **GIVEN** portaliq is installed and a catalogue `woo` lists the publication schema
- **WHEN** a source system creates a publication through `POST /apps/openregister/api/objects/{register}/{schema}`
- **THEN** the response carries `publicUrl` ending in the portaliq publication page for its uuid
- **AND** `officerUrl` is the absolute URL `/apps/opencatalogi/publications/woo/{uuid}`

#### Scenario: The show response carries both pages
<!-- @e2e exclude API contract; proven by PublicationsControllerTest::testShowCarriesThePublicAndOfficerPages. -->

- **GIVEN** a stored publication
- **WHEN** a caller asks `GET /api/{catalogSlug}/{id}`
- **THEN** the answer carries `publicUrl` and `officerUrl`

#### Scenario: No public link can be built
<!-- @e2e exclude Fail-closed path; proven by PublicationLinksListenerTest::testNoPublicLinkLeavesPublicUrlEmpty. -->

- **GIVEN** no URL template, no portaliq, and an empty `publication_url_catalog`
- **WHEN** a publication is created
- **THEN** `publicUrl` is empty and is not the object's API URL

### Requirement: A publication records whether it is complete and public (REQ-PTS-002)

The publication schema SHALL carry `completeness` (enum `incomplete`, `complete`, default `incomplete`, read-only to callers), `completeAt` (date-time, read-only) and `expectedDocuments` (optional integer, at least 1). `OCA\OpenCatalogi\Service\Publication\CompletenessService::evaluate(array $publication): string` SHALL answer `complete` only when all of these hold: `PublicationStateService::stateOf()` answers `public`; every file OpenRegister's `FileMapper::getFilesForObject()` returns for the publication has a non-empty `share_token` and none is withdrawn; and the number of attached files is at least `expectedDocuments` when that is set. Otherwise it SHALL answer `incomplete`. When a lookup throws, `evaluate()` SHALL throw and the caller SHALL leave the stored value unchanged.

A listener on `ObjectUpdatingEvent` for the publication schema SHALL write the evaluated value, and `completeAt` when it becomes `complete`, through `setModifiedData()`. The file publish and withdraw paths (`EventService::publishObjectAttachments()`, `PublicationStateController::withdrawFile()`) SHALL re-evaluate after they act. A background job `OCA\OpenCatalogi\BackgroundJob\CompletenessSweep`, every 15 minutes, SHALL re-evaluate publications whose `publicationDate` or `depublicationDate` passed since its previous run and save those whose value changed.

#### Scenario: One of three documents is public
<!-- @e2e exclude Service rule; proven by CompletenessServiceTest::testFewerDocumentsThanExpectedIsIncomplete. -->

- **GIVEN** a public publication with `expectedDocuments` 3 and one attached file with a share
- **WHEN** its completeness is evaluated
- **THEN** it is `incomplete`

#### Scenario: A file without a public share
<!-- @e2e exclude Service rule; proven by CompletenessServiceTest::testAFileWithoutAShareIsIncomplete. -->

- **GIVEN** a public publication with two files of which one has no share token
- **WHEN** its completeness is evaluated
- **THEN** it is `incomplete`

#### Scenario: A lookup fails
<!-- @e2e exclude Fail-closed path; proven by CompletenessListenerTest::testALookupFailureLeavesTheStoredValueAsItWas. -->

- **GIVEN** a publication stored as `incomplete` and a file lookup that throws
- **WHEN** it is saved
- **THEN** it is still `incomplete` and a warning is logged

#### Scenario: A scheduled publication completes when its date passes
<!-- @e2e exclude Background job; proven by CompletenessSweepTest::testAPublicationWhoseDatePassedIsMarkedComplete, which fails on today's code because no such job exists. -->

- **GIVEN** a publication with every file shared and a publication date one minute ago, stored as `incomplete`
- **WHEN** the sweep runs
- **THEN** it is stored as `complete` with `completeAt` set

#### Scenario: A withdrawn file makes it incomplete again
<!-- @e2e exclude Service path; proven by CompletenessListenerTest::testWithdrawingAFileMovesBackToIncomplete. -->

- **GIVEN** a `complete` publication
- **WHEN** an editor withdraws one of its files
- **THEN** it is stored as `incomplete`

### Requirement: The source system is told through an OpenRegister webhook (REQ-PTS-003)

OpenCatalogi SHALL NOT send the notification itself. The move of `completeness` from `incomplete` to `complete` SHALL be an OpenRegister object update, so that a webhook subscribed on `ObjectUpdatedEvent` with the filters `{"oldObject.completeness": "incomplete", "newObject.completeness": "complete", "newObject.@self.owner": "<the source's user>"}` receives exactly one CloudEvent per completion. The documentation under `docs/` SHALL give that subscription as a copyable recipe, including the filter to narrow it to one register and schema.

#### Scenario: The source system is told once, when the last document goes public
<!-- @e2e exclude Webhook delivery is server to server; proven by CompletenessWebhookContractTest::testTheFilterMatchesOnlyTheCompletingUpdate, which runs OpenRegister's WebhookService filter over the payloads of three successive updates, and by the live check in tasks 4.2. -->

- **GIVEN** a source system subscribed with the recipe's filters, and a public publication it created with `expectedDocuments` 2 and one shared file
- **WHEN** the second file is attached and shared, and then the title is edited
- **THEN** the subscriber receives one CloudEvent, for the update that made it complete
- **AND** the edit after it delivers nothing
