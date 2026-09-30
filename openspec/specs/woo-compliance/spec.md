---
status: in-progress
---

# WOO Compliance (Sitemaps, Robots, DIWOO)

## Purpose

@e2e exclude pure backend/API spec — all scenarios test server-side PHP XML sitemap generation, DIWOO metadata mapping, robots.txt rendering, and catalog schema queries; no browser-observable UI surface; covered by Newman API tests instead.

OpenCatalogi supports Dutch WOO (Wet Open Overheid) compliance by generating XML sitemaps and robots.txt files that conform to the DIWOO metadata standard. This enables government organizations to make their publications discoverable by the Dutch government's central search index (KOOP/DIWOO). Sitemaps are generated per catalog and per WOO information category (informatiecategorie), mapping publications to the DIWOO XML schema with proper metadata including creation dates, publishers, file formats, and document handling information.

## Requirements

### Requirement: Generate XML sitemap index per catalog per WOO information category (WOO-001)
The system MUST generate an XML sitemap index per catalog per WOO information category.

**Priority:** Must **Status:** Implemented

#### Scenario: sitemap index generated for a catalog and category
- GIVEN a catalog with slug "woo-publicaties" and `hasWooSitemap=true`
- WHEN a GET request is made to `/api/woo-publicaties/sitemaps/sitemapindex-diwoo-infocat014.xml`
- THEN the system MUST return a `<sitemapindex>` XML with `<sitemap>` entries for that catalog and WOO category

### Requirement: Generate XML sitemap with DIWOO Document metadata for publications (WOO-002)
The system MUST generate an XML sitemap with DIWOO Document metadata for publications.

**Priority:** Must **Status:** Implemented

#### Scenario: DIWOO sitemap generated for publications
- GIVEN publications exist for a catalog and WOO category
- WHEN a GET request is made to the `.../publications` sitemap endpoint
- THEN the response MUST wrap each file as a `diwoo:Document` element inside `<diwoo:Documents>` with the proper XML namespaces

### Requirement: Support all 17 WOO information categories (informatiecategorieen) (WOO-003)
The system MUST support all 17 WOO information categories (informatiecategorieen).

**Priority:** Must **Status:** Implemented

#### Scenario: every WOO category code is resolvable
- GIVEN the 17 mandatory WOO information categories
- WHEN a sitemap request uses any of the `sitemapindex-diwoo-infocat001..017.xml` codes
- THEN the system MUST map the code to its category and generate a sitemap for it

### Requirement: Generate robots.txt with sitemap URLs for all WOO-enabled catalogs (WOO-004)
The system MUST generate a robots.txt with sitemap URLs for all WOO-enabled catalogs.

**Priority:** Must **Status:** Implemented

#### Scenario: robots.txt lists sitemap URLs
- GIVEN WOO-enabled catalogs exist
- WHEN a GET request is made to `/api/robots.txt`
- THEN the response MUST be plain text containing a `Sitemap: {url}` line for each WOO category of each qualifying catalog

### Requirement: Paginate sitemaps (max 1000 entries per page) (WOO-005)
The system MUST paginate sitemaps (max 1000 entries per page).

**Priority:** Must **Status:** Implemented

#### Scenario: sitemap pagination caps page size
- GIVEN a catalog/category with more than 1000 publications
- WHEN sitemaps are generated
- THEN the system MUST split entries into pages of at most 1000 and expose additional `?page=N` sitemap entries

### Requirement: Map publication + file metadata to DIWOO Document XML structure (WOO-006)
The system MUST map publication + file metadata to the DIWOO Document XML structure.

**Priority:** Must **Status:** Implemented

#### Scenario: publication and file metadata mapped to DIWOO
- GIVEN a publication with attached files
- WHEN the DIWOO sitemap is generated
- THEN each file MUST map to a `diwoo:Document` with the DIWOO fields (loc, lastmod, creatiedatum, publisher, format, informatiecategorie, soortHandeling, atTime)

### Requirement: Validate that requested category belongs to the catalog's schemas (WOO-007)
The system MUST validate that the requested category belongs to the catalog's schemas.

