---
status: proposed
---

# Woo request intake

## ADDED Requirements

### Requirement: OpenCatalogi offers no officer screen for Woo requests (REQ-WRS-001)

`src/manifest.json` SHALL contain no page, menu entry or dashboard widget whose register or schema resolves to the `wooRequest` schema, and no page whose route or id names a Woo request (`woo-request`, `wooRequest`, `requests` under `/woo`, or `verzoek`). The Woo request screens are dossiq's: `DqWooVerzoeken` for the list and `DqZaak` for one request. The parity rows `wr-request-record` and `wr-search-sources` in `openspec/parity/capabilities.json` SHALL carry `screen` `{board: null, reason: ...}` naming those dossiq boards, and SHALL name no OpenCatalogi board.

#### Scenario: The manifest has no Woo request page
<!-- @e2e exclude Manifest contract; proven by tests/Unit/AppInfo/NoWooRequestScreenTest.php::testTheManifestHasNoWooRequestPage, which reads src/manifest.json and fails when a page, menu item or widget points at the wooRequest schema or a request route. -->

- **WHEN** the shipped `src/manifest.json` is read
- **THEN** no page, menu item or widget points at the `wooRequest` schema
- **AND** no page route or id names a Woo request

#### Scenario: The request rows point at dossiq
<!-- @e2e exclude Matrix data; proven by NoWooRequestScreenTest::testTheRequestRowsPointAtDossiq, which reads openspec/parity/capabilities.json. -->

- **WHEN** the rows `wr-request-record` and `wr-search-sources` are read
- **THEN** each has `screen.board` null and a reason naming `DqWooVerzoeken` or `DqZaak`

### Requirement: A disclosure batch names the dossiq Woo case it answers (REQ-WRS-002)

A disclosure batch SHALL name the Woo request it answers through `caseReference`, the reference of the dossiq case. The batch form SHALL ask for that reference and SHALL offer no picker of OpenCatalogi `wooRequest` objects. A batch with a `caseReference` and no OpenCatalogi request SHALL work as before (REQ-WRI-007). The route `POST /api/woo/requests/{requestId}/batch` is removed with the other request routes by `woo-request-intake-hands-over-to-dossiq` (REQ-WHD-006), not here.

#### Scenario: A batch is created for a dossiq case
<!-- @e2e exclude Existing batch behaviour; proven by WooServiceTest, which creates a batch with caseReference WOO-2026-009 and no request. The board OcWooBatchAanmaken draws the form without a request picker. -->

- **GIVEN** a dossiq Woo case with reference WOO-2026-037
- **WHEN** an officer creates a batch with `caseReference` WOO-2026-037
- **THEN** the batch is stored with that reference
- **AND** no OpenCatalogi request is read or written

### Requirement: dossiq publishes the Woo decision and its documents as one publication (REQ-WRS-003)

The `publication` schema SHALL keep declaring `caseReference`, `publicationKind`, `wooCategory` and `period`, which dossiq's `WooPublicationService::buildPayload()` sends. A publication dossiq creates SHALL carry `publicationKind` `woo-besluit` and the dossiq case reference in `caseReference`. Its disclosable documents arrive as files on the publication, not as separate objects. OpenCatalogi SHALL publish it like any other publication. It SHALL tell the source where it went and when it is public through `publication-tells-its-source`. It SHALL record withheld documents only through `WithheldDocuments::record()` (`woo-decision-shows-what-was-withheld`).

#### Scenario: The publication keeps every key dossiq sends
<!-- @e2e exclude Schema contract for a cross-app write; proven by tests/Unit/Settings/WooJourneyRegisterTest.php::testADecisionPublicationIsAccepted, which validates dossiq's payload shape against the shipped publication schema, and by dossiq's WooPublicationService tests on the sending side. -->

- **GIVEN** the shipped register fragments
- **WHEN** dossiq publishes a Woo decision for case 2026-0082 in category infocat014
- **THEN** the publication schema accepts `caseReference` 2026-0082, `publicationKind` `woo-besluit` and `wooCategory` infocat014
- **AND** none of those keys is undeclared

### Requirement: The requester follows a Woo request in portaliq, not in OpenCatalogi (REQ-WRS-004)

OpenCatalogi SHALL show a requester no status, term or document of a Woo request. The requester follows and works on the request in portaliq Mijn zaken, on the dossiq case (`portaliq/woo-dossier-in-my-cases`). "Mijn dossiers" SHALL keep the dossiq action that starts a Woo request from a dossier, and SHALL keep listing the publication once dossiq has published it.

#### Scenario: A dossier starts a request and shows the result
<!-- @e2e exclude Citizen journey across three apps; the dossier side is covered by citizen-collections e2e, the case side by portaliq's woo-dossier-in-my-cases e2e. -->

- **GIVEN** a resident with a dossier in Mijn dossiers
- **WHEN** she starts a Woo request from it
- **THEN** dossiq opens the case and Mijn zaken lists it
- **AND** once dossiq publishes the decision, the dossier lists the publication
