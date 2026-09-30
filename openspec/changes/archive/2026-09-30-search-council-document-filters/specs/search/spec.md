---
status: proposed
---

# Search filters for council documents

## ADDED Requirements

### Requirement: The public search filters and counts council documents by type and body (REQ-SCF-001)

`GET /api/search` SHALL accept `documentType` and `bodyName` as exact filters on publications that carry them, and SHALL return facet counts for both in the response.

#### Scenario: A citizen filters by document type

- **GIVEN** three council publications, two with `documentType` minutes and one with agenda
- **WHEN** a client requests `GET /api/search?documentType=minutes`
- **THEN** the response lists the two minutes
- **AND** the facet counts show minutes 2 for the unfiltered request

> @e2e exclude Public API contract with no screen of its own (the citizen page is portaliq's); PHPUnit runs decidiq's real schema through the search (PublicationQuerySearchContractTest::testACitizenFiltersByDocumentType).

### Requirement: The public search filters council documents by meeting date range (REQ-SCF-002)

`GET /api/search` SHALL accept `meetingDate[gte]` and `meetingDate[lte]` and return only publications whose `meetingDate` falls in the range, endpoints included. A value that is not a date SHALL be answered with 400 and a message that names the parameter.

#### Scenario: A citizen searches one quarter

- **GIVEN** publications with meeting dates in January, April and May
- **WHEN** a client requests `GET /api/search?meetingDate[gte]=2026-04-01&meetingDate[lte]=2026-06-30`
- **THEN** the response lists only the April and May publications

> @e2e exclude Public API contract with no screen of its own; PHPUnit (PublicationQuerySearchContractTest::testACitizenSearchesOneQuarter).

#### Scenario: A bad date

- **GIVEN** a request with `meetingDate[gte]=tomorrow-ish`
- **WHEN** the request reaches the endpoint
- **THEN** the response is 400 and names `meetingDate[gte]`

> @e2e exclude Public API contract with no screen of its own; PHPUnit (SearchControllerTest::testIndexReturns400NamingAMalformedRangeBound, PublicationQuerySearchContractTest::testABadDateNamesTheParameter).

### Requirement: The contract is tested against the real council fragment (REQ-SCF-003)

A test SHALL run the real `PublicationPayload` fragment from decidiq through the public search and assert filters, range and facets, so a change to either app that breaks the contract fails a test.

#### Scenario: The fragment changes shape

- **GIVEN** the copied fragment no longer marks `bodyName` as facetable
- **WHEN** the contract test runs
- **THEN** it fails and names `bodyName`

> @e2e exclude A test about a test fixture; PHPUnit (PublicationQuerySearchContractTest::testTheFragmentMarksTheThreeFiltersFacetable).
