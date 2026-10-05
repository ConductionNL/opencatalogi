---
status: proposed
---

# DCAT-AP harvest

## ADDED Requirements

### Requirement: The licence comes from a controlled list, bind or omit (REQ-DCC-001)

`lib/Settings/dcat_waardelijsten.json` SHALL gain a `licences` list taken from the EU licences authority list, with each member's code, label and authority IRI, its version and source URL recorded beside it. `DcatVocabularyService::resolveLicence(?string $value): ?string` SHALL answer the authority IRI for an IRI, code or exact label (case-insensitive), and `licenceList(): array` SHALL list the members. `DcatMappingService` SHALL emit `dct:license` on the catalogue, the dataset and each distribution only as a resolved IRI. A value that does not resolve SHALL be omitted and reported by the DCAT-010 validator per dataset. The admin DCAT settings, the catalogue form (`dcatLicense`) and the publication form (`license`) SHALL offer a picker over the list and SHALL refuse a value outside it on save.

#### Scenario: A licence from the list
<!-- @e2e exclude Feed contract; proven by DcatMappingServiceTest::testALicenceCodeIsEmittedAsItsAuthorityIri, which fails on today's code because the raw value is emitted. -->

- **GIVEN** a catalogue whose `dcatLicense` is `CC0`
- **WHEN** its DCAT feed is requested
- **THEN** `dct:license` is `http://publications.europa.eu/resource/authority/licence/CC0`

#### Scenario: A free-text licence is not emitted
<!-- @e2e exclude Fail-closed feed contract; proven by DcatMappingServiceTest::testAnUnresolvedLicenceIsOmittedAndReported. -->

- **GIVEN** a legacy publication whose `license` is "vrij te gebruiken"
- **WHEN** the feed is rendered and validated
- **THEN** the dataset carries no `dct:license` from that value
- **AND** the validator lists the dataset with the unresolved licence

#### Scenario: An editor picks a licence

- **GIVEN** an administrator on the DCAT settings
- **WHEN** they open the default licence field
- **THEN** they choose from the list and cannot type a value outside it

### Requirement: The data.overheid.nl registration and its harvest are tracked (REQ-DCC-002)

`OCA\OpenCatalogi\Service\DonlRegistrationService` SHALL keep, in app config, `status` (`not_requested`, `requested`, `registered`), `requestedAt`, `registeredAt`, `lastFetchAt` (the last fetch of `/api/dcat` or `/api/catalogs/{slug}/dcat` whose user agent matches the configured DONL harvester pattern), `lastCheckAt`, `donlDatasetCount`, `feedDatasetCount` and `lastError`. A daily `TimedJob` `DonlHarvestCheck` SHALL ask the DONL CKAN API for the number of datasets from the configured source (`package_search` filtered on the source the operator records) and store the count, or the error. The admin DCAT settings SHALL show the status with these values, and SHALL show "Harvested" only when `lastFetchAt` is set or `donlDatasetCount` is greater than 0. The operator SHALL record requested and registered by hand (DCAT-NPF-001 is unchanged).

#### Scenario: The admin sees whether data.overheid.nl harvests us

- **GIVEN** an operator who recorded the registration last month, a DONL fetch of `/api/dcat` yesterday, and a DONL count of 118 against 120 datasets in the feed
- **WHEN** the administrator opens the DCAT settings
- **THEN** they see Registered, last harvested yesterday, 118 of 120 datasets on data.overheid.nl

#### Scenario: The DONL API cannot be reached
<!-- @e2e exclude Background job failure path; proven by DonlHarvestCheckTest::testAnUnreachableApiKeepsTheLastCountAndSaysSo, which fails on today's code because the job does not exist. -->

- **GIVEN** a stored count of 118 from last week and an API call that times out
- **WHEN** the job runs
- **THEN** the count stays 118 with last week's date and `lastError` names the timeout

#### Scenario: The operator's word alone is not harvested
<!-- @e2e exclude Status rule; proven by DonlRegistrationServiceTest::testRegisteredWithoutEvidenceIsNotHarvested. -->

- **GIVEN** status `registered`, no recorded fetch and a count of 0
- **WHEN** the status is read
- **THEN** it does not say harvested

### Requirement: The catalogue is served as linked data and through SPARQL (REQ-DCC-003)

Every catalogue and dataset IRI the feed emits SHALL dereference: a `GET` on it with `Accept: text/turtle`, `application/rdf+xml` or `application/ld+json` SHALL answer that resource's graph in that format through `DcatSerializer`, and with `text/html` SHALL redirect to its public page. `GET` and `POST /api/sparql` (public, CORS) SHALL answer SPARQL 1.1 `SELECT`, `ASK`, `CONSTRUCT` and `DESCRIBE` queries over the published DCAT graph, in SPARQL JSON results or the RDF format asked for. The graph SHALL be built from the same anonymous public read as `/api/dcat` and cached, and the cache SHALL be invalidated when a publication or catalogue changes. Update operations, `SERVICE` and `LOAD` SHALL be refused with 400. A result SHALL be capped at 10,000 rows and a query at 10 seconds, with the cap said in the response.

#### Scenario: A dataset IRI dereferences
<!-- @e2e exclude Content negotiation on a public endpoint; proven by DcatDereferenceTest::testADatasetIriAnswersTurtle, which fails on today's code because the IRI is not routed. -->

- **GIVEN** a public publication whose dataset IRI is in the feed
- **WHEN** that IRI is requested with `Accept: text/turtle`
- **THEN** the answer is Turtle describing that dataset only

#### Scenario: A SPARQL query over the catalogue
<!-- @e2e exclude Public query endpoint; proven by SparqlEndpointTest::testASelectQueryListsTheDatasetsOfATheme, which fails on today's code because the route does not exist. -->

- **GIVEN** three public datasets of which two carry the data theme TRAN, and one draft carrying TRAN
- **WHEN** a client posts `SELECT ?d WHERE { ?d a dcat:Dataset ; dcat:theme <http://publications.europa.eu/resource/authority/data-theme/TRAN> }`
- **THEN** the answer lists exactly the two public datasets

#### Scenario: An update is refused
<!-- @e2e exclude Fail-closed path; proven by SparqlEndpointTest::testAnUpdateIsRefused. -->

- **WHEN** a client posts `INSERT DATA { <x> <y> <z> }`
- **THEN** the answer is 400 and nothing changes