**Priority:** Must **Status:** Implemented

#### Scenario: schema not in catalog is rejected
- GIVEN a valid category code maps to a schema the catalog does not include
- WHEN the sitemap endpoint is called
- THEN the system MUST return a 400 XMLResponse with "Schema not configured in catalog"

### Requirement: Only catalogs with `hasWooSitemap: true` appear in robots.txt (WOO-008)
Only catalogs with `hasWooSitemap: true` MUST appear in robots.txt.

**Priority:** Must **Status:** Bug (RobotsController does NOT check hasWooSitemap)

#### Scenario: robots.txt restricted to WOO-enabled catalogs
- GIVEN catalogs both with and without `hasWooSitemap=true`
- WHEN `/api/robots.txt` is generated
- THEN only catalogs with `hasWooSitemap: true` MUST contribute sitemap entries

### Requirement: All sitemap/robots endpoints are public (WOO-009)
All sitemap/robots endpoints MUST be public.

**Priority:** Must **Status:** Implemented

#### Scenario: sitemap endpoints require no authentication
- GIVEN an unauthenticated client
- WHEN it requests any sitemap or robots.txt endpoint
- THEN the request MUST succeed without authentication

### Requirement: Include file metadata: download URL, format, creation date, publisher, handling type (WOO-010)
The system MUST include file metadata: download URL, format, creation date, publisher, handling type.

**Priority:** Must **Status:** Implemented

#### Scenario: DIWOO document carries file metadata
- GIVEN a file attached to a publication
- WHEN its `diwoo:Document` is generated
- THEN it MUST include the download URL, format, creation date, publisher, and handling type

### Requirement: `diwoo:informatiecategorie` is bound to the official TOOI value list (WOO-TOOI-001)

The system MUST resolve each `diwoo:informatiecategorie` to an official TOOI
informatiecategorie URI drawn from the bundled 17-category waardelijst, rather
than trusting free-object `tooiCategorieNaam`/`tooiCategorieUri` fields. A
publication's category value MUST resolve to a value-list member; when it does,
the emitted `diwoo:informatiecategorie` MUST carry both the official `@resource`
URI and its canonical label. A category value that does not resolve to a
value-list member MUST NOT be emitted as a free-text `@resource`.

#### Scenario: mapped category emits the official TOOI URI

- **GIVEN** a publication in WOO category "Woo-verzoeken en -besluiten" (infocat014)
- **WHEN** its `diwoo:Document` is generated
- **THEN** `diwoo:informatiecategorie @resource` MUST be the official TOOI URI
  for that category
- **AND** the element text MUST be the category's canonical label

#### Scenario: unresolved category is not leaked as a literal

- **GIVEN** a publication whose category value has no TOOI value-list mapping
- **WHEN** its `diwoo:Document` is generated
- **THEN** the document MUST NOT carry a free-text `diwoo:informatiecategorie @resource`
- **AND** the document MUST be reported by the DIWOO validator (WOO-TOOI-004)

### Requirement: `diwoo:publisher @resource` is a TOOI organisatie URI (WOO-TOOI-002)

The system MUST emit `diwoo:publisher @resource` as a valid TOOI organisatie
identifier URI (`https://identifier.overheid.nl/tooi/id/…`), resolved from the
publication's owning OpenRegister organisation via its `tooiIdentifier`
property, not as the organisation's OpenRegister UUID. When the organisation
carries no `tooiIdentifier`, the `@resource` attribute MUST be omitted (the
human-readable `#text` publisher MAY still be emitted) and the document MUST be
reported by the DIWOO validator.

#### Scenario: organisation with a TOOI identifier

- **GIVEN** a publication whose owning organisation has
  `tooiIdentifier = https://identifier.overheid.nl/tooi/id/gemeente/gm0855`
- **WHEN** its `diwoo:Document` is generated
- **THEN** `diwoo:publisher @resource` MUST be that TOOI organisatie URI

#### Scenario: organisation without a TOOI identifier

- **GIVEN** a publication whose owning organisation has no `tooiIdentifier`
- **WHEN** its `diwoo:Document` is generated
- **THEN** `diwoo:publisher` MUST NOT carry a `@resource` that is the OR UUID
- **AND** the document MUST appear in the DIWOO validator report

