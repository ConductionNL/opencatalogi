# Woo compliance

## Purpose

The Woo-index sitemap reads four fields from integriq's editable mapping `woo-index-publication` and falls back to its own reads. Design: `../../design.md`. Issue opencatalogi#1672.

## ADDED Requirements

### Requirement: Woo-index fields come from the editable mapping when integriq answers (REQ-WIM-001)

For each publication in a Woo sitemap, the system SHALL dispatch integriq's `MappingExecutionRequestedEvent` with slug `woo-index-publication`, source app `opencatalogi` and the publication's fields, and SHALL use each of `publisher`, `officieleTitel`, `informatiecategorie` and `soortHandeling` from the output when it is a non-empty string. The event SHALL be dispatched at most once per publication per request.

#### Scenario: An administrator changes where the official title comes from
<!-- @e2e exclude Server-side XML; proven by SitemapServiceTest::testAMappedOfficialTitleIsInTheSitemap with a listener on the real event class. -->
- **GIVEN** integriq is installed and `woo-index-publication` maps `officieleTitel` from `summary`
- **WHEN** a Woo sitemap is generated for a publication with summary "Besluit windpark Oostpolder"
- **THEN** the document's official title is "Besluit windpark Oostpolder"

### Requirement: The sitemap never depends on the mapping answering (REQ-WIM-002)

When the event is not handled, is refused (`not-found`, `not-allowed`, `failed`) or a listener throws, the system SHALL use its built-in reads for all four fields, and the sitemap SHALL be generated. A field the output leaves empty SHALL use the built-in read for that field only.

#### Scenario: Without integriq nothing changes
<!-- @e2e exclude Fallback path; proven by SitemapServiceTest::testWithoutIntegriqTheSitemapIsUnchanged. -->
- **GIVEN** integriq is not installed
- **WHEN** a Woo sitemap is generated
- **THEN** its XML is the same as before this change

#### Scenario: A refused mapping falls back
<!-- @e2e exclude Fallback path; proven by WooIndexFieldMapperTest::testANotAllowedRefusalFallsBackAndIsKept. -->
- **GIVEN** the mapping's `callableBy` no longer lists `opencatalogi`
- **WHEN** a Woo sitemap is generated
- **THEN** all four fields use the built-in reads and the refusal `not-allowed` is kept for the readiness report

### Requirement: Mapped values are checked like built-in ones (REQ-WIM-003)

A mapped `informatiecategorie` SHALL resolve to a member of the TOOI informatiecategorieen list, a mapped `publisher` SHALL be a TOOI organisation URI and a mapped `soortHandeling` SHALL be a DiWoo value; a value that fails SHALL be recorded as a violation on the same axis as a built-in value.

#### Scenario: A wrong edit shows up in the readiness check
<!-- @e2e exclude Validation path; proven by WooIndexFieldMapperTest::testAMappedCategoryOutsideTheListIsAViolation. -->
- **GIVEN** the mapping returns `informatiecategorie` "vergaderstukken" (not a URI)
- **WHEN** the readiness self-check runs
- **THEN** it reports an `informatiecategorie` violation for that publication

### Requirement: The administrator sees where the fields come from (REQ-WIM-004)

The Woo readiness report SHALL state whether the Woo-index fields came from integriq's mapping or the built-in reads, which fields came from the mapping, and the refusal code and reason when there was one. The settings page's readiness card SHALL show that in one line.

#### Scenario: The settings page names the source
- **GIVEN** integriq answers the mapping for all four fields
- **WHEN** the administrator runs the readiness check on the settings page
- **THEN** the card reads "Woo-index fields from integriq mapping woo-index-publication"
