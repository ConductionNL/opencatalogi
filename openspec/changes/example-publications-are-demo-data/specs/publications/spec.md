## ADDED Requirements

### Requirement: Example publications are demo data, installed only on request (REQ-EPD-001)

The publication register imported on install MUST NOT carry example publications, documents or depublications. Example rows MUST live in the demo dataset, which is installed only when an administrator asks for it (ADR-111).

#### Scenario: A municipality's public search after a fresh install
- GIVEN a fresh install where nobody installed the demo data
- WHEN a visitor searches the public publications for "Voorbeeld"
- THEN no example publication or document is found
- @e2e exclude pinned by `PublicationStateServiceTest::testTheInstalledRegisterCarriesNoExamplePublications`; the bundled register is not a browser surface

#### Scenario: The demo data shows Publish again
- GIVEN an administrator installed the demo data
- WHEN they open the withdrawn example publication
- THEN it is withdrawn, has its depublication, and offers Publish again
- @e2e exclude pinned by `PublicationStateServiceTest::testTheDemoDataCarriesAWithdrawnPublicationAndItsDepublication`
