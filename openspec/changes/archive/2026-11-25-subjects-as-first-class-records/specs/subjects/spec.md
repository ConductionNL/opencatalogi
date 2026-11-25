---
status: proposed
---

# Subjects as first-class records

## ADDED Requirements

### Requirement: A subject can be featured, with its landing data (REQ-SUB-001)

The theme schema in `lib/Settings/publication_register.json` SHALL gain `featured` (boolean, default `false`), `featuredOrder` (integer, at least 0) and `slug` (string, unique within the register, lowercase letters, digits and hyphens), with its `version` bumped. A save that sets `featured` to `true` without a `title` SHALL be refused naming `title`. The officer's theme editor SHALL offer the featured toggle and the order.

#### Scenario: An administrator features a subject

- **GIVEN** a subject "Parkeren" with an image and a description
- **WHEN** an administrator opens it in OpenCatalogi, turns on Featured with order 1 and saves
- **THEN** `GET /api/themes?featured=true` lists "Parkeren" first

#### Scenario: A featured subject needs a title
<!-- @e2e exclude Pre-save refusal; proven by ThemeFeaturedCheckTest::testAFeaturedSubjectWithoutATitleIsRefused. -->

- **GIVEN** a subject without a title
- **WHEN** it is saved with `featured` true
- **THEN** the save is refused naming `title`

### Requirement: The featured subjects are served for the portal (REQ-SUB-002)

`GET /api/themes?featured=true` (public, CORS as the other theme routes) SHALL answer `{results: [{id, slug, title, summary, description, image, url, isExternal, featuredOrder, publicationCount}], total}` for the featured subjects ordered by `featuredOrder` then `title`. `publicationCount` SHALL be the number of publications under the subject that the anonymous public read returns. This is the contract `portaliq/home-and-theme-landing-pages` reads; that change SHALL carry its own test against these keys. When portaliq is not installed nothing in OpenCatalogi changes.

#### Scenario: The count only counts what the public may see
<!-- @e2e exclude Public API contract; proven by FeaturedThemesTest::testTheCountOnlyCountsPublicPublications, which fails on today's code because the featured filter and the count do not exist. -->

- **GIVEN** a featured subject with two public publications, one draft and one scheduled for next week
- **WHEN** a signed-in user calls `GET /api/themes?featured=true`
- **THEN** its `publicationCount` is 2

### Requirement: A subject lists what is filed under it (REQ-SUB-003)

`GET /api/themes/{id}/publications` (public; `{id}` an id, uuid or slug) SHALL answer the publications whose `themes` hold the subject, as returned by the anonymous public read, paginated with `_limit` and `_page` and ordered by `publicationDate` descending, in the same envelope as the public publications list. `GET /api/themes/{id}` SHALL carry `publicationsUrl` pointing at it and `publicationCount`. The theme page in OpenCatalogi SHALL list the same publications for the officer, including non-public ones with their state.

#### Scenario: A subject lists what is filed under it

- **GIVEN** a subject "Sporthal" with three public publications and one draft
- **WHEN** a reader requests `GET /api/themes/sporthal/publications`
- **THEN** the answer lists the three public publications, newest first
- **AND** the officer's theme page lists all four with their state

#### Scenario: A non-public publication is never listed publicly
<!-- @e2e exclude Fail-closed contract; proven by ThemePublicationsTest::testADraftUnderASubjectIsNotListed, which fails on today's code because the route does not exist. -->

- **GIVEN** a subject whose only publication is a draft
- **WHEN** a signed-in user calls the public route
- **THEN** the answer is an empty list with total 0

### Requirement: Public search returns subjects as their own result type (REQ-SUB-004)

`PublicationQueryService::assemblePublicSearchResults()` SHALL include theme objects matching the query on `title`, `summary`, `description` or `content`, inside the same anonymous scope. Every result SHALL carry `resultType`: `subject` for a theme, `document` for a file hit (as D11 resolves it), `publication` otherwise. A subject result SHALL carry `id`, `slug`, `title`, `summary`, `image`, `publicationCount` and `url` (its `GET /api/themes/{id}` URL). Facets SHALL count `resultType`. A request with `resultType=publication` SHALL return what it returns today.

#### Scenario: Search finds a subject
<!-- @e2e exclude Public API contract; proven by PublicSearchSubjectsTest::testASubjectIsAResultWithItsOwnType, which fails on today's code because themes are not searched. -->

- **GIVEN** a subject "Sporthal" whose description mentions "sportaccommodatie", and two public publications that do not
- **WHEN** a reader searches for "sportaccommodatie"
- **THEN** the results hold the subject with `resultType` `subject` and `publicationCount` 2

#### Scenario: Existing results keep their shape
<!-- @e2e exclude Regression contract; proven by PublicSearchSubjectsTest::testAPublicationOnlySearchIsUnchanged. -->

- **GIVEN** the public search fixtures of `PublicationQueryServiceTest`
- **WHEN** they are searched with `resultType=publication`
- **THEN** the results equal today's results with `resultType` `publication` added
