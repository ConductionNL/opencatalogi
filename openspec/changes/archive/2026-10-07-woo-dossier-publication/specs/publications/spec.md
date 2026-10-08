---
status: proposed
---

# Woo dossier publication

## ADDED Requirements

### Requirement: A publication records its kind, source case and period (REQ-WDP-001)

Implements hydra `woo-citizen-journey`: Both publishing paths MUST create a public, searchable publication.

The `publication` schema SHALL accept `publicationKind` (`woo-besluit` or `actief`), `caseReference` and `period` (`{ from, to }`). `wooCategory` SHALL be the publication's information category. All three new properties SHALL be optional, so existing publications stay valid.

#### Scenario: A decision published from a case

- **GIVEN** a publication payload with `publicationKind: woo-besluit`, `wooCategory: infocat014`, `caseReference` and `period`
- **WHEN** it is validated against the publication schema
- **THEN** it passes

#### Scenario: An unknown kind

- **GIVEN** a payload with `publicationKind: concept`
- **WHEN** it is validated
- **THEN** it fails on `publicationKind`

### Requirement: Public search takes the portal's filter names (REQ-WDP-002)

Implements hydra `woo-citizen-journey` journey step J2.2 (narrow by information category, organisation and publication period).

`GET /api/search` SHALL accept `informatiecategorie[]`, `organisation[]`, `periodFrom` and `periodTo`, and SHALL filter on `wooCategory`, `organization` and the `publicationDate` range. A malformed `periodFrom` or `periodTo` SHALL answer 400 with the parameter named, as other malformed range bounds do.

#### Scenario: Narrow by information category and period

- **GIVEN** publications in categories infocat014 and infocat010
- **WHEN** a visitor searches with `informatiecategorie[]=infocat014&periodFrom=2026-01-01`
- **THEN** the search asks OpenRegister for `wooCategory` infocat014 and `publicationDate[gte]` 2026-01-01

#### Scenario: A malformed period

- **GIVEN** a visitor on the search page
- **WHEN** they search with `periodFrom=yesterday`
- **THEN** the answer is 400 and names `publicationDate[gte]`
