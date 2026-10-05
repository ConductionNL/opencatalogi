---
status: proposed
---

# Woo compliance

## ADDED Requirements

### Requirement: OpenCatalogi declares the Woo annual report template (REQ-WAR-001)

OpenCatalogi SHALL ship a seed object `woo-jaarverslag` for OpenRegister's `report-templates` schema in `lib/Settings/register.d/woo-annual-report.json`, imported only when that schema exists. It SHALL declare these sections, each naming its data source (register, schema, filters) and aggregation in the template format `rapportage-bi-export` defines:

1. Publications per information category: the publication schemas each category's `WooCategoryRegistry::schemasFor()` lists, counted for public publications with `publicationDate` in the year, grouped by `wooCategory`, one row per category of `WooCategoryRegistry::all()` (17), zero rows included.
2. Publications per month in the year.
3. Timeliness: days from `creationDate` to `publicationDate`, median and the share within 14 days; publications without `creationDate` counted on a separate line.
4. Withdrawals: `depublication` records in the year, counted per reason.
5. Each of 1 to 4 for the previous year beside it.
6. Woo requests: a line linking dossiq's Woo request report when dossiq is installed, else the sentence that requests are handled in dossiq, which is not installed.

The template SHALL be generated per year and catalogue, with formats `pdf`, `ods` and `csv`. A test SHALL compare the categories in the template with `WooCategoryRegistry::all()` so a registry change cannot leave the report behind.

#### Scenario: Every category is in the report, zero included
<!-- @e2e exclude Template contract; proven by WooAnnualReportTemplateTest::testEveryRegistryCategoryHasARowIncludingZero, which fails on today's code because no template exists. -->

- **GIVEN** the registry's 17 categories and publications in only three of them in 2026
- **WHEN** the template's category section is evaluated for 2026
- **THEN** it has 17 rows, 14 of them 0

#### Scenario: Timeliness never uses the save moment
<!-- @e2e exclude Template contract; proven by WooAnnualReportTemplateTest::testAPublicationWithoutCreationDateIsCountedApart. -->

- **GIVEN** a publication without `creationDate`
- **WHEN** the timeliness section is evaluated
- **THEN** it is counted on the "no creation date" line and not timed from `@self.created`

### Requirement: An officer generates the annual report from the publications report (REQ-WAR-002)

The publications report page (`PublicationsReport` in `src/manifest.json`) SHALL offer Generate annual report with a year and a catalogue, calling OpenRegister's `POST /api/reports/generate` with the `woo-jaarverslag` template. When OpenRegister has no report generation (the `report-templates` schema or the route is absent), the action SHALL be absent and the page SHALL say the annual report needs a newer OpenRegister.

#### Scenario: An officer generates the 2026 Woo annual report

- **GIVEN** a catalogue with publications in 2025 and 2026 and OpenRegister's report generation available
- **WHEN** an officer chooses Generate annual report for 2026 and PDF
- **THEN** a PDF downloads with the 17 categories, the months, the timeliness, the withdrawals and 2025 beside each

#### Scenario: OpenRegister cannot generate reports
<!-- @e2e exclude Absent-capability path; proven by WooAnnualReportCapabilityTest::testWithoutReportGenerationTheActionIsAbsent. -->

- **GIVEN** an OpenRegister without report generation
- **WHEN** the publications report page loads
- **THEN** there is no Generate annual report action and the page says why
