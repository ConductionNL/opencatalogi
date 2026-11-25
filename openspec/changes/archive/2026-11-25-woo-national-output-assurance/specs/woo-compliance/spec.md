---
status: proposed
---

# Woo compliance

## ADDED Requirements

### Requirement: A change to a delivered publication is delivered again (REQ-WNO-001)

`PlooiDeliveryListener::handle()` SHALL also queue a `PlooiDelivery` job when a publication that is public before and after the update has `plooiStatus` `delivered` and the update changed a field `SitemapService::mapDiwooDocument()` emits or the set of its public files. The job SHALL send the update, and a withdrawal for each document that is no longer public, through `NationalIndexService::deliverToPlooi()`. A publication that stops being public with `plooiStatus` `delivered` SHALL queue a withdrawal of the whole record. A change to a field DiWoo does not carry SHALL queue nothing.

#### Scenario: A corrected title reaches PLOOI without publishing again
<!-- @e2e exclude Server-side delivery; proven by PlooiDeliveryListenerTest::testAChangedTitleOnADeliveredPublicationQueuesAnUpdate, built on the real ObjectUpdatedEvent, which fails on today's code because the listener returns for a publication that was already public. -->

- **GIVEN** a public publication delivered to PLOOI
- **WHEN** an editor corrects its title
- **THEN** a PLOOI delivery job is queued for it, and after it runs `plooiDeliveredAt` is the new moment

#### Scenario: A removed annex is withdrawn from PLOOI
<!-- @e2e exclude Server-side delivery; proven by PlooiDeliveryServiceTest::testADocumentNoLongerPublicIsWithdrawn. -->

- **GIVEN** a delivered publication with two documents
- **WHEN** one document is withdrawn
- **THEN** the next delivery carries a withdrawal for that document

#### Scenario: An internal note changes nothing
<!-- @e2e exclude Server-side delivery; proven by PlooiDeliveryListenerTest::testAChangeOutsideDiwooQueuesNothing. -->

- **GIVEN** a delivered publication
- **WHEN** only `retentionNote` changes
- **THEN** no delivery is queued

### Requirement: The DiWoo output is validated as it is generated, against the current standard (REQ-WNO-002)

`SitemapService` SHALL validate each `diwoo:Document` it renders against the DiWoo metadata XSD shipped in `lib/Settings/diwoo/` (the same files as the test fixture of `diwoo-metadata-on-the-publication`), with `DOMDocument::schemaValidate()` on the document's fragment wrapped as the XSD requires. An invalid document SHALL be left out of the page, logged with the XSD errors, and listed by `collectDiwooViolations()` with reason `xsd-invalid`. A daily `TimedJob` `DiwooStandardCheck` SHALL read the current DiWoo metadata version from the standard's authority (the version index under `https://standaarden.overheid.nl/diwoo/metadata/`) and store it; when it is newer than `StandardsVersionService::DIWOO_VERSION` the readiness check SHALL fail with "DiWoo <new> is current; this instance emits <ours>". When the authority cannot be read it SHALL report "could not verify".

#### Scenario: An invalid document is held back at render time
<!-- @e2e exclude Sitemap XML contract; proven by SitemapServiceTest::testAnXsdInvalidDocumentIsLeftOutAndReported, which fails on today's code because nothing validates at render time. -->

- **GIVEN** a category page with two documents, one of whose rendered `diwoo:Document` violates the XSD
- **WHEN** the page is requested
- **THEN** the page lists only the valid document and is itself valid against the XSD
- **AND** the validator lists the other with `xsd-invalid` and the XSD message

#### Scenario: A newer DiWoo version is noticed
<!-- @e2e exclude Background job against a recorded authority response; proven by DiwooStandardCheckTest::testANewerVersionFailsReadiness and ::testAnUnreachableAuthorityIsNotCurrent. -->

- **GIVEN** the authority lists DiWoo 0.9.9 and the instance emits 0.9.8
- **WHEN** the job runs
- **THEN** the readiness check fails naming both versions and an incident is raised

### Requirement: The sitemaps are reconciled against the register daily (REQ-WNO-003)

