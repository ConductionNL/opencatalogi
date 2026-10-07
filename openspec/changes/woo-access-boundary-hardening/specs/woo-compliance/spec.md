---
status: proposed
---

# Woo compliance

## ADDED Requirements

### Requirement: Reads of non-public Woo records are logged (REQ-WAB-001)

The schemas `wooAssessment`, `wooBatch`, `depublication`, `wooRequest` and `publication` SHALL declare `x-openregister-processing: {logReads: true, activity: "opencatalogi-woo-records"}`, with their versions bumped. OpenCatalogi SHALL seed the processing activity `opencatalogi-woo-records` (purpose: carrying out the Woo; legal basis: legal obligation, Woo) through its register import. A read of one of these objects through OpenRegister's `ObjectService` SHALL then write a processing log entry attributed to that activity. The Woo readiness check SHALL report how many entries in the last 30 days fell back to OpenRegister's unclassified activity for these schemas.

#### Scenario: An officer reads an assessment and the read is logged
<!-- @e2e exclude Server-side log; proven by WooReadLoggingTest::testAReadOfAnAssessmentWritesAnAttributedEntry, which runs against OpenRegister's ProcessingLogService when it exists and fails on today's schemas because they do not opt in. -->

- **GIVEN** a `wooAssessment` and an officer who may read it
- **WHEN** the officer opens it
- **THEN** OpenRegister's processing log holds an entry for that object, the officer and the activity `opencatalogi-woo-records`

### Requirement: A test proves the public side cannot reach a non-public record (REQ-WAB-002)

`tests/Unit/Boundary/PublicBoundaryTest.php` SHALL read every route in `appinfo/routes.php` whose controller method carries `#[PublicPage]`, call each through its controller with an anonymous session against a fixture holding one object of each non-public kind (a draft publication, a scheduled one, a withdrawn one, an archived one, an unlisted one when that property exists, a `wooAssessment`, a `wooBatch`, a `depublication`, a `wooRequest`, and a withdrawn file on a public publication), with every id-taking route called with each fixture id, and SHALL fail when any response body contains a fixture's id or title. `tests/e2e/public-boundary.spec.ts` SHALL do the same over HTTP anonymously against the CI seed, which SHALL contain those kinds. Both SHALL also assert that every non-public OpenCatalogi route answers 401 or 403 to an anonymous caller.

#### Scenario: No public route returns a non-public record
<!-- @e2e exclude Proven by the boundary suite itself: PublicBoundaryTest::testNoPublicRouteReturnsANonPublicRecord and tests/e2e/public-boundary.spec.ts. -->

- **GIVEN** the fixture with every non-public kind
- **WHEN** every public route is called anonymously with every fixture id
- **THEN** no response contains a fixture's id or title
- **AND** every non-public route answers 401 or 403

#### Scenario: A new public route is covered by default
<!-- @e2e exclude Suite contract; proven by PublicBoundaryTest::testTheRouteListIsReadFromTheRouteTable, which fails when the suite's route count differs from the PublicPage count in appinfo/routes.php. -->

- **GIVEN** a developer adds a `#[PublicPage]` route
- **WHEN** the suite runs
- **THEN** that route is called without anyone adding it to a list

### Requirement: The officer side and acceptance installs stay out of search indexes (REQ-WAB-003)

`RobotsController::index()` SHALL add `Disallow` lines for OpenCatalogi's officer paths (`/apps/opencatalogi/` and `/index.php/apps/opencatalogi/` except the `api/` paths it allows today, and the OpenCatalogi settings paths). A middleware SHALL add `X-Robots-Tag: noindex, nofollow` to every OpenCatalogi response whose controller method is not `#[PublicPage]`. The admin setting `search_indexing` (`allowed` by default, or `blocked`) SHALL, when `blocked`, add `X-Robots-Tag: noindex, nofollow` to every OpenCatalogi response and make robots.txt `Disallow: /`, without closing the public side.

#### Scenario: Officer paths are not indexed
<!-- @e2e exclude Response headers and robots text; proven by RobotsControllerTest::testOfficerPathsAreDisallowed and NoIndexMiddlewareTest::testANonPublicResponseCarriesNoindex, which fail on today's code. -->

- **GIVEN** a default install
- **WHEN** robots.txt and an officer page are requested
- **THEN** robots.txt disallows the officer paths and still allows the API
- **AND** the officer page answers `X-Robots-Tag: noindex, nofollow`

#### Scenario: An acceptance install is not indexed
<!-- @e2e exclude Response headers; proven by NoIndexMiddlewareTest::testBlockedIndexingMarksEveryResponse. -->

- **GIVEN** `search_indexing` set to `blocked`
- **WHEN** a public sitemap page and robots.txt are requested
- **THEN** the sitemap answers with `noindex` and robots.txt disallows everything
- **AND** the sitemap is still served

### Requirement: Capabilities are rights granted per group (REQ-WAB-004)

A listener on OpenRegister's `PermissionsDeclaringEvent` SHALL declare the verbs `openInspection`, `openCommentPeriod`, `depublish`, `announceNationally`, `decideRetention`, `processWooBatch`, `publishWooBatch` and `requestWooRegistration` for app `opencatalogi` at the `schema` level, each with a description. Grants SHALL live in the `catalog` schema's `authorization` block, a verb mapped to a list of Nextcloud group ids. A listener on `CustomScopeEvaluatingEvent` SHALL decide these verbs: allow when the user is an administrator or in a granted group, deny otherwise, and deny when the groups or the block cannot be read. `OCA\OpenCatalogi\Service\CapabilityRights::may(string $verb, ?string $userId): bool` SHALL call `PermissionHandler::hasPermission()` with the catalog schema and the verb. These controller methods SHALL replace `#[AuthorizedAdminSetting]` with `#[NoAdminRequired]` and a `may()` check that answers 403 naming the verb: `InspectionController::open` (`openInspection`), `CommentPeriodController::open` (`openCommentPeriod`), `DepublicationController::depublish` and `acknowledgeWithdrawal` (`depublish`), `PublicationDisclosureController::announce` (`announceNationally`), `RetentionController::decide` (`decideRetention`), `WooController::createBatch`, `getBatch`, `updateAssessment`, `markReadyForReview` and `inventarislijst` (`processWooBatch`), `WooController::publishBatch` (`publishWooBatch`), `WooRegistrationController::request` and `confirm` (`requestWooRegistration`). The OpenCatalogi admin settings SHALL show a table of the eight rights with the groups granted each, editable by an administrator.

#### Scenario: A group may open inspection periods and nothing else

- **GIVEN** the group `ter-inzage` granted `openInspection` only, and a user in it who is not an administrator
- **WHEN** the user opens an inspection period on a publication
- **THEN** the period opens
- **AND** when the same user tries to publish a Woo batch the answer is 403 naming `publishWooBatch`

#### Scenario: The grant list cannot be read
<!-- @e2e exclude Fail-closed path; proven by CapabilityScopeListenerTest::testAnUnreadableGrantListDenies, built on the real CustomScopeEvaluatingEvent. -->

- **GIVEN** a catalog schema whose authorization block cannot be read
- **WHEN** a non-administrator asks for `openInspection`
- **THEN** the listener denies

#### Scenario: Every gated method checks its verb
<!-- @e2e exclude Wiring contract; proven by CapabilityRightsWiringTest::testEveryListedMethodChecksItsVerbAndHasNoAdminAttribute, which reads the controllers by reflection and fails on today's code. -->

- **WHEN** the controller methods listed above are read
- **THEN** none carries `#[AuthorizedAdminSetting]` and each calls `may()` with its verb before acting