### Requirement: `diwoo:soortHandeling` is bound to the DiWoo value list (WOO-TOOI-003)

The system MUST resolve `diwoo:soortHandeling` through the bundled DiWoo
soortHandeling waardelijst rather than emitting a hard-coded constant. The
default MUST remain `ontvangst` (a value-list member) for backwards
compatibility, but a publication or catalog MAY declare a different handling
type, which MUST resolve to a value-list member before it is emitted.

#### Scenario: default handling type resolves through the value list

- **GIVEN** a publication that declares no explicit handling type
- **WHEN** its `diwoo:Document` is generated
- **THEN** `diwoo:soortHandeling` MUST be `ontvangst` resolved as a value-list member

#### Scenario: declared handling type is honoured

- **GIVEN** a publication declaring handling type `vaststelling`
- **WHEN** its `diwoo:Document` is generated
- **THEN** `diwoo:soortHandeling` MUST be `vaststelling` from the value list

### Requirement: Bundled TOOI/DiWoo value lists and a DIWOO validator (WOO-TOOI-004)

The OpenCatalogi register bundle MUST ship the TOOI/DiWoo value lists
(informatiecategorieën, organisatie-identificatoren, soortHandeling) as
reference data, and admin/publisher settings MUST provide a "Validate DIWOO
output" action that runs the sitemap mapping in a dry-run mode and reports, per
document, any axis that could not resolve to an official value-list URI. The
validator MUST be advisory — it MUST NOT prevent the sitemap from being served.

#### Scenario: value lists resolvable at render time

- **GIVEN** the OpenCatalogi register bundle is installed
- **WHEN** a DIWOO sitemap is generated
- **THEN** the informatiecategorie, organisatie, and soortHandeling value lists
  MUST be resolvable for binding

#### Scenario: validator reports an unresolved axis

- **GIVEN** a catalog with one publication whose organisation lacks a `tooiIdentifier`
- **WHEN** the publisher runs "Validate DIWOO output"
- **THEN** the report MUST list that document with the unresolved `publisher` axis
- **AND** the sitemap endpoint MUST still serve XML for the catalog

### Requirement: Harvester-readiness self-check validates the deployed public WOO surface (WOO-HR-001)

**Priority:** Must **Status:** Implemented

The system MUST provide an admin-triggered harvester-readiness self-check that
validates, using outbound HTTP requests against the instance's own public base
URL, every precondition for KOOP Woo-harvester ingestion:

1. `robots.txt` is publicly reachable (HTTP 200) and references the DIWOO
   sitemapindex location(s) rendered by `RobotsController`;
2. every WOO-enabled catalog's sitemapindex is publicly reachable and is
   well-formed XML;
3. each per-informatiecategorie sitemap referenced by a sitemapindex is
   publicly reachable, well-formed, and carries the `diwoo:` metadata
   extension elements;
4. the DIWOO metadata in a sampled sitemap validates against the bundled
   DIWOO XSD (same schema version used by the existing admin DIWOO
   validation endpoint);
5. a sampled publication URL from a sitemap resolves publicly with HTTP 200.

Each check MUST produce an individual `pass` / `fail` result with a
machine-readable reason on failure. A check that cannot run because its
prerequisite failed MUST report `skipped`, not `pass`. Outbound requests MUST
go through the existing SSRF-hardened outbound URL guard.

#### Scenario: fully harvestable instance reports all checks passing

- GIVEN an instance with at least one WOO-enabled catalog whose robots.txt,
  sitemapindex, category sitemaps and publication URLs are publicly reachable
  and DIWOO-valid,
- WHEN an admin runs the readiness self-check,
- THEN the report MUST list every check with status `pass`,
- AND the overall verdict MUST be `ready`.

> @e2e exclude Backend outbound-validation contract against the instance's own public surface; requires a publicly-resolvable deployment topology not present in the e2e harness; covered by PHPUnit tests with a mocked HTTP client.

#### Scenario: publicly unreachable sitemapindex fails the check with a reason