A daily `TimedJob` `DiwooSitemapReconciliation` SHALL, per catalogue with `hasWooSitemap` and per category, count the public Woo publications and their public documents through the anonymous public read, collect the document locations from every sitemap page of that category, and store `{catalog, category, expected, listed, missing: [loc], extra: [loc], checkedAt, complete: bool}`. The readiness report SHALL show it. A missing or extra entry SHALL raise an incident. When a read fails, `complete` SHALL be false with the reason.

#### Scenario: A document missing from the sitemap is reported
<!-- @e2e exclude Background job; proven by DiwooSitemapReconciliationTest::testADocumentMissingFromTheSitemapIsReported, which fails on today's code because no reconciliation exists. -->

- **GIVEN** a category with three public documents in the register, one of which the sitemap does not list
- **WHEN** the reconciliation runs
- **THEN** the result lists that document under `missing` and an incident is raised

#### Scenario: A read failure is not a clean bill
<!-- @e2e exclude Fail-closed path; proven by DiwooSitemapReconciliationTest::testAFailedReadIsIncomplete. -->

- **GIVEN** a sitemap page that cannot be built
- **WHEN** the reconciliation runs
- **THEN** `complete` is false with the reason

### Requirement: A pipeline failure reaches a named group (REQ-WNO-004)

A schema `wooPipelineIncident` (`kind`, `subject`, `message`, `at`, `resolvedAt`) SHALL declare an `x-openregister-notifications` rule on `created` with channels `nc-notification` and `email` to the members of the group in app config `woo_incident_group`. `OCA\OpenCatalogi\Service\Woo\PipelineIncidents::raise(string $kind, string $subject, string $message): void` SHALL create one, coalescing an identical open incident within 24 hours. It SHALL be called on: a failing readiness check (`WooReadinessService`), a refused sitemap or category, a failed `publishBatch()`, a failed national hand-over (`NationalIndexService::deliver()`, `deliverToPlooi()`, `PlooiDeliveryService::deliver()`), a reconciliation gap and a stale DiWoo version. When `woo_incident_group` is empty, the readiness check SHALL fail with "nobody receives pipeline failures". The Woo settings SHALL offer the group setting and list open incidents.

#### Scenario: A failed PLOOI delivery reaches the Woo team

- **GIVEN** `woo_incident_group` set to `woo-team` and a PLOOI source that answers an error
- **WHEN** a publication is delivered
- **THEN** members of `woo-team` get a Nextcloud notification and an e-mail naming the publication and the reason
- **AND** the Woo settings list the open incident

#### Scenario: Every failure path raises
<!-- @e2e exclude Wiring contract; proven by PipelineIncidentsWiringTest, one case per named failure path driven through its real caller, each failing on today's code because nothing raises. -->

- **WHEN** each named failure path fails
- **THEN** one `wooPipelineIncident` is created with its kind

## MODIFIED Requirements

### Requirement: A publication that turns public is delivered to PLOOI when the catalogue asks for it (REQ-WND-003)

When a publication in a catalogue with `plooiDelivery` on becomes public, the app SHALL post its DiWoo metadata and document links to the PLOOI source and store `plooiStatus`, `plooiDeliveredAt` and `plooiIdentifier` on the publication. When a delivered publication changes in a DiWoo field or in its public documents, or stops being public, the app SHALL deliver the update or the withdrawal the same way (REQ-WNO-001). A failed delivery SHALL store `plooiStatus` as failed with the reason, SHALL raise a pipeline incident (REQ-WNO-004), and SHALL NOT block publishing.

#### Scenario: A publication is published

- **GIVEN** a catalogue with `plooiDelivery` on and a PLOOI source set
- **WHEN** an editor publishes a publication in it
- **THEN** the publication shows the PLOOI delivery status and the identifier returned by the platform

#### Scenario: PLOOI refuses

- **GIVEN** the PLOOI source answers with an error
- **WHEN** an editor publishes a publication
- **THEN** the publication is public
- **AND** its `plooiStatus` is failed with the platform's reason
