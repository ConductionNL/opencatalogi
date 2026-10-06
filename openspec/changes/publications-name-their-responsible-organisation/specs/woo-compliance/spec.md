---
status: proposed
---

# Woo compliance

## ADDED Requirements

### Requirement: A publication names its responsible organisation apart from its publisher (REQ-PRO-001)

The publication schema SHALL carry `responsibleOrganization`, `{"format": "uuid", "$ref": "nc-organisation"}`, optional. `SitemapService::mapDiwooDocument()` SHALL emit `diwoo:verantwoordelijke` with the TOOI organisation URI of `responsibleOrganization`, or of `organization` when it is empty, and SHALL keep emitting `diwoo:publisher` from `organization`. When the responsible organisation has no TOOI identifier, the element SHALL be omitted and `collectDiwooViolations()` SHALL report `verantwoordelijke` with the reason. When the DiWoo XSD fixture marks the element mandatory, `DiwooCompletenessListener` SHALL treat a missing TOOI identifier on the responsible organisation as a missing mandatory field. The publication form SHALL offer Responsible organisation with the same organisation picker as Publisher.

#### Scenario: A shared service centre publishes for a municipality
<!-- @e2e exclude Sitemap XML contract; proven by SitemapServiceTest::testVerantwoordelijkeComesFromTheResponsibleOrganisation, which fails on today's code because verantwoordelijke is the publisher. -->

- **GIVEN** a public Woo publication whose `organization` is "Servicecentrum Drechtsteden" and whose `responsibleOrganization` is "Gemeente Dordrecht", both with TOOI identifiers
- **WHEN** its category sitemap page is rendered
- **THEN** `diwoo:publisher` names the service centre's TOOI URI and `diwoo:verantwoordelijke` names the municipality's

#### Scenario: Without a separate responsible organisation
<!-- @e2e exclude Sitemap XML contract; proven by SitemapServiceTest::testWithoutAResponsibleOrganisationThePublisherIsResponsible. -->

- **GIVEN** a publication with no `responsibleOrganization`
- **WHEN** its DiWoo record is rendered
- **THEN** `diwoo:verantwoordelijke` is the publisher's TOOI URI

#### Scenario: A responsible organisation without a TOOI identifier
<!-- @e2e exclude Fail-closed path; proven by SitemapServiceTest::testAResponsibleOrganisationWithoutTooiIsOmittedAndReported. -->

- **GIVEN** a responsible organisation with no TOOI identifier
- **WHEN** the record is rendered and validated
- **THEN** no `diwoo:verantwoordelijke` literal is emitted and the validator reports it

#### Scenario: An editor sets the responsible organisation

- **GIVEN** a draft publication
- **WHEN** an editor picks "Gemeente Dordrecht" as Responsible organisation and saves
- **THEN** the publication page shows the publisher and the responsible organisation separately

### Requirement: The publisher's RSIN travels with every stored document (REQ-PRO-002)

`OCA\OpenCatalogi\Service\Publication\PublisherIdentity::of(array $publication): array` SHALL resolve `{rsin, name, tooi}` from the `nc-organisation` object `organization` refers to, and SHALL answer `rsin` only when it is nine digits that pass the 11-proef. When a document is attached to a public publication (`BatchPublicationWriter::attach()` and the attachment upload path), OpenCatalogi SHALL write `rsin`, `publisherName` and `publisherTooi` to the file's metadata through OpenRegister's file metadata service (route `files#updateMetadata`), and SHALL store `publisherRsin` on the publication. When `EmbeddedTitleWriter` (from `published-file-carries-its-facts`) exists, it SHALL also write the RSIN into the file's embedded metadata (XMP `dc:publisher` with the name and a property carrying the RSIN). When the RSIN is missing or invalid, nothing SHALL be stamped, the file metadata SHALL hold `rsinStatus: missing` with the reason, and the DiWoo validator SHALL list the document.

#### Scenario: The RSIN travels with the file
<!-- @e2e exclude File metadata contract; proven by PublisherIdentityTest::testTheRsinIsWrittenToTheFileMetadataOnAttach, which fails on today's code because nothing resolves or writes an RSIN. -->

- **GIVEN** an organisation with RSIN 002220647 and a public publication of that organisation
- **WHEN** a document is attached
- **THEN** the file's metadata holds `rsin` 002220647, the organisation's name and its TOOI identifier
- **AND** the publication holds `publisherRsin` 002220647

#### Scenario: An RSIN that fails the 11-proef
<!-- @e2e exclude Fail-closed path; proven by PublisherIdentityTest::testAnInvalidRsinIsNotStamped. -->

- **GIVEN** an organisation whose RSIN is 123456789, which fails the 11-proef
- **WHEN** a document is attached
- **THEN** no RSIN is written, the file metadata holds `rsinStatus: missing` with the reason, and the validator lists it