- GIVEN a WOO-enabled catalog whose sitemapindex URL returns HTTP 404 from
  the public base URL,
- WHEN an admin runs the readiness self-check,
- THEN the sitemapindex check MUST report `fail` with reason `http-404`,
- AND dependent per-category sitemap checks MUST report `skipped`,
- AND the overall verdict MUST be `not-ready`.

> @e2e exclude Same backend contract as above; PHPUnit with mocked HTTP client.

### Requirement: Readiness report is persisted and retrievable (WOO-HR-002)

**Priority:** Must **Status:** Implemented

The system MUST persist the most recent readiness report (per-check results,
overall verdict, run timestamp, checked base URL) and expose it via an
admin-gated endpoint so the settings UI can render the last-known state
without re-running the checks. Running a new self-check MUST replace the
persisted report atomically.

#### Scenario: last report is returned without re-running checks

- GIVEN a readiness self-check completed at time T,
- WHEN an admin requests the readiness report,
- THEN the persisted report from time T MUST be returned,
- AND no outbound validation requests may be made by that read.

> @e2e exclude Backend persistence contract; covered by PHPUnit.

### Requirement: Woo-index registration status is tracked in configuration (WOO-HR-003)

**Priority:** Must **Status:** Implemented

The system MUST track the organisation's Woo-index registration state as an
admin-editable configuration object with fields `status`
(`not_registered` | `requested` | `registered`), `registeredUrl` (the public
base URL registered in the Woo-index / Register van Overheidsorganisaties)
and `registeredAt` (date). The readiness report MUST include this
registration object, and when `status=registered` the self-check MUST verify
that `registeredUrl` matches the public base URL the checks ran against,
reporting a `url-mismatch` failure otherwise.

#### Scenario: registered URL mismatch is surfaced

- GIVEN registration status `registered` with `registeredUrl`
  `https://old.example.org`,
- AND the instance's configured public base URL is `https://new.example.org`,
- WHEN an admin runs the readiness self-check,
- THEN the registration check MUST report `fail` with reason `url-mismatch`.

> @e2e exclude Backend config contract; covered by PHPUnit.

### Requirement: Readiness endpoints are admin-gated and fail closed (WOO-HR-004)

**Priority:** Must **Status:** Implemented

The readiness run and report endpoints MUST be gated with
`#[AuthorizedAdminSetting]`. When the WOO configuration is absent or no
WOO-enabled catalog exists, the run endpoint MUST fail closed with an
explicit `not-configured` error (HTTP 409) rather than reporting `ready`,
and MUST NOT perform any outbound request.

#### Scenario: unconfigured instance refuses the check instead of passing

- GIVEN an instance with no WOO-enabled catalog,
- WHEN an admin runs the readiness self-check,
- THEN the endpoint MUST respond HTTP 409 with error `not-configured`,
- AND no outbound HTTP request may be made.

> @e2e exclude Backend auth/fail-mode contract; covered by PHPUnit.

### Requirement: A hand-over to a national channel calls the gateway with a real source (REQ-WND-001)

The hand-over to any national channel SHALL resolve the channel to an integriq source object named in the `channel_sources` setting and call the gateway with that object. A channel with no source configured SHALL fail before any call, with a message that names the channel and the setting.

#### Scenario: A configured channel is reached

- **GIVEN** the Woo index channel has a source set in the Woo settings
- **WHEN** an admin requests registration with the Woo index
- **THEN** the gateway is called with that source object and the stored answer is the platform's reply

#### Scenario: A channel with no source

- **GIVEN** the PLOOI channel has no source set
- **WHEN** a delivery to PLOOI is attempted
- **THEN** it fails with a message naming the PLOOI channel and the `channel_sources` setting
- **AND** nothing is recorded as delivered

### Requirement: Official notices travel by reference through the publication gateway (REQ-WND-002)

An official notice for the national publication platform SHALL be sent by dispatching a gateway delivery request for the `publicatie` gateway that carries a document reference and a publication instruction, and never the document itself. A request that integriq does not take SHALL be reported as unreachable and never as acknowledged.

