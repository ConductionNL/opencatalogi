# app-packaging Specification

## Purpose
TBD - created by archiving change product-metadata-and-readme-honesty. Update Purpose after archive.
## Requirements
### Requirement: info.xml declares the actual EUPL-1.2 licence (PKG-001)

`appinfo/info.xml` `<licence>` MUST reflect the app's actual licence, which is
**EUPL-1.2** as declared in `LICENSE` (EUROPEAN UNION PUBLIC LICENCE v. 1.2),
`composer.json` (`"license": "EUPL-1.2"`), and `publiccode.yml`
(`license: EUPL-1.2`). It MUST NOT declare `agpl`. The four declarations
(`info.xml`, `LICENSE`, `composer.json`, `publiccode.yml`) MUST be mutually
consistent.

#### Scenario: licence is consistent across all metadata files

- **GIVEN** the repository at HEAD
- **WHEN** the licence declaration in `appinfo/info.xml` is compared with
  `LICENSE`, `composer.json`, and `publiccode.yml`
- **THEN** `info.xml` MUST declare EUPL-1.2 (not `agpl`)
- **AND** all four MUST agree on EUPL-1.2

### Requirement: info.xml Nextcloud baseline is NC >= 31 (PKG-002)

`appinfo/info.xml` `<nextcloud min-version>` MUST be `31`, matching the fleet
baseline (NC 28–30 are end-of-life). The `max-version` MUST remain the currently
supported ceiling.

#### Scenario: minimum Nextcloud version is 31

- **GIVEN** `appinfo/info.xml`
- **WHEN** the `<nextcloud>` dependency is read
- **THEN** `min-version` MUST be `31`
- **AND** `max-version` MUST remain at the supported ceiling

### Requirement: README describes the real search backend and does not over-promise search (PKG-003)

User-facing documentation MUST NOT claim capabilities the code does not provide.
Specifically: the README MUST NOT claim an **ElasticSearch** backend (none exists
in `lib/`; the actual optional high-performance backend is OpenRegister **SOLR**),
and it MUST NOT present **document-content full-text search** as a shipped
feature — that capability is the in-progress `add-public-fulltext-search` change
and MUST be described as planned, pointing at it.

#### Scenario: no ElasticSearch claim

- **GIVEN** the README
- **WHEN** its Features and Tech-Stack sections are read
- **THEN** they MUST NOT claim an ElasticSearch backend
- **AND** the optional high-performance search backend MUST be described as
  OpenRegister SOLR

#### Scenario: document-content search is marked planned

- **GIVEN** the README's search description
- **WHEN** it references searching across attached document content
- **THEN** it MUST mark that capability as planned (the
  `add-public-fulltext-search` change), not as currently shipped

### Requirement: aggregated-publications documentation matches the routed endpoint (PKG-004)

`README_AGGREGATED_PUBLICATIONS.md` MUST document the endpoint that is actually
registered in `appinfo/routes.php`. The reachable endpoint is
`GET /api/federation/publications` (`FederationController` →
`PublicationService::getAggregatedPublications`), specified by the `federation`
capability (FED-001). The document MUST NOT present the unrouted path
`GET /api/publications/aggregated`, and MUST name `PublicationService::getAggregatedPublications`
(not `DirectoryService::getPublications`) as the entry point.

#### Scenario: documented path is reachable

- **GIVEN** `README_AGGREGATED_PUBLICATIONS.md`
- **WHEN** its documented endpoint path is checked against `appinfo/routes.php`
- **THEN** the documented path MUST be `GET /api/federation/publications`
- **AND** the unrouted `/api/publications/aggregated` MUST NOT appear as the
  endpoint
- **AND** the document MUST cross-reference the `federation` capability (FED-001)

