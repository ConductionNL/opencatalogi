---
status: proposed
---

# Publications for signed-in readers

## ADDED Requirements

### Requirement: A publication says who can read it (REQ-PSR-001)

The publication schema SHALL carry `audience` (`public`, `signedIn`, default `public`) and `minimumAssurance` (`substantial`, `high`, default `substantial`). A migration MUST set `audience: public` on every existing publication before the public read rule changes. The Visibility card of the publication page SHALL show "Who can read it", editable in the publication editor.

#### Scenario: An editor limits a publication to signed-in readers
<!-- @e2e exclude Visibility field not yet on the canvas board; proven by tests/e2e/publications-for-signed-in-readers.spec.ts once task 3.1 lands. -->

- **GIVEN** a draft publication without a Woo category
- **WHEN** an editor sets "Who can read it" to readers who log in, level Substantial, and saves
- **THEN** the Visibility card reads "Readers who log in with DigiD or eHerkenning, level Substantial"

#### Scenario: Existing publications stay public
<!-- @e2e exclude Migration; proven by AudienceMigrationTest::testEveryExistingPublicationGetsAudiencePublic. -->

- **GIVEN** an instance with public publications that have no `audience`
- **WHEN** the migration runs
- **THEN** each has `audience: public` and is still returned by the public API

### Requirement: Every public surface leaves a sign-in publication out (REQ-PSR-002)

The `group: public` read rule MUST match only `audience: public`, and `isObjectPublic()` MUST apply the same check. The public API, search, the Woo sitemaps, DCAT, OOAPI and federation MUST NOT return a `signedIn` publication to a reader without the trusted read of REQ-PSR-003. An anonymous request for one MUST answer 404.

#### Scenario: An anonymous reader cannot tell it exists
<!-- @e2e exclude Fail-closed path; proven by SignedInPublicationExclusionTest, one case per surface. -->

- **GIVEN** a published publication with `audience: signedIn`
- **WHEN** an anonymous reader asks for it by id, searches for its title, or reads the sitemap and the DCAT feed
- **THEN** the direct read answers 404 and none of the others lists it

### Requirement: A trusted portal reads it for a reader at the required level (REQ-PSR-003)

A consumer key SHALL carry `actForSignedInReaders`. A request with such a key for the publication's catalogue and `X-Reader-Assurance` at or above `minimumAssurance` SHALL be served the publication, with `Cache-Control: private, no-store`, and MUST write an audit entry with the key, the publication, the level and the time. Any other request MUST answer 404.

#### Scenario: A resident logged in with DigiD reads it through the portal
<!-- @e2e exclude Server-to-server contract; proven by SignedInReadControllerTest::testAKeyThatMayActForReadersAtTheRightLevelIsServed. -->

- **GIVEN** a `signedIn` publication with minimum `substantial` and a portal key with `actForSignedInReaders`
- **WHEN** the portal asks for it with `X-Reader-Assurance: substantial`
- **THEN** the publication is returned and an audit entry records the read

#### Scenario: A lower level is refused
<!-- @e2e exclude Fail-closed path; proven by SignedInReadControllerTest::testALowerLevelAnswers404. -->

- **GIVEN** a `signedIn` publication with minimum `high`
- **WHEN** the portal asks for it with `X-Reader-Assurance: substantial`
- **THEN** the answer is 404

#### Scenario: A key without the permission is refused
<!-- @e2e exclude Fail-closed path; proven by SignedInReadControllerTest::testAKeyWithoutActForSignedInReadersAnswers404. -->

- **GIVEN** a consumer key for the catalogue without `actForSignedInReaders`
- **WHEN** it asks for a `signedIn` publication with `X-Reader-Assurance: high`
- **THEN** the answer is 404

### Requirement: A Woo publication stays public (REQ-PSR-004)

A save with `audience: signedIn` and a `wooCategory` MUST be refused with "A Woo publication is public by law. Remove the Woo category or make it readable for everyone."

#### Scenario: A Woo decision cannot be limited to signed-in readers
<!-- @e2e exclude Validation rule; proven by PublicationValidationServiceTest::testASignedInPublicationWithAWooCategoryIsRefused. -->

- **GIVEN** a publication with `wooCategory` `infocat008`
- **WHEN** an editor sets `audience` to `signedIn` and saves
- **THEN** the save is refused with that message