#### Scenario: A notice is announced

- **GIVEN** a decision with a publication type and an effective date
- **WHEN** an admin announces it
- **THEN** a `publicatie` delivery request is dispatched with the reference and the instruction
- **AND** the response shows the delivery returned by integriq

#### Scenario: integriq is not installed

- **GIVEN** no integriq app answers the request
- **WHEN** an admin announces a decision
- **THEN** the response says the channel could not be reached
- **AND** the notice is not marked as sent

### Requirement: A publication that turns public is delivered to PLOOI when the catalogue asks for it (REQ-WND-003)

When a publication in a catalogue with `plooiDelivery` on becomes public, the app SHALL post its DiWoo metadata and document links to the PLOOI source and store `plooiStatus`, `plooiDeliveredAt` and `plooiIdentifier` on the publication. A failed delivery SHALL store `plooiStatus` as failed with the reason, and SHALL NOT block publishing.

#### Scenario: A publication is published

- **GIVEN** a catalogue with `plooiDelivery` on and a PLOOI source set
- **WHEN** an editor publishes a publication in it
- **THEN** the publication shows the PLOOI delivery status and the identifier returned by the platform

#### Scenario: PLOOI refuses

- **GIVEN** the PLOOI source answers with an error
- **WHEN** an editor publishes a publication
- **THEN** the publication is public
- **AND** its `plooiStatus` is failed with the platform's reason

### Requirement: The announce endpoint has a screen (REQ-WND-004)

The publication page SHALL offer an Announce action for an admin that calls `POST /api/publications/announce` and shows the delivery result per channel.

#### Scenario: An admin announces a decision

- **GIVEN** an admin on the page of a publication that is a decision
- **WHEN** the admin chooses Announce
- **THEN** the page lists each channel with its delivery result

### Requirement: A publication stores the Woo information category it belongs to (REQ-WPC-001)

The publication schema SHALL carry an optional property `wooCategory` whose value is one of the codes `infocat001` to `infocat017`. A value outside that list SHALL fail validation against the publication schema, and no category sitemap SHALL list it.

#### Scenario: An editor files a publication

- **GIVEN** an editor on a publication in a Woo-enabled catalogue
- **WHEN** the editor chooses "Jaarplannen en jaarverslagen" in the category select and saves
- **THEN** the stored publication has `wooCategory` equal to `infocat012`

> @e2e exclude The value is written by the generic nc-vue data widget into OpenRegister; the stored property and its enum are proven by PHPUnit against the shipped schema fragment (PublicationWooCategoryTest).

#### Scenario: An unknown code is refused

- **GIVEN** a publication with `wooCategory` set to `infocat099`
- **WHEN** it is validated against the publication schema
- **THEN** validation fails with an error naming the property

> @e2e exclude Schema validation contract; PHPUnit validates the payload with the Opis validator against the shipped fragment (PublicationWooCategoryTest).

### Requirement: Each category sitemap lists the publications filed under it (REQ-WPC-002)

`GET` on the sitemap of category `infocat012` for a Woo catalogue SHALL list the publications of that catalogue whose `wooCategory` is `infocat012` and no others. On an instance that still runs a register titled `woo`, the schema-title lookup SHALL contribute its rows as well. The DiWoo information category of a listed document SHALL come from `wooCategory` when it is set.

#### Scenario: The harvester reads one category

- **GIVEN** three publications, two with `infocat012` and one with `infocat004`
- **WHEN** the national Woo index harvester requests the sitemap of `infocat012`
- **THEN** the sitemap lists exactly the two publications filed under it

> @e2e exclude Server-side XML sitemap generation with no browser surface; PHPUnit (SitemapServiceTest) builds the sitemap from three filed publications.

### Requirement: The editor is offered the 17 categories (REQ-WPC-003)

`GET /api/woo/categories` SHALL return the 17 codes with their Dutch and English names for an admin or an editor, and the publication form SHALL show them in a labelled select.

#### Scenario: The select lists all categories

- **GIVEN** an editor opening a new publication in a Woo-enabled catalogue
- **WHEN** the category select is opened
- **THEN** it lists 17 options, each named in the user's language

> @e2e exclude The options come from the schema enum and its x-enum-labels through the generic nc-vue select; the enum, labels and Dutch names are asserted by PHPUnit (PublicationWooCategoryTest), the API list by tests/e2e/woo-category.spec.ts.

#### Scenario: The category list is read over the API

- **GIVEN** a signed-in user
- **WHEN** they call `GET /api/woo/categories`
- **THEN** the response lists 17 categories, each with its code, Dutch name and English name

### Requirement: A batch publish from a Woo request files under the decision category (REQ-WPC-004)

Publishing a batch from a Woo request SHALL set `wooCategory` to `infocat014` on the publication record it stores on the batch.

#### Scenario: A batch is published

- **GIVEN** an approved Woo request batch
- **WHEN** the batch is published
- **THEN** the publication record stored on the batch has `wooCategory` equal to `infocat014`

> @e2e exclude Needs an approved batch with a completed approval chain, which the e2e harness does not seed; PHPUnit (WooServiceTest) publishes a batch and reads it back.

### Requirement: robots.txt names every Woo sitemap index on its own line (REQ-WIH-001)

The app's robots.txt at `GET /api/robots.txt` SHALL contain one `Sitemap:` line per category sitemap index of each catalogue whose `hasWooSitemap` is true, and no line for any other catalogue. Every line SHALL end in a line break character, never in the two characters backslash and n. The file SHALL allow the app's API paths, so a crawler that honours robots may fetch the sitemaps and the documents they list.

#### Scenario: Two Woo catalogues and one other

- **GIVEN** catalogues `woo-a` and `woo-b` with `hasWooSitemap` true and catalogue `news` with it false
- **WHEN** a harvester requests `GET /index.php/apps/opencatalogi/api/robots.txt`
- **THEN** the response lists the sitemap indexes of `woo-a` and `woo-b`, each on its own line
- **AND** it lists nothing for `news`
- **AND** it contains no literal backslash-n

#### Scenario: The sitemap paths are allowed

- **GIVEN** one Woo-enabled catalogue
- **WHEN** a harvester reads the app's robots.txt
- **THEN** it finds an `Allow:` line covering `/apps/opencatalogi/api/`

### Requirement: The administrator gets the rule that serves robots.txt at the domain root (REQ-WIH-002)

The Woo section of the admin settings SHALL show a ready Apache rule and a ready nginx rule that serve the app's robots.txt at `/robots.txt` on the instance's base URL. When the readiness check reports the root robots.txt as unreachable or without a sitemap line, the panel SHALL point at these rules.

#### Scenario: An administrator copies the nginx rule

- **GIVEN** an administrator on the Woo section of the OpenCatalogi admin settings
- **WHEN** they open the root robots.txt rule
- **THEN** they see an nginx `location = /robots.txt` block that targets the app's robots.txt route on their own base URL
- **AND** a copy button puts it on the clipboard

#### Scenario: A failing check points at the rule

- **GIVEN** a readiness report whose `robots-txt` check failed with `missing-sitemap-reference`
- **WHEN** the administrator opens the Woo section
- **THEN** the failed check says the root robots.txt does not reach the app and links to the rules

### Requirement: The Woo-index registration is requested through the gateway (REQ-WIH-003)

An administrator SHALL request the Woo-index registration with one action. The app SHALL compose the request from the organisation's name and TOOI identifier, the root robots.txt URL and the sitemap index URLs of every Woo-enabled catalogue, and hand it to the gateway's national Woo-index channel. The registration status SHALL move to `requested` only when the gateway answered, and the answer SHALL be stored with the time. When no gateway can take the request, nothing SHALL be recorded as sent, and the composed request SHALL be shown so the administrator can send it another way.

#### Scenario: The gateway takes the request

- **GIVEN** integriq is installed with a `national-woo-index` source
- **WHEN** an administrator presses Request registration in the Woo section
- **THEN** the status shows requested, with the time and the gateway's answer

#### Scenario: No gateway is installed

- **GIVEN** no gateway app is installed
- **WHEN** an administrator presses Request registration
- **THEN** the status stays as it was
- **AND** the panel shows the composed request with the organisation, the robots.txt URL and every sitemap index URL

### Requirement: The readiness verdict stays current without anyone running it (REQ-WIH-004)

The harvester readiness check (WOO-HR-001) SHALL run once a day while at least one catalogue is Woo-enabled, and once whenever a catalogue's `hasWooSitemap` becomes true. The Woo section SHALL show when the last check ran.

#### Scenario: A catalogue is switched on

- **GIVEN** no catalogue was Woo-enabled
- **WHEN** an editor sets `hasWooSitemap` to true on a catalogue
- **THEN** a readiness report exists afterwards without anyone pressing Run check

#### Scenario: Nothing is Woo-enabled

- **GIVEN** no catalogue has `hasWooSitemap` true
- **WHEN** the daily job runs
- **THEN** it makes no outbound request and leaves the stored report unchanged

## Data Model

### WOO Information Categories (INFO_CAT)

The 17 mandatory WOO categories mapped to sitemap codes:

| Code | Category (Dutch) |
|------|-----------------|
| sitemapindex-diwoo-infocat001.xml | Wetten en algemeen verbindende voorschriften |
| sitemapindex-diwoo-infocat002.xml | Overige besluiten van algemene strekking |
| sitemapindex-diwoo-infocat003.xml | Ontwerpen van wet- en regelgeving met adviesaanvraag |
| sitemapindex-diwoo-infocat004.xml | Organisatie en werkwijze |
| sitemapindex-diwoo-infocat005.xml | Bereikbaarheidsgegevens |
| sitemapindex-diwoo-infocat006.xml | Bij vertegenwoordigende organen ingekomen stukken |
| sitemapindex-diwoo-infocat007.xml | Vergaderstukken Staten-Generaal |
| sitemapindex-diwoo-infocat008.xml | Vergaderstukken decentrale overheden |
| sitemapindex-diwoo-infocat009.xml | Agenda's en besluitenlijsten bestuurscolleges |
| sitemapindex-diwoo-infocat010.xml | Adviezen |
| sitemapindex-diwoo-infocat011.xml | Convenanten |
| sitemapindex-diwoo-infocat012.xml | Jaarplannen en jaarverslagen |
| sitemapindex-diwoo-infocat013.xml | Subsidieverplichtingen anders dan met beschikking |
| sitemapindex-diwoo-infocat014.xml | Woo-verzoeken en -besluiten |
| sitemapindex-diwoo-infocat015.xml | Onderzoeksrapporten |
| sitemapindex-diwoo-infocat016.xml | Beschikkingen |
| sitemapindex-diwoo-infocat017.xml | Klachtoordelen |

### DIWOO Document Metadata Mapping

Each file attached to a publication generates a `diwoo:Document` with:

| DIWOO Field | Source |
|-------------|--------|
| loc | file.downloadUrl |
| lastmod | file.published or publication.@self.updated |
| diwoo:creatiedatum | publication.@self.created |
| diwoo:publisher @resource | publication.@self.organisation |
| diwoo:publisher #text | file.owner or publication.@self.owner |
| diwoo:format @resource | europa.eu file-type URI from file.extension |
| diwoo:format #text | file.extension (lowercase) |
| diwoo:informatiecategorie #text | publication.tooiCategorieNaam |
| diwoo:informatiecategorie @resource | publication.tooiCategorieUri |
| diwoo:soortHandeling | "ontvangst" (receipt) |
| diwoo:atTime | file.published or publication.publicatiedatum (the removed object-level @self.published is always empty for magic-mapped publications) |

## API Endpoints

| Method | Endpoint | Description |
|--------|----------|-------------|
| GET | `/api/{catalogSlug}/sitemaps/{categoryCode}` | Sitemap index for a catalog + WOO category |
| GET | `/api/{catalogSlug}/sitemaps/{categoryCode}/publications` | Sitemap with DIWOO Document entries (paginated, ?page=N) |
| GET | `/api/robots.txt` | Robots.txt with sitemap URLs for all WOO-enabled catalogs |

## Scenarios

### Scenario: Generate sitemap index
- GIVEN a catalog with slug "woo-publicaties" and hasWooSitemap=true
- AND the WOO category "sitemapindex-diwoo-infocat014.xml" maps to schema "Woo-verzoeken en -besluiten"
- WHEN a GET request is made to `/api/woo-publicaties/sitemaps/sitemapindex-diwoo-infocat014.xml`
- THEN the SitemapService validates the category code and catalog
- AND verifies the schema exists in the catalog's schema list
- AND queries publications ordered by updated DESC with limit 1000
- AND generates a `<sitemapindex>` XML with `<sitemap>` entries containing:
  - loc: URL to the publications sitemap page
  - lastmod: updated timestamp of the first (most recent) publication in that batch
- AND pagination creates additional sitemap entries for each batch of 1000

### Scenario: Generate DIWOO sitemap
- GIVEN publications exist for the specified catalog and WOO category
- WHEN a GET request is made to `/api/woo-publicaties/sitemaps/sitemapindex-diwoo-infocat014.xml/publications?page=1`
- THEN publications are fetched with register/schema filters (limit 1000, page N)
- AND for each publication, files are fetched via FileService
- AND each file generates a `diwoo:Document` XML element with proper DIWOO metadata
- AND the response is wrapped in `<diwoo:Documents>` with proper XML namespaces (sitemaps.org, DIWOO, XSD schema locations)

### Scenario: Generate robots.txt
- GIVEN catalogs exist, some with hasWooSitemap=true
- WHEN a GET request is made to `/api/robots.txt`
- THEN all catalogs are fetched from the catalog register/schema
- AND only catalogs with a slug are included (**Bug**: `hasWooSitemap` is NOT checked by `RobotsController` -- all catalogs with a slug get sitemap entries. The `SitemapService.isValidSitemapRequest()` checks `hasWooSitemap` for individual sitemap requests, but the robots.txt generation does not.)
- AND for each qualifying catalog, 17 sitemap URLs are generated (one per WOO category)
- AND the response is plain text with "Sitemap: {url}" lines

### Scenario: Invalid category code
- GIVEN a request with an invalid category code "invalid.xml"
- WHEN the sitemap endpoint is called
- THEN a 400 error XMLResponse is returned with "Invalid category code"

### Scenario: Schema not in catalog
- GIVEN a valid category code maps to schema ID 5
- BUT the catalog does not include schema 5 in its schemas array
- WHEN the sitemap endpoint is called
- THEN a 400 error XMLResponse is returned with "Schema not configured in catalog"

## Cross-References

- **Woo category mapping** (in progress, `openspec/changes/woo-category-mapping-intake/`): the
  `diwoo:informatiecategorie` axis (WOO-TOOI-001) gains a type-level default fallback sourced from the
  `woo-category-mapping` capability, consumed by any publishing app (including a future decidesk
  `DiWooMetadataService`) via a direct OpenRegister object query — see that change's proposal and design for
  the full cross-app mapping contract.
- **Auto-publishing**: When `auto_publish_attachments` is enabled (see [auto-publishing spec](../auto-publishing/spec.md)), files get share links created automatically. These share links are used as `downloadUrl` values in DIWOO sitemap documents. Without auto-publishing or manual share link creation, the DIWOO sitemap `loc` fields may be empty.
- **File Management**: The [file management service](../file-management/spec.md) provides share link creation used by both auto-publishing and WOO sitemap generation.
- **Download Service**: The [download service](../download-service/spec.md) generates PDF/ZIP exports of publications, which is complementary to the WOO sitemap's XML-based discovery mechanism.

## Dependencies

- **SitemapService** - buildSitemapIndex(), buildSitemap(), mapDiwooDocument(), isValidSitemapRequest()
- **SettingsService** - getSettings() for register/schema lookups
- **OpenRegister ObjectService** - searchObjectsPaginated for publication queries
- **OpenRegister FileService** - getFiles(), formatFiles() for file metadata
- **Nextcloud IURLGenerator** - Base URL generation for sitemap URLs
- **XMLResponse** - Custom response class for XML output
- **TextResponse** - Custom response class for robots.txt output
