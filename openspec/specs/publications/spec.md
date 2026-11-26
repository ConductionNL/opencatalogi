---
status: in-progress
retrofit_extensions:
  - PUB-016
  - PUB-017
  - PUB-018
---

# Publications

## Purpose

@e2e exclude retrofit spec — public publications HTTP API contract (catalog-scoped lists, detail, attachments, downloads, relations) verified by Newman API tests, not browser-UI observable.

Publications are the core content objects in OpenCatalogi. They represent individual published documents, records, or data entries within a catalog. The publications API provides public, read-only access to publications scoped by catalog slug, including support for attachments, file downloads, and object relation traversal (uses/used-by). Publications are consumed by external frontends like tilburg-woo-ui.

## Requirements

### Requirement: List publications scoped to a catalog slug with pagination and facets (PUB-001)
The system MUST list publications scoped to a catalog slug with pagination and facets.

**Priority:** Must **Status:** Implemented

#### Scenario: List publications in a catalog
- GIVEN a catalog with slug "publications" with schemas [1, 2] and registers [1]
- WHEN a GET request is made to `/api/publications`
- THEN the catalog MUST be resolved by slug and publications returned scoped to its schemas/registers with pagination and facets

### Requirement: Retrieve a single publication by catalog slug and object UUID or slug (PUB-002)

The system MUST retrieve a single publication by catalog slug and an identifier that is
either the object's UUID or the object's slug. The identifier MUST first be resolved as
a UUID within the catalogue's registers and schemas, exactly as before. Only when that
finds no publication MUST it be resolved as a slug within the same registers and
schemas. A publication outside the catalogue's registers and schemas MUST NOT be
returned by either route.

**Priority:** Must **Status:** Implemented

#### Scenario: Get a single publication by UUID

- GIVEN a publication with UUID "xyz-789" within a catalog
- WHEN a GET request is made to `/api/{catalogSlug}/xyz-789`
- THEN the matching publication MUST be rendered and returned

#### Scenario: Get a single publication by slug

- GIVEN a publication with slug "2026-0042" within catalog "woo"
- WHEN a GET request is made to `/api/woo/2026-0042`
- THEN that publication MUST be rendered and returned with HTTP 200
- AND the response MUST have the same shape as a lookup by its UUID

#### Scenario: The URL id is normalised before the slug lookup

- GIVEN a publication stored with slug "zaak-2026-0042" within catalog "woo"
- WHEN a GET request is made to `/api/woo/Zaak_2026-0042`
- THEN the identifier MUST be normalised like OpenRegister normalises a stored slug (lowercase, spaces and underscores to hyphens, other characters outside a-z, 0-9 and hyphen dropped, hyphen runs collapsed, edge hyphens trimmed, cut to 255) before the slug lookup
- AND that publication MUST be returned with HTTP 200
- AND an identifier with nothing left after normalising MUST NOT run a slug search (HTTP 404)

#### Scenario: Slug of a publication in another catalog

- GIVEN a publication with slug "2026-0042" in catalog "archive" only
- WHEN a GET request is made to `/api/woo/2026-0042`
- THEN the response MUST be HTTP 404

#### Scenario: Unknown slug

- GIVEN no publication in catalog "woo" holds slug "does-not-exist"
- WHEN a GET request is made to `/api/woo/does-not-exist`
- THEN the response MUST be HTTP 404 with the existing "Publication not found" body

### Requirement: Publication list endpoint must filter by the catalog's configured registers and schemas (PUB-003)
The publication list endpoint MUST filter by the catalog's configured registers and schemas.

**Priority:** Must **Status:** Implemented

#### Scenario: List filtered by catalog registers and schemas
- GIVEN a catalog configured with specific registers and schemas
- WHEN its publications are listed
- THEN searchObjectsPaginated MUST be called with the catalog's `_schemas` and `_register`

### Requirement: Support multi-schema catalogs with UNION-based search across multiple magic tables (PUB-004)
The system MUST support multi-schema catalogs with UNION-based search across multiple magic tables.

**Priority:** Must **Status:** Implemented

#### Scenario: Multi-schema catalog strips non-universal ordering
- GIVEN a catalog spanning schemas with different property names
- WHEN ordering by a non-universal field is requested
- THEN only universal order fields (uuid, created, updated, published, depublished) MUST be allowed and non-universal order fields stripped

### Requirement: Support `_extend` parameter for including related object data (PUB-005)
The system SHALL support the `_extend` parameter for including related object data. It SHOULD expand the requested relations in the response.

**Priority:** Should **Status:** Implemented

#### Scenario: Extend a publication with related data
- GIVEN a request including an `_extend` parameter
- WHEN the publication is rendered
- THEN the requested related object data MUST be included in the response

### Requirement: Retrieve publication attachments (files linked to a publication) (PUB-006)
The system MUST retrieve publication attachments (files linked to a publication).

**Priority:** Must **Status:** Implemented

#### Scenario: Get publication attachments
- GIVEN a published publication with attached files
- WHEN a GET request is made to `/api/{catalogSlug}/{id}/attachments`
- THEN the publication MUST be verified in the catalog and its file metadata returned

### Requirement: Download publication files (PUB-007)
The system MUST allow downloading publication files.

**Priority:** Must **Status:** Implemented

#### Scenario: Download a publication file
- GIVEN a publication with downloadable files
- WHEN a GET request is made to `/api/{catalogSlug}/{id}/download`
- THEN the file content MUST be returned for download

### Requirement: Retrieve outgoing relations (objects this publication references) via `/uses` (PUB-008)
The system MUST retrieve outgoing relations (objects this publication references) via `/uses`.

**Priority:** Must **Status:** Implemented

#### Scenario: Traverse outgoing relations
- GIVEN a publication "abc" that references objects "def" and "ghi"
- WHEN a GET request is made to `/api/{catalogSlug}/abc/uses`
- THEN the referenced objects MUST be returned with RBAC enabled

### Requirement: Retrieve incoming relations (objects that reference this publication) via `/used` (PUB-009)
The system MUST retrieve incoming relations (objects that reference this publication) via `/used`.

**Priority:** Must **Status:** Implemented

#### Scenario: Traverse incoming relations
- GIVEN objects that reference publication "abc"
- WHEN a GET request is made to `/api/{catalogSlug}/abc/used`
- THEN the referencing objects MUST be returned

### Requirement: All public endpoints must include CORS headers (PUB-010)
All public endpoints MUST include CORS headers.

**Priority:** Must **Status:** Implemented

#### Scenario: CORS headers on publication endpoints
- GIVEN a cross-origin frontend
- WHEN it requests any public publication endpoint
- THEN the response MUST include CORS headers

### Requirement: Return 404 with descriptive error when catalog slug or publication ID not found (PUB-011)
The system MUST return 404 with a descriptive error when the catalog slug or publication ID is not found.

**Priority:** Must **Status:** Implemented

#### Scenario: Unknown catalog slug or publication returns 404
- GIVEN a request for an unknown catalog slug or publication ID
- WHEN the endpoint resolves it
- THEN the system MUST return HTTP 404 with a descriptive error message

### Requirement: Publication endpoints use wildcard `{catalogSlug}` routes (must be last in route order) (PUB-012)
Publication endpoints MUST use wildcard `{catalogSlug}` routes, which MUST be placed last in route order.

**Priority:** Must **Status:** Implemented

#### Scenario: Wildcard routes registered last
- GIVEN the `{catalogSlug}` wildcard routes with requirement `[a-z0-9-]+`
- WHEN routes are registered
- THEN they MUST be placed last so they do not shadow named routes like `/api/themes` or `/api/glossary`

### Requirement: Support filter parameter extraction from various formats (single, array, OR/AND operators) (PUB-013)
The system SHALL support filter parameter extraction from various formats (single value, array, OR/AND operators). It SHOULD normalise these into a list of integer values.

**Priority:** Should **Status:** Implemented

#### Scenario: Filter extraction with OR syntax
- GIVEN a request with `?schemas[or]=1,2,3`
- WHEN extractFilterValues() processes the filter
- THEN it MUST return `[1, 2, 3]` as integer values usable for multi-schema filtering

### Requirement: Fallback object location lookup across all magic tables when catalog register/schema search fails (PUB-014)
The system SHALL perform a fallback object-location lookup across all magic tables when the catalog register/schema search fails. It SHOULD use this fallback only after the fast path misses.

**Priority:** Should **Status:** Implemented

#### Scenario: Fallback location lookup on miss
- GIVEN a publication not found in the catalog's register/schema combinations
- WHEN the single-publication endpoint resolves it
- THEN findObjectLocation() MUST search all magic tables via a UNION ALL query to locate the object's register/schema

### Requirement: Schema authorization (RBAC) is enabled on the publication list for conditional access rules (PUB-015)
Schema authorization (RBAC) SHALL be enabled on the publication list so conditional access rules apply. It SHOULD enforce schema-level access conditions.

**Priority:** Should **Status:** Implemented

#### Scenario: RBAC applied on the publication list
- GIVEN schemas with conditional access rules
- WHEN the publication list is served
- THEN schema authorization MUST be enabled so conditional access rules are evaluated

### Requirement: Publish a publication object from the frontend store (PUB-016)
The frontend object store SHALL publish a publication by POSTing to the OpenRegister
publish endpoint `/index.php/apps/openregister/api/objects/{register}/{schema}/{id}/publish`.
The register and schema identifiers are resolved from the object's `@self` metadata
(falling back to top-level `register`/`schema`) and reduced to bare IDs via `extractId`.
On success the store replaces the active `publication` object with the server's response
and removes the object from the current multi-select selection. Per-object loading and
error state are tracked under the keys `publish_{id}`.

**Priority:** Must **Status:** Implemented

#### Scenario: Publish an unpublished publication
- GIVEN a publication object with resolvable `id`, `register`, and `schema`
- WHEN `objectStore.publishObject(object)` is called
- THEN a POST request MUST be sent to the OpenRegister `.../{id}/publish` endpoint
- AND the returned object MUST replace the active `publication` if it matches the object's id
- AND the object MUST be removed from the selected-objects list if currently selected

#### Scenario: Publish with missing register/schema metadata
@e2e exclude internal store guard — this is a pre-request validation throw inside objectStore with no browser-rendered UI feedback; covered by Jest store unit test.
- GIVEN a publication object lacking `id`, `register`, or `schema`
- WHEN `objectStore.publishObject(object)` is called
- THEN the store MUST throw an error before issuing any HTTP request

### Requirement: Depublish a publication object from the frontend store (PUB-017)
The frontend object store SHALL depublish a publication by POSTing to the OpenRegister
depublish endpoint `/index.php/apps/openregister/api/objects/{register}/{schema}/{id}/depublish`,
mirroring the publish flow: register/schema resolved from `@self`, active publication
replaced with the server response on success, the object removed from the current
selection, and loading/error state tracked under `depublish_{id}` keys.

**Priority:** Must **Status:** Implemented

#### Scenario: Depublish a published publication
- GIVEN a published publication object with resolvable `id`, `register`, and `schema`
- WHEN `objectStore.depublishObject(object)` is called
- THEN a POST request MUST be sent to the OpenRegister `.../{id}/depublish` endpoint
- AND the returned object MUST replace the active `publication` if it matches the object's id

#### Scenario: Depublish failure surfaces an error
@e2e exclude store error-state tracking — the error is recorded in Pinia store state under a key; no reliably-automatable error-state UI is rendered without a failing API endpoint in the test environment; covered by Jest store unit test.
- GIVEN the depublish endpoint returns a non-OK HTTP status
- WHEN `objectStore.depublishObject(object)` is called
- THEN the store MUST record the error under `depublish_{id}` and re-throw it

### Requirement: Provide a publish/depublish confirmation dialog (PUB-018)
The system SHALL provide a `PublishPublicationDialog` shown when the navigation store's
dialog is `publishPublication`. The dialog reads the active `publication` from the object
store, displays a "Publish publication" or "Depublish publication" heading based on the
publication's status, and renders a confirmation prompt with Publish/Cancel actions plus
success and error note cards.

**Priority:** Should **Status:** Implemented

#### Scenario: Open the publish dialog for an unpublished publication
- GIVEN the active publication has a status other than `Published`
- WHEN the navigation store dialog is set to `publishPublication`
- THEN the dialog MUST render with a "Publish publication" heading and the publication title
- AND a primary Publish button MUST be shown

#### Scenario: Open the dialog for a published publication
- GIVEN the active publication has status `Published`
- WHEN the dialog is opened
- THEN the dialog MUST render with a "Depublish publication" heading

> **Notes (observed-but-buggy — not fixed by this retrofit):**
> The dialog's confirm handler `handleCopy()` does NOT call `publishObject`/`depublishObject`.
> It reads the active **menu** object, clones it with a `(kopie)` title, and calls
> `objectStore.createObject('menu', ...)` — clearly copy-pasted from a "copy menu" dialog.
> So clicking Publish currently copies a menu instead of publishing the publication.
> Additionally, the `catch (error)` block shadows the outer `error` ref and then assigns
> `error.value`, which throws on the shadowed local. REQ PUB-018 specifies the *intended*
> publish/depublish confirmation behavior; the handler bug is tracked separately and must
> be fixed in a code change, not silently re-specified here.

### Requirement: Maps leaf widget on geo publications (PUB-MAP-001)
The system MUST surface the geometry of a publication's `geo` GeoJSON property by
**placing the OpenRegister maps leaf widget** on the publication detail page via
the app manifest (`detail.config` widgets, ADR-024 / ADR-036) — NOT by building a
bespoke Leaflet/map component in OpenCatalogi (hydra ADR-022). The widget binds to
`publication.geo` and renders points / areas / routes on a map.

> @e2e exclude OR maps-leaf manifest-placement + graceful-degradation contract — the rendered map is produced by the external OpenRegister maps leaf (integration registry, ADR-022/036), not by opencatalogi, which only declares the widget placement in the manifest and MUST NOT ship a bespoke Leaflet component. The assertion is the manifest placement + the "maps integration required" / clean-empty degradation when the leaf or geo data is absent (the default state in any instance without the leaf installed). Verified by vitest over the manifest placement and the degradation branch; the detail page is reachable via spa-deep-link-routing::open-a-deep-link-directly.

#### Scenario: Publication with geo data shows a map
- GIVEN a publication whose `geo` property contains valid GeoJSON
- WHEN a user opens the publication detail page
- THEN the maps leaf widget renders the geometry on a map
- AND OpenCatalogi does NOT ship a bespoke map component for this

#### Scenario: Publication without geo data
- GIVEN a publication with no `geo` data (or invalid GeoJSON)
- WHEN the publication detail page renders
- THEN the maps widget hides or shows a clean empty state (no error)

#### Scenario: Maps leaf absent
- GIVEN the OpenRegister maps leaf / integration is not available
- WHEN the publication detail page renders
- THEN the maps widget degrades gracefully ("maps integration required")

### Requirement: Contacts leaf widget on the Organisation detail (PUB-CON-001)
The system MUST surface an Organisation's contact persons / addresses by
**placing the OpenRegister contacts leaf widget** on the Organisation
object-detail surface via the app manifest (ADR-024 / ADR-036) — NOT via ad-hoc
free-text contact fields or a bespoke contact component (hydra ADR-022). The
Organisation is the contactable bestuursorgaan behind publications.

> @e2e exclude OR contacts-leaf manifest-placement + graceful-degradation contract — the contact list is produced by the external OpenRegister contacts leaf (integration registry, ADR-022/036), not by opencatalogi, which only declares the widget placement and MUST NOT keep a parallel contact model. The assertion is the manifest placement + the "contacts integration required" degradation when the leaf is absent (the default state without the leaf installed). Verified by vitest over the manifest placement and the degradation branch; the Organisation detail page is reachable via spa-deep-link-routing::open-a-deep-link-directly.

#### Scenario: View an Organisation's linked contacts
- GIVEN an Organisation with linked OR contacts
- WHEN a user opens the Organisation detail page
- THEN the contacts leaf widget lists the linked contact persons / addresses
- AND OpenCatalogi does NOT maintain a parallel contact model for this

#### Scenario: Contacts leaf absent
- GIVEN the OpenRegister contacts leaf / integration is not available
- WHEN the Organisation detail page renders
- THEN the contacts widget degrades gracefully ("contacts integration required")

### Requirement: Optional photos and bookmarks leaf widgets on publications (PUB-MEDIA-001)
The system MUST surface any publication image-attachment gallery or curated
external-link list on the publication detail page by **placing the OpenRegister
photos and bookmarks leaf widgets** via the app manifest (ADR-024 / ADR-036) —
NOT by building bespoke gallery / link components (hydra ADR-022). These
placements are optional and each MUST be gated independently on its leaf's
availability; neither placement MUST block the maps (PUB-MAP-001) or contacts
(PUB-CON-001) placements.

> @e2e exclude OR photos/bookmarks-leaf optional manifest-placement + graceful-degradation contract — the gallery / link list is produced by the external OpenRegister photos and bookmarks leaves (integration registry, ADR-022/036), not by opencatalogi, which only declares optional, independently-gated placements and MUST NOT ship bespoke gallery/link components. The assertion is the manifest placement + each widget being omitted when its leaf is absent without affecting the required widgets (the default state without the leaves installed). Verified by vitest over the manifest placement and the per-leaf gating branch.

#### Scenario: Photos widget shows an image gallery
- GIVEN a publication with image attachments
- AND the photos leaf is available
- WHEN a user opens the publication detail page
- THEN the photos leaf widget renders the images as a gallery

#### Scenario: Bookmarks widget shows curated links
- GIVEN a publication with curated external links
- AND the bookmarks leaf is available
- WHEN a user opens the publication detail page
- THEN the bookmarks leaf widget lists the links

#### Scenario: Optional leaf absent
- GIVEN the photos or bookmarks leaf is not available
- WHEN the publication detail page renders
- THEN that optional widget is omitted without affecting the required widgets

### Requirement: The server tells the page whether a publication is public (REQ-PPW-001)

`GET /api/publications/{id}/visibility` SHALL answer the publication's state as one of draft, in_review, approved, scheduled, public, withdrawn or archived, computed by `PublicationStateService::stateOf()` from its stored lifecycle state and, while that state is `published`, its publication date and depublication date (REQ-PLC-002). It SHALL refuse a user who cannot read the publication.

#### Scenario: A publication with a past publication date

- **GIVEN** a publication in stored state `published` with a publication date last week and no depublication date
- **WHEN** an editor's page calls `GET /api/publications/{id}/visibility`
- **THEN** the answer is public

#### Scenario: A withdrawn publication

- **GIVEN** a publication in stored state `published` whose depublication date has passed
- **WHEN** the page asks for its visibility
- **THEN** the answer is withdrawn

#### Scenario: A publication in review

- **GIVEN** a publication in stored state `in_review` with a publication date last week
- **WHEN** the page asks for its visibility
- **THEN** the answer is in_review

### Requirement: An editor publishes or withdraws a publication in one action (REQ-PPW-002)

The publication page SHALL offer Publish now for a draft or approved publication, and for a scheduled publication only to a user with the `liftEmbargo` right, through Lift embargo (REQ-PSG-002). It SHALL offer Withdraw for a public or scheduled one, each only when it applies. Publish now SHALL make the publication public at once, after the document check of REQ-PSG-004. Withdraw SHALL require a reason, take the publication down at once, record who did it and why, and send a withdrawal to every channel the publication reached. Only a user who may update the publication SHALL be able to do either.

#### Scenario: An editor withdraws a publication published by mistake

- **GIVEN** a public publication that reached the national Woo-index
- **WHEN** the editor chooses Withdraw, enters the reason "Wrong annex attached" and confirms
- **THEN** readers of the public API no longer get the publication
- **AND** a depublication with the editor's name, the reason and a withdrawal for the Woo-index channel is stored
- **AND** the message names any channel that did not confirm

#### Scenario: A reader without update rights

- **GIVEN** a user who can read but not update a public publication
- **WHEN** they call `POST /api/publications/{id}/withdraw`
- **THEN** the call is refused and the publication stays public

#### Scenario: A scheduled publication without the lift right

- **GIVEN** an editor without the `liftEmbargo` right and a scheduled publication
- **WHEN** the editor opens the publication page
- **THEN** Publish now is not offered and the page shows the embargo moment

### Requirement: A withdrawn publication can be published again (REQ-PPW-003)

A withdrawn publication SHALL offer Publish again, which makes it public at once. Earlier depublications SHALL stay in its history. Archive SHALL remain the only final step.

#### Scenario: An editor restores a publication after fixing it

- **GIVEN** a withdrawn publication
- **WHEN** the editor chooses Publish again and confirms
- **THEN** the publication is public
- **AND** its earlier depublication is still listed

### Requirement: One document comes down without its publication (REQ-PPW-004)

An editor SHALL be able to withdraw one document of a publication with a reason. The document SHALL leave the public API and the DiWoo sitemap at once, the rest of the publication SHALL stay public, and a depublication naming the document SHALL be stored.

#### Scenario: An annex with personal data

- **GIVEN** a public publication with a decision and two annexes
- **WHEN** the editor withdraws one annex with the reason "Contains a home address"
- **THEN** the harvester no longer finds that annex in the category sitemap
- **AND** the decision and the other annex are still listed
- **AND** a depublication names the annex, the editor and the reason

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

### Requirement: An object of another schema in a catalog opens on its own detail page (COS-001)

On a catalog's publications page, a row whose `@self` register and schema are not the publication pair SHALL open on the `CatalogObjectDetail` page for that register, schema and id, by a click, the View action, or a ctrl, cmd, shift or middle click in a new tab. This SHALL also hold in a catalog that does not hold the publication pair, and for every row while the publication register and schema ids are still unresolved. A row whose `@self` register or schema is not a numeric id SHALL open in CnIndexPage's form for its own schema instead, except on a middle click, which SHALL do nothing. The page SHALL show the object's properties in a read-only Data card, including free-form object properties whose schema names a `widget`. The object SHALL be edited through the page's Edit button, not inline.

#### Scenario: A component's description can be read per language
- **GIVEN** a catalog over the publiccode schema, holding a component with a Dutch and an English short and long description
- **WHEN** a user clicks the component's row on the catalog's publications page
- **THEN** the browser SHALL be on `/publications/<catalogSlug>/<register>/<schema>/<id>`
- **AND** the Data card SHALL show the Dutch and the English short and long description

#### Scenario: A ctrl click opens the component in a new tab
- **GIVEN** the same catalog
- **WHEN** a user ctrl-clicks the component's row
- **THEN** its detail page SHALL open in a new tab and the list SHALL stay open

#### Scenario: The component is edited in the form, with its description
- **GIVEN** a component's detail page
- **WHEN** a user clicks the page's Edit button
- **THEN** the form SHALL open with a JSON field holding the description per language

#### Scenario: A row without numeric ids opens in the form
- **GIVEN** a row whose `@self` register or schema is a slug instead of a numeric id
- **WHEN** a user clicks the row
- **THEN** the form for its own schema SHALL open and no page SHALL be navigated to

@e2e exclude A stored object always carries numeric register and schema ids in `@self`, so a seeded fixture cannot produce this row; covered by the CatalogPublicationsIndex component test.

#### Scenario: Rows open on the generic page while the publication ids are unresolved
- **GIVEN** the publication register and schema settings are not yet resolved
- **WHEN** a user clicks a row of the catalog
- **THEN** the row SHALL open on the CatalogObjectDetail page

@e2e exclude Needs an instance whose OpenCatalogi settings were never imported; covered by the CatalogPublicationsIndex component test.

### Requirement: The publications page offers the filter and columns sidebar (COS-002)

The Publications page SHALL enable CnIndexPage's sidebar with its search, filters and column toggles, the same as the library's index pages.

#### Scenario: The sidebar is offered on a catalog of components
- **GIVEN** a catalog over the publiccode schema
- **WHEN** a user opens its publications page
- **THEN** the page SHALL offer the sidebar with the filters and columns of the publiccode schema

### Requirement: A catalog without the publication schema lists a default column set (COS-003)

When a catalog does not hold the publication pair, its publications page SHALL list the columns Name, Summary and Updated, from the objects' `@self` metadata, instead of every property of the page's schema. The other properties SHALL stay available as optional columns in the sidebar. The publiccode schema SHALL fill `@self.summary` from the Dutch short description, else the English one. OpenRegister fills it when the object is saved, so a component saved before the schema carried this configuration SHALL have an empty Summary until it is saved again, e.g. by a re-harvest.

#### Scenario: The Componenten catalog lists three columns
- **GIVEN** a catalog over the publiccode schema only
- **AND** a component saved after the OpenCatalogi settings with this change were imported
- **WHEN** a user opens its publications page
- **THEN** the table SHALL have the columns Name, Summary and Updated
- **AND** the component's Summary SHALL be its Dutch short description

### Requirement: A slug held by more than one publication in a catalogue MUST be refused (PUB-019)

The system MUST NOT return a publication for a slug that matches more than one publication across the catalogue's registers and schemas. It MUST answer HTTP 404 with the
existing "Publication not found" body, and MUST log a warning that names the catalogue,
the slug and the UUIDs of the matching publications. The response MUST NOT contain those
UUIDs.

**Priority:** Must **Status:** Implemented

#### Scenario: Two schemas of one catalogue share a slug

- GIVEN catalog "woo" spans schemas "convenanten" and "adviezen"
- AND a publication in each holds slug "2026-0042"
- WHEN a GET request is made to `/api/woo/2026-0042`
- THEN the response MUST be HTTP 404
- AND a warning MUST be logged naming catalog "woo", slug "2026-0042" and both UUIDs
- AND the response body MUST NOT contain either UUID

#### Scenario: The UUID of one of them still resolves

- GIVEN the two publications above
- WHEN a GET request is made to `/api/woo/{uuid of the first}`
- THEN that publication MUST be returned with HTTP 200

### Requirement: Slug resolution MUST be backwards compatible (PUB-020)

Resolving by slug MUST NOT change any behaviour for a UUID URL, for a publication without a slug, or for a catalogue without registers and schemas.

**Priority:** Must **Status:** Implemented

#### Scenario: UUID URL unchanged

- GIVEN a publication reachable at `/api/{catalogSlug}/{uuid}` before this change
- WHEN the same URL is requested after this change
- THEN the response MUST be identical in status and body

#### Scenario: A numeric slug is never treated as an object id

- GIVEN a publication whose slug is "123456" and another object with numeric id 123456 in the same register
- WHEN a GET request is made to `/api/{catalogSlug}/123456`
- THEN the publication holding slug "123456" MUST be returned
- AND the object with numeric id 123456 MUST NOT be returned through the slug route

#### Scenario: Publication without a slug

- GIVEN a publication without a slug (its source has no `kenmerk`, or it was not backfilled)
- WHEN it is requested by its UUID
- THEN it MUST be returned as before

#### Scenario: Catalogue without registers or schemas

- GIVEN a catalogue with no registers or no schemas configured
- WHEN any identifier is requested
- THEN the response MUST be what it was before this change (no fallback search)

### Requirement: Example publications are demo data, installed only on request (REQ-EPD-001)

The publication register imported on install MUST NOT carry example publications, documents or depublications. Example rows MUST live in the demo dataset, which is installed only when an administrator asks for it (ADR-111).

#### Scenario: A municipality's public search after a fresh install
- GIVEN a fresh install where nobody installed the demo data
- WHEN a visitor searches the public publications for "Voorbeeld"
- THEN no example publication or document is found
- @e2e exclude pinned by `PublicationStateServiceTest::testTheInstalledRegisterCarriesNoExamplePublications`; the bundled register is not a browser surface

#### Scenario: The demo data shows Publish again
- GIVEN an administrator installed the demo data
- WHEN they open the withdrawn example publication
- THEN it is withdrawn, has its depublication, and offers Publish again
- @e2e exclude pinned by `PublicationStateServiceTest::testTheDemoDataCarriesAWithdrawnPublicationAndItsDepublication`

### Requirement: A field an administrator adds to the publication schema shows on the publication form (REQ-CVS-001)

When an administrator adds a property to the publication schema in
OpenRegister's schema editor, the publication form on the Publications page
MUST offer that property, and saving MUST store it on the publication. The
public publication endpoint MUST serve it like the shipped properties. This
MUST need no change to OpenCatalogi's code or manifest.

#### Scenario: An administrator adds a case number
- **GIVEN** an administrator who adds the text property `zaaknummer` to the publication schema in OpenRegister
- **WHEN** an editor opens Add on the Publications page of catalogue "Woo-publicaties"
- **THEN** the form offers the field `zaaknummer`
- **AND** a publication saved with `zaaknummer` "Z-2026-00871" returns that value from `GET /api/woo-publicaties/{id}` once it is public

#### Scenario: An added field that is required
- **GIVEN** the same property, marked required in the schema
- **WHEN** an editor saves a publication without it
- **THEN** the save is refused and the form names `zaaknummer`

### Requirement: Only publishers change whether a publication is public (REQ-OPR-001)

Changing `publicationDate` or `depublicationDate` on a publication SHALL be allowed only for members of the group `opencatalogi-publishers` and for administrators. Editing any other property SHALL keep the rights it has today.

#### Scenario: An editor tries to publish

- **GIVEN** an editor who is not in `opencatalogi-publishers`
- **WHEN** the editor saves a publication with a new `publicationDate`
- **THEN** the save is refused with an error that names the property
- **AND** the publication is not public

#### Scenario: An editor edits a title

- **GIVEN** the same editor
- **WHEN** the editor saves a publication with a changed title and an unchanged `publicationDate`
- **THEN** the save succeeds

### Requirement: The screen offers publish actions only to publishers (REQ-OPR-002)

`GET /api/publications/{id}/visibility` SHALL include `canPublish`, true for a publisher or an administrator. The Publish now, Withdraw and Publish again actions SHALL be hidden when it is false.

#### Scenario: An editor opens a draft

- **GIVEN** an editor outside the publishers group on a draft publication
- **WHEN** the editor opens the Actions menu
- **THEN** no Publish now action is listed

### Requirement: The publish endpoints refuse a caller without the right (REQ-OPR-003)

`POST /api/publications/{id}/publish` and `POST /api/publications/{id}/withdraw` SHALL answer 403 for a caller who is neither a publisher nor an administrator, with a message that names the group.

#### Scenario: A direct call by an editor

- **GIVEN** an editor outside the group
- **WHEN** the editor calls `POST /api/publications/{id}/withdraw`
- **THEN** the response is 403 and names `opencatalogi-publishers`

### Requirement: The group exists and the administrator can see it (REQ-OPR-004)

The app SHALL create the group `opencatalogi-publishers` when it is missing, on install and on repair, without adding members. The Woo section of the admin settings SHALL show the group and its member count.

#### Scenario: A repair on an existing instance

- **GIVEN** an instance without the group
- **WHEN** the repair step runs twice
- **THEN** the group exists once and has no members
- **AND** the settings section shows 0 members

### Requirement: Publication and catalogue text is translatable (REQ-PTR-001)

The `publication` and `catalog` schemas SHALL declare `title`, `summary` and `description` as `translatable: true` with `sourceLanguage` `nl`. OpenCatalogi SHALL store and read translations only through OpenRegister.

#### Scenario: The schema declares the fields
<!-- @e2e exclude Schema contract; proven by PublicationTranslationsSchemaTest::testTheThreeFieldsAreTranslatable. -->
- **WHEN** the publication register is imported
- **THEN** `title`, `summary` and `description` of both schemas are translatable with source language `nl`

### Requirement: The public API answers in the visitor's language (REQ-PTR-002)

The public publication and catalogue endpoints SHALL pass the language from `_lang` or `Accept-Language` to OpenRegister, SHALL answer each translatable field in that language when a translation exists and in Dutch when it does not, and SHALL set `Content-Language`. An invalid language tag SHALL be served in Dutch, not refused. The DCAT feed SHALL write each language present as its own language-tagged literal.

#### Scenario: An English visitor reads a partly translated publication
- **GIVEN** a public publication with an English title and summary and only a Dutch description
- **WHEN** a visitor requests it with `Accept-Language: en`
- **THEN** the title and summary are English and the description is Dutch

#### Scenario: A nonsense tag does not break the read
<!-- @e2e exclude Negotiation edge; proven by PublicationQueryServiceTest::testAnInvalidTagFallsBackToDutch. -->
- **WHEN** a visitor requests a publication with `?_lang=xx-!!`
- **THEN** the answer is 200 in Dutch

### Requirement: An editor writes a translation on the edit page (REQ-PTR-003)

The publication and catalogue edit pages SHALL offer a language switch with Dutch as source and every language enabled in the settings. In another language, saving SHALL write only the translatable fields for that language, and the other fields SHALL be read-only. An outdated translation SHALL be marked on its field.

#### Scenario: Adding an English summary
- **GIVEN** a Dutch publication and English enabled in the settings
- **WHEN** the editor switches to English, types a summary and saves
- **THEN** the English summary is stored as a translation and the Dutch summary is unchanged

### Requirement: The publication page shows what is translated (REQ-PTR-004)

The publication page SHALL show a Languages card listing each enabled language as source, complete, or with the fields that are missing or outdated, and SHALL offer to open the editor in that language.

#### Scenario: A missing description is named
- **GIVEN** a publication with English title and summary and no English description
- **WHEN** the editor opens the publication page
- **THEN** the Languages card reads "Title and summary translated, description missing" for English

### Requirement: An editor creates a publication with a title, summary, description and themes (REQ-CRR-001)

An editor MUST be able to create a publication from the Publications page of a
catalogue. The form SHALL offer a title, a summary, a description and one or
more themes. The themes are the publication's category. A saved publication
MUST be listed in its catalogue as a draft until it is published.

#### Scenario: An editor adds a publication

- **GIVEN** an editor on the Publications page of catalogue "Woo-publicaties"
- **WHEN** they choose Add, enter the title "Besluit subsidie buurthuis", a summary and a description, pick the theme "Welzijn" and save
- **THEN** the catalogue lists "Besluit subsidie buurthuis" as a draft
- **AND** opening it shows the summary, the description and the theme "Welzijn"

#### Scenario: A publication without a title is not saved

- **GIVEN** the same form with an empty title
- **WHEN** the editor saves
- **THEN** the save is refused and the title field says it is required

### Requirement: A draft that was never released is deleted permanently with everything on it (REQ-PLC-004)

`DELETE /api/publications/{id}/draft` SHALL delete permanently a publication whose stored state is `draft` or `in_review` and that was never released, together with its attached files, its document references, its `depublication`, `publicationProcess` and `wooAssessment` records that point at it, and any suggestion records that point at it. "Never released" SHALL mean: `firstReleasedAt` empty, no `depublication` record names it, and the audit trail of the object holds no entry in which `status` was `published`. Any one of those that holds, or an audit trail that cannot be read, SHALL refuse the delete with 409 and the reason. The caller SHALL need the update right on the publication. Before removing anything the service SHALL list every object and file it will remove. It SHALL then remove them, with OpenRegister's `ObjectService::deleteObject(..., permanent: true)` for objects, and write one audit entry through `AuditTrailMapper::createAuditTrail()` with action `publication.draft.purged`, the publication's title and id, and the list. When a removal fails part way it SHALL stop, keep the publication, and answer 500 naming what was already removed.

#### Scenario: A draft and everything on it goes

- **GIVEN** a draft publication that was never released, with two files and one document reference
- **WHEN** an editor chooses Delete permanently on its page and confirms
- **THEN** the publication, both files and the reference are gone and cannot be restored
- **AND** one audit entry `publication.draft.purged` lists the publication and the three removed items

#### Scenario: Anything ever released is refused
<!-- @e2e exclude Fail-closed refusal; proven by DraftPurgeServiceTest::testAPublicationThatWasEverPublishedIsRefused, DraftPurgeServiceTest::testAnUnreadableAuditTrailRefuses. -->

- **GIVEN** a publication in state `draft` that was published last month and retracted
- **WHEN** `DELETE /api/publications/{id}/draft` is called
- **THEN** the answer is 409 naming the earlier release
- **AND** nothing is removed

#### Scenario: A failure part way keeps the publication
<!-- @e2e exclude Failure path; proven by DraftPurgeServiceTest::testAFailurePartWayKeepsThePublicationAndNamesWhatWent. -->

- **GIVEN** a draft with two files, where deleting the second file fails
- **WHEN** the delete runs
- **THEN** the publication still exists and the answer names the first file as removed

### Requirement: An officer orders a publication's documents and the public list keeps that order (REQ-PDP-001)

The publication schema SHALL carry `attachmentOrder`, an array of file ids as strings. The publication page SHALL show the attachments with Move up and Move down buttons on each row, reachable and operable by keyboard, each with an accessible name naming the document, announcing the new position through a live region. Saving SHALL write `attachmentOrder`. `PublicationService::attachments()` and `FederationController::publicationAttachments()` SHALL answer the files in `attachmentOrder` order, then files not in it by upload time, each with `position` from 1. An id in `attachmentOrder` that is no longer attached SHALL be skipped.

#### Scenario: An officer orders the documents by keyboard and the portal gets that order

- **GIVEN** a public publication with the documents "Bijlage 2", "Besluit" and "Bijlage 1" in upload order
- **WHEN** the officer moves "Besluit" to the top and "Bijlage 1" above "Bijlage 2" with the keyboard and saves
- **THEN** `GET /api/{catalogSlug}/{id}/attachments` answers "Besluit", "Bijlage 1", "Bijlage 2" with positions 1, 2 and 3

#### Scenario: A new upload goes last
<!-- @e2e exclude Service ordering rule; proven by AttachmentOrderTest::testAFileNotInTheOrderComesLast, which fails on today's code because no order is read. -->

- **GIVEN** a publication with `attachmentOrder` for two files and a third file uploaded later
- **WHEN** the attachments are listed
- **THEN** the third file has position 3

### Requirement: A document has a stable id and its own metadata (REQ-PDP-002)

Each attachment in the public and federation lists SHALL carry `documentId` (the Nextcloud file id as a string, unchanged by a rename), `title`, `format`, `size`, `published`, `downloadUrl`, `documentsoort` and `creationDate` when stored, and `metadataUrl` and `diwooUrl`. `GET /api/{catalogSlug}/{id}/documents/{documentId}` (public, CORS) SHALL answer that document's metadata with `publication: {id, title, url}`. `GET /api/{catalogSlug}/{id}/documents/{documentId}/diwoo` SHALL answer its `diwoo:Document` as XML, rendered by `SitemapService::mapDiwooDocument()`. Both SHALL answer 404 for a document that is not a public attachment of a publicly readable publication. This is the contract `portaliq/publication-detail-page-complete` reads.

#### Scenario: A document's own metadata links back
<!-- @e2e exclude Public API contract for the portal; proven by PublicDocumentControllerTest::testADocumentAnswersItsMetadataAndItsPublication, which fails on today's code because the route does not exist. -->

- **GIVEN** a public publication with a public file
- **WHEN** a client asks `GET /api/{catalogSlug}/{id}/documents/{documentId}` and its `/diwoo` twin
- **THEN** the first answers the file's metadata with the publication's id, title and URL
- **AND** the second answers one `diwoo:Document`

#### Scenario: A withdrawn file is not served
<!-- @e2e exclude Fail-closed path; proven by PublicDocumentControllerTest::testAWithdrawnFileIs404. -->

- **GIVEN** a public publication whose file was withdrawn
- **WHEN** a client asks for that document
- **THEN** the answer is 404

### Requirement: The publication carries its comment period's state (REQ-PDP-003)

The public publication response and `GET /api/federation/publications/{id}` SHALL carry `commentPeriod`, the `CommentPeriodService::publicView()` of the publication's comment period (state `upcoming`, `open` or `closed`, the dates, the remedy, and the reaction form URL only while open), when the publication has one. When `state()` refuses unreadable dates, the response SHALL carry `commentPeriodError: "unreadable-dates"` and no `commentPeriod`. This is the contract `portaliq/publication-detail-page-complete` reads for 7.20.

#### Scenario: An open period on the federation endpoint
<!-- @e2e exclude Public API contract for the portal; proven by FederationControllerTest::testThePublicationCarriesItsOpenCommentPeriod, which fails on today's code because the endpoint carries no period. -->

- **GIVEN** a public publication with a comment period open until next Friday
- **WHEN** a client asks the federation endpoint for it
- **THEN** `commentPeriod.state` is `open` with next Friday as end and the reaction form URL

### Requirement: A catalogue may show that documents were withheld and why (REQ-PDP-004)

The catalogue schema SHALL gain `showWithheld` (boolean, default `false`). `wooAssessment` SHALL gain `titlePublic` (boolean, default `false`). When the publication's catalogue has `showWithheld` true, the public publication response SHALL carry `withheld: [{position, grounds, title?}]` for every `wooAssessment` with `assessment` `niet_openbaar` in the batch whose `wooPublication.publication` is this publication, with `grounds` the stored `weigeringsgronden` article references, and `title` only when `titlePublic` is true. Nothing else of the assessment SHALL be returned. When `showWithheld` is false or the catalogue cannot be resolved, `withheld` SHALL be absent. This is the contract `portaliq/publication-error-reports-and-withheld-notices` reads for 6.16.

#### Scenario: A catalogue that opted in shows the withheld documents
<!-- @e2e exclude Public API contract; proven by WithheldDocumentsTest::testAnOptedInCatalogueListsWithheldDocumentsWithGroundsOnly. -->

- **GIVEN** a catalogue with `showWithheld` true and a publication from a batch with one document withheld on art. 5.1 lid 2 sub e, its title not public
- **WHEN** a reader fetches the publication
- **THEN** `withheld` holds one entry with its position and the ground, and no title

#### Scenario: By default nothing is shown
<!-- @e2e exclude Fail-closed default; proven by WithheldDocumentsTest::testWithoutOptInThereIsNoWithheldKey, which fails if the key is ever present by default. -->

- **GIVEN** a catalogue that did not opt in
- **WHEN** a reader fetches the same publication
- **THEN** the response has no `withheld` key

### Requirement: A catalogue may take error reports from anyone, throttled and moderated (REQ-PDP-005)

The catalogue schema SHALL gain `acceptErrorReports` (boolean, default `false`) and `errorReportModerators` (Nextcloud group ids). A schema `errorReport` SHALL hold `publication`, `message` (at most 2,000 characters), `contact` (optional e-mail), `status` (`new`, `handled`, `dismissed`) and `createdAt`, with no public read rule. `POST /api/publications/{id}/error-reports` (public, `#[AnonRateLimit(limit: 5, period: 3600)]`) SHALL store a report and answer `{reference}` when the publication is publicly readable and its catalogue has `acceptErrorReports` true with at least one moderator group; otherwise it SHALL answer 404. It SHALL store no IP address. The `errorReport` schema SHALL declare an `x-openregister-notifications` rule on `created` to the moderator groups. The officer side SHALL list reports per catalogue with their status. This is the contract `portaliq/publication-error-reports-and-withheld-notices` posts to for 6.15.

#### Scenario: A report reaches the moderators

- **GIVEN** a catalogue that accepts error reports with moderator group `woo-redactie`
- **WHEN** an anonymous visitor posts "De bijlage hoort bij een ander besluit" on a public publication
- **THEN** an `errorReport` with status `new` is stored and the answer carries its reference
- **AND** members of `woo-redactie` get a notification

#### Scenario: Not opted in, or too many
<!-- @e2e exclude Fail-closed path; proven by ErrorReportControllerTest::testWithoutOptInTheRouteIs404 and ErrorReportControllerTest::testTheSixthReportInAnHourIsThrottled. -->

- **GIVEN** a catalogue that did not opt in
- **WHEN** a visitor posts a report
- **THEN** the answer is 404 and nothing is stored







### Requirement: A corrected publication keeps its earlier public versions reachable (REQ-PWA-001)

A schema `publicationVersion` SHALL be added in `lib/Settings/register.d/publication-withdrawal-aftercare.json` with `publication` (uuid), `version` (integer, from 1), `supersededAt` (date-time), `fields` (object) and `documents` (array of `{title, format, fileId}`), and no public read rule. A listener on OpenRegister's `ObjectUpdatingEvent` for the publication schema SHALL, when the old object is publicly readable (`PublicationStateService::stateOf()` answers `public`) and a field the public read returns, or the set of public files, changes, call `PublicationVersionService::snapshot(array $old)`. The snapshot SHALL store the old public projection in `fields`, copy each public file onto the `publicationVersion` object with OpenRegister's `FileService::copyFile(ObjectEntity $source, int $fileId, ObjectEntity $target)`, and set `supersededAt` to now. When the snapshot fails, the update SHALL be refused, so a correction never erases a public version unrecorded.

`GET /api/{catalogSlug}/{id}/versions` SHALL list `{version, supersededAt, url}` for the publication's snapshots, and `GET /api/{catalogSlug}/{id}/versions/{version}` SHALL answer the snapshot's `fields`, its documents with public download URLs, `superseded: true` and the URL of the current version. Both SHALL be public routes and SHALL answer 404 unless the publication itself is publicly readable at that moment, checked through the same public read path as `PublicationsController::show()`. The current public response SHALL carry `versions` with the list URL.

#### Scenario: The cited version is still there
<!-- @e2e exclude Public API contract; proven by PublicationVersionControllerTest::testACorrectionKeepsTheEarlierVersionAtItsOwnUrl, which fails on today's code because no version route exists. -->

- **GIVEN** a public publication titled "Besluit parkeren 2026" with one document
- **WHEN** an editor corrects the title to "Besluit parkeren binnenstad 2026" and replaces the document
- **THEN** `GET /api/{catalogSlug}/{id}/versions/1` answers the old title and the old document, with `superseded: true`
- **AND** the current publication answers the new title and lists version 1

#### Scenario: A version of a withdrawn publication is not served
<!-- @e2e exclude Fail-closed path; proven by PublicationVersionControllerTest::testAVersionOfAPublicationThatIsNoLongerPublicIs404. -->

- **GIVEN** a publication with version 1 that has since been withdrawn
- **WHEN** an anonymous reader asks for version 1
- **THEN** the answer is 404

#### Scenario: A snapshot never carries a hidden field
<!-- @e2e exclude Service rule; proven by PublicationVersionServiceTest::testTheSnapshotHoldsOnlyTheFieldsThePublicReadReturned. -->

- **GIVEN** a public publication with a property the public read does not return
- **WHEN** it is corrected
- **THEN** the snapshot's `fields` do not contain that property

#### Scenario: A failed snapshot refuses the correction
<!-- @e2e exclude Fail-closed path; proven by PublicationVersionListenerTest::testAFailedSnapshotRefusesTheUpdate, built on the real ObjectUpdatingEvent. -->

- **GIVEN** a public publication and a file copy that throws
- **WHEN** an editor saves a correction
- **THEN** the save is refused and the publication is unchanged

### Requirement: A withdrawn publication answers with a tombstone about the withdrawal (REQ-PWA-002)

The `depublication` schema SHALL gain `publicReason` (string) and `tombstoneShowsTitle` (boolean, default `false`). `WithdrawPublicationDialog.vue` SHALL ask for both beside the internal reason. When the public read of a publication by id finds nothing, `PublicationsController::show()` SHALL look, as the system, for a `depublication` whose `publication` is that id, or for a publication with that id in state `archived` with `firstReleasedAt` set. When one exists it SHALL answer 410 with `{id, withdrawnAt, publicReason, title?}`, the title only when `tombstoneShowsTitle` is `true`. It SHALL answer nothing else of the record. When neither exists it SHALL answer 404 as today. The publication remains absent from search, sitemaps, DCAT and federation (RET-001, RET-006).

#### Scenario: A withdrawn link answers with a tombstone
<!-- @e2e exclude Public API status code; proven by PublicationTombstoneTest::testAWithdrawnPublicationAnswers410WithItsTombstone, which fails on today's code because the answer is 404. -->

- **GIVEN** a publication withdrawn yesterday with public reason "Gepubliceerd in strijd met de AVG" and `tombstoneShowsTitle` false
- **WHEN** an anonymous reader requests it by id
- **THEN** the answer is 410 with its id, yesterday's date and that public reason
- **AND** the answer carries no title, summary or document

#### Scenario: A never-public draft stays a 404
<!-- @e2e exclude Fail-closed path; proven by PublicationTombstoneTest::testADraftThatWasNeverPublicStays404. -->

- **GIVEN** a draft that was never public
- **WHEN** an anonymous reader requests it by id
- **THEN** the answer is 404

#### Scenario: The officer chooses what the tombstone says

- **GIVEN** a public publication
- **WHEN** an editor withdraws it with an internal reason, a public reason and Show the title ticked
- **THEN** the depublication stores both reasons and the choice
- **AND** the public link answers 410 with the title and the public reason

### Requirement: A withdrawn publication is frozen with the withdrawal reason (REQ-PWA-003)

The publication schema SHALL declare `x-openregister-archive` with `enabled: true`. `DepublicationService::depublish()` SHALL, after storing the depublication, freeze the publication through OpenRegister's `ArchiveHandler::freeze(identifier: <uuid>, reason: <the internal reason>)`. When the freeze throws, the withdrawal SHALL stand and the answer SHALL carry `frozen: false` with the error; the page SHALL show the publication as not frozen. Every document write inside OpenCatalogi (`EventService::publishObjectAttachments()`, `PublicationStateController::withdrawFile()`, the attachment upload and mass attach paths) SHALL check the publication's frozen marker first and refuse with the message of `ObjectStateWriteException::frozen()`. The publication page SHALL show the marker (who, when, why) and SHALL offer no edit, upload or file action while frozen.

#### Scenario: An edit to a withdrawn publication is refused with the reason
<!-- @e2e exclude Refusal by OpenRegister's save path on a frozen object; proven by DepublicationServiceTest::testWithdrawingFreezesThePublicationWithTheReason and a controller test saving the frozen object, which fail on today's code because nothing freezes. -->

- **GIVEN** a publication an editor withdrew with the reason "Verkeerde bijlage"
- **WHEN** an editor saves a new title for it
- **THEN** the save is refused with a message naming the editor who froze it and the date
- **AND** the stored title is unchanged

#### Scenario: A document write is refused
<!-- @e2e exclude Refusal on file write paths; proven by FrozenPublicationDocumentWriteTest::testAnUploadToAFrozenPublicationIsRefused in opencatalogi, and on OpenRegister's file endpoint by the object-archive-state amendment's own test. -->

- **GIVEN** a withdrawn, frozen publication
- **WHEN** an editor uploads a file to it through OpenCatalogi or through OpenRegister's file endpoint
- **THEN** the upload is refused with the frozen message and no file is added

#### Scenario: The page says why

- **GIVEN** a withdrawn, frozen publication
- **WHEN** an editor opens it
- **THEN** the page shows who froze it, when and why, and offers no edit or upload

#### Scenario: A failed freeze is not hidden
<!-- @e2e exclude Failure path; proven by DepublicationServiceTest::testAFailedFreezeKeepsTheWithdrawalAndSaysSo. -->

- **GIVEN** a freeze that throws
- **WHEN** an editor withdraws a publication
- **THEN** the publication is withdrawn and the answer carries `frozen: false` with the error

### Requirement: A publication carries its public page and its officer page (REQ-PTS-001)

The publication schema SHALL carry two read-only string properties, `publicUrl` and `officerUrl`. A listener on OpenRegister's `ObjectCreatingEvent` for the publication schema, `OCA\OpenCatalogi\Listener\PublicationLinksListener`, SHALL set them through `setModifiedData()`: `publicUrl` from `PublicationLinker::url($uuid)`, and `officerUrl` as the absolute URL of the route `opencatalogi.ui.publicationsPage` with the slug of the first catalogue whose `schemas` include the publication's schema, resolved through `CatalogiService`. When no catalogue lists the schema, `officerUrl` SHALL be the absolute URL of `opencatalogi.ui.publicationsIndex` for the default catalogue setting, or empty when there is none. When `PublicationLinker` can build no public link, `publicUrl` SHALL be empty. A value a caller sends for either property SHALL be ignored.

`PublicationsController::show()` and the publication create path of OpenCatalogi SHALL return both values computed fresh. When the app config `publication_url_template` or `publication_url_catalog` changes, a queued job SHALL rewrite both properties on every publication.

#### Scenario: A handoff gets both pages back
<!-- @e2e exclude Cross-app API contract; proven by PublicationLinksListenerTest::testACreateThroughOpenRegisterReturnsBothPages, built on the real ObjectCreatingEvent, which fails on today's code because no listener sets the properties. -->

- **GIVEN** portaliq is installed and a catalogue `woo` lists the publication schema
- **WHEN** a source system creates a publication through `POST /apps/openregister/api/objects/{register}/{schema}`
- **THEN** the response carries `publicUrl` ending in the portaliq publication page for its uuid
- **AND** `officerUrl` is the absolute URL `/apps/opencatalogi/publications/woo/{uuid}`

#### Scenario: The show response carries both pages
<!-- @e2e exclude API contract; proven by PublicationsControllerTest::testShowCarriesThePublicAndOfficerPages. -->

- **GIVEN** a stored publication
- **WHEN** a caller asks `GET /api/{catalogSlug}/{id}`
- **THEN** the answer carries `publicUrl` and `officerUrl`

#### Scenario: No public link can be built
<!-- @e2e exclude Fail-closed path; proven by PublicationLinksListenerTest::testNoPublicLinkLeavesPublicUrlEmpty. -->

- **GIVEN** no URL template, no portaliq, and an empty `publication_url_catalog`
- **WHEN** a publication is created
- **THEN** `publicUrl` is empty and is not the object's API URL

### Requirement: A publication records whether it is complete and public (REQ-PTS-002)

The publication schema SHALL carry `completeness` (enum `incomplete`, `complete`, default `incomplete`, read-only to callers), `completeAt` (date-time, read-only) and `expectedDocuments` (optional integer, at least 1). `OCA\OpenCatalogi\Service\Publication\CompletenessService::evaluate(array $publication): string` SHALL answer `complete` only when all of these hold: `PublicationStateService::stateOf()` answers `public`; every file OpenRegister's `FileMapper::getFilesForObject()` returns for the publication has a non-empty `share_token` and none is withdrawn; and the number of attached files is at least `expectedDocuments` when that is set. Otherwise it SHALL answer `incomplete`. When a lookup throws, `evaluate()` SHALL throw and the caller SHALL leave the stored value unchanged.

A listener on `ObjectUpdatingEvent` for the publication schema SHALL write the evaluated value, and `completeAt` when it becomes `complete`, through `setModifiedData()`. The file publish and withdraw paths (`EventService::publishObjectAttachments()`, `PublicationStateController::withdrawFile()`) SHALL re-evaluate after they act. A background job `OCA\OpenCatalogi\BackgroundJob\CompletenessSweep`, every 15 minutes, SHALL re-evaluate publications whose `publicationDate` or `depublicationDate` passed since its previous run and save those whose value changed.

#### Scenario: One of three documents is public
<!-- @e2e exclude Service rule; proven by CompletenessServiceTest::testFewerDocumentsThanExpectedIsIncomplete. -->

- **GIVEN** a public publication with `expectedDocuments` 3 and one attached file with a share
- **WHEN** its completeness is evaluated
- **THEN** it is `incomplete`

#### Scenario: A file without a public share
<!-- @e2e exclude Service rule; proven by CompletenessServiceTest::testAFileWithoutAShareIsIncomplete. -->

- **GIVEN** a public publication with two files of which one has no share token
- **WHEN** its completeness is evaluated
- **THEN** it is `incomplete`

#### Scenario: A lookup fails
<!-- @e2e exclude Fail-closed path; proven by CompletenessListenerTest::testALookupFailureLeavesTheStoredValueAsItWas. -->

- **GIVEN** a publication stored as `incomplete` and a file lookup that throws
- **WHEN** it is saved
- **THEN** it is still `incomplete` and a warning is logged

#### Scenario: A scheduled publication completes when its date passes
<!-- @e2e exclude Background job; proven by CompletenessSweepTest::testAPublicationWhoseDatePassedIsMarkedComplete, which fails on today's code because no such job exists. -->

- **GIVEN** a publication with every file shared and a publication date one minute ago, stored as `incomplete`
- **WHEN** the sweep runs
- **THEN** it is stored as `complete` with `completeAt` set

#### Scenario: A withdrawn file makes it incomplete again
<!-- @e2e exclude Service path; proven by CompletenessListenerTest::testWithdrawingAFileMovesBackToIncomplete. -->

- **GIVEN** a `complete` publication
- **WHEN** an editor withdraws one of its files
- **THEN** it is stored as `incomplete`

### Requirement: The source system is told through an OpenRegister webhook (REQ-PTS-003)

OpenCatalogi SHALL NOT send the notification itself. The move of `completeness` from `incomplete` to `complete` SHALL be an OpenRegister object update, so that a webhook subscribed on `ObjectUpdatedEvent` with the filters `{"oldObject.completeness": "incomplete", "newObject.completeness": "complete", "newObject.@self.owner": "<the source's user>"}` receives exactly one CloudEvent per completion. The documentation under `docs/` SHALL give that subscription as a copyable recipe, including the filter to narrow it to one register and schema.

#### Scenario: The source system is told once, when the last document goes public
<!-- @e2e exclude Webhook delivery is server to server; proven by CompletenessWebhookContractTest::testTheFilterMatchesOnlyTheCompletingUpdate, which runs OpenRegister's WebhookService filter over the payloads of three successive updates, and by the live check in tasks 4.2. -->

- **GIVEN** a source system subscribed with the recipe's filters, and a public publication it created with `expectedDocuments` 2 and one shared file
- **WHEN** the second file is attached and shared, and then the title is edited
- **THEN** the subscriber receives one CloudEvent, for the update that made it complete
- **AND** the edit after it delivers nothing

### Requirement: A publication carries a plain language summary with its level (REQ-PPL-001)

The publication schema SHALL carry `plainSummary` (string, at most 1,500 characters), `plainSummaryLevel` (enum `A2`, `B1`, `B2`, default `B1`) and `plainSummaryProvenance` (object, read-only to callers: `generatedBy` `person` or `ai`, `provider`, `at`, `acceptedBy`). A save with `plainSummary` and an empty level SHALL be refused naming `plainSummaryLevel`. The public publication response SHALL carry `plainSummary` and `plainSummaryLevel`. `DcatMappingService` SHALL emit `plainSummary` as `dct:abstract` with the publication's `language` (default `nl`) as its language tag. The publication page SHALL show the field with its level beside the summary.

#### Scenario: An officer writes a B1 summary and the reader sees it

- **GIVEN** a publication "Besluit op bezwaar parkeervergunning"
- **WHEN** an officer writes the plain summary "U kreeg geen parkeervergunning. U maakte bezwaar. De gemeente blijft bij het besluit." at level B1 and saves
- **THEN** the publication page shows it with "B1"
- **AND** the public API answers it with level B1

#### Scenario: The DCAT feed carries it
<!-- @e2e exclude Feed contract; proven by DcatMappingServiceTest::testThePlainSummaryIsEmittedAsAbstractWithItsLanguage, which fails on today's code because no abstract is emitted. -->

- **GIVEN** a public publication with a plain summary and language nl
- **WHEN** the DCAT feed is rendered
- **THEN** its dataset carries `dct:abstract` with that text tagged `nl`

### Requirement: A drafted plain summary is a suggestion an officer accepts (REQ-PPL-002)

`OCA\OpenCatalogi\Service\Publication\PlainLanguageDrafter::draft(string $publicationId): array` SHALL, when `OCP\TaskProcessing\IManager::getAvailableTaskTypes()` lists `core:text2text:simplification`, schedule that task with the publication's title, summary and description, and return `{taskId}`; `GET /api/publications/{id}/plain-summary-draft/{taskId}` SHALL return `{status, text?, error?}`. The caller SHALL need the update right on the publication. `POST /api/publications/{id}/plain-summary` with `{text, level, fromTaskId?}` SHALL store the text and level with provenance `ai` and the provider when `fromTaskId` is given and the text came from that task, else `person`, with `acceptedBy` the caller. When no provider is available the draft route SHALL answer 409 `no-provider`, and the page SHALL hide Draft and say no text assistant is installed. A failed or timed-out task SHALL store nothing.

#### Scenario: A draft is a suggestion until accepted

- **GIVEN** a TaskProcessing provider for simplification and a publication without a plain summary
- **WHEN** the officer chooses Draft plain summary
- **THEN** the page shows the draft as a suggestion and the stored `plainSummary` is still empty
- **AND** after the officer edits and accepts it, it is stored with provenance `ai`, the provider and the officer as `acceptedBy`

#### Scenario: Without a provider
<!-- @e2e exclude Absent provider path; proven by PlainLanguageDrafterTest::testWithoutAProviderTheDraftRouteAnswers409. -->

- **GIVEN** no provider for `core:text2text:simplification`
- **WHEN** the draft route is called
- **THEN** the answer is 409 `no-provider` and nothing is scheduled

#### Scenario: A failed task stores nothing
<!-- @e2e exclude Failure path; proven by PlainLanguageDrafterTest::testAFailedTaskStoresNothing. -->

- **GIVEN** a scheduled draft task that fails
- **WHEN** the officer polls it
- **THEN** the answer carries the error and the publication is unchanged

## Data Model

The publication schema is defined in `publication_register.json`.

| Field | Type | Required | Description |
|-------|------|----------|-------------|
| title | string | Yes | The title of the publication |
| summary | string | No | Brief description of the publication |
| description | string | No | Detailed description of the publication |
| organization | string | No | Reference to the owning organization (facetable) |
| themes | array(string) | No | List of theme references (facetable) |

Note: Publications also inherit OpenRegister system fields (`@self.uuid`, `@self.created`, `@self.updated`, `@self.schema`, `@self.register`, `@self.files`, etc.). Publication visibility is NOT an OR system field: it is governed by the object's own `publicatiedatum`/`depublicatiedatum` fields under OR's RBAC predicate `{group:public, match:{publicatiedatum:{$lte:$now}}}`. The former object-level `@self.published`/`@self.depublished` predicate has been removed from OpenRegister core.

## User Interface

The Nextcloud admin UI provides:
- **PublicationIndex.vue** (`/publications/{catalogSlug}`) - Publications list filtered by catalog
- **PublicationDetail.vue** (`/publications/{catalogSlug}/{id}`) - Single publication detail view
- **PublicationList.vue** - Publication list component
- **PublicationTable.vue** - Tabular publication display
- **PublishPublicationDialog.vue** - Dialog for publishing/depublishing
- **Various object modals** - ViewObject, DeleteObject, MergeObject, LockObject, etc.

## API Endpoints

| Method | Endpoint | Description |
|--------|----------|-------------|
| GET | `/api/{catalogSlug}` | List publications in a catalog (public, paginated, with facets) |
| GET | `/api/{catalogSlug}/{id}` | Get single publication detail |
| GET | `/api/{catalogSlug}/{id}/attachments` | Get publication attachments/files |
| GET | `/api/{catalogSlug}/{id}/download` | Download publication files |
| GET | `/api/{catalogSlug}/{id}/uses` | Get outgoing relations (this publication references) |
| GET | `/api/{catalogSlug}/{id}/used` | Get incoming relations (other objects reference this) |
| OPTIONS | `/api/{catalogSlug}` | CORS preflight |
| OPTIONS | `/api/{catalogSlug}/{id}` | CORS preflight |
| OPTIONS | `/api/{catalogSlug}/{id}/uses` | CORS preflight |
| OPTIONS | `/api/{catalogSlug}/{id}/used` | CORS preflight |
| OPTIONS | `/api/{catalogSlug}/{id}/attachments` | CORS preflight |
| OPTIONS | `/api/{catalogSlug}/{id}/download` | CORS preflight |

All `{catalogSlug}` routes have the requirement `[a-z0-9-]+` and must be placed LAST in `routes.php` to avoid matching specific named routes like `/api/themes`, `/api/glossary`, etc.

## PublicationsController Helper Methods (Gap 22)

### findObjectLocation() - Magic Table Scanning

The `PublicationsController` contains a private `findObjectLocation(string $uuid)` method that scans all OpenRegister magic tables to find which register/schema combination an object belongs to:

1. **Discovery**: Queries `information_schema.tables` for all tables matching `oc_openregister_table_%`
2. **UNION ALL**: Builds a single SQL query that searches all magic tables simultaneously:
   ```sql
   (SELECT 1 AS register_id, 2 AS schema_id FROM oc_openregister_table_1_2 WHERE _uuid = 'abc-123')
   UNION ALL
   (SELECT 1 AS register_id, 3 AS schema_id FROM oc_openregister_table_1_3 WHERE _uuid = 'abc-123')
   ...
   LIMIT 1
   ```
3. **Table name parsing**: Register and schema IDs are extracted from table names via regex: `oc_openregister_table_(\d+)_(\d+)`
4. **Returns**: `{ register: int, schema: int }` or null if not found

This is used as a fallback when the catalog's register/schema combinations don't contain the requested object (e.g., object was moved or catalog config is stale).

### extractFilterValues() - Filter Syntax with [or] Support

The `extractFilterValues(mixed $filter)` method normalizes various filter formats into an array of integer values:

| Input Format | Example | Result |
|-------------|---------|--------|
| Single numeric | `1` | `[1]` |
| Simple array | `[1, 2, 3]` | `[1, 2, 3]` |
| OR operator (array) | `{ "or": [1, 2, 3] }` | `[1, 2, 3]` |
| OR operator (string) | `{ "or": "1,2,3" }` | `[1, 2, 3]` |
| AND operator (array) | `{ "and": [1, 2] }` | `[1, 2]` |
| AND operator (string) | `{ "and": "1,2" }` | `[1, 2]` |
| Comma-separated string | `"1,2,3"` | `[1, 2, 3]` |

This supports the `[or]` query parameter syntax used by frontends: `?schemas[or]=1,2,3`.

## Scenarios

### Scenario: List publications in a catalog
- GIVEN a catalog with slug "publications" exists with schemas [1, 2] and registers [1]
- WHEN a GET request is made to `/api/publications`
- THEN the catalog is resolved by slug (from cache or DB)
- AND ObjectService.searchObjectsPaginated is called with `_schemas: [1, 2]`, `_register: 1`
- AND results include pagination (results, total, page, pages, limit, offset)
- AND `@catalog` metadata is added to the response (slug, title, schemas, registers)
- AND CORS headers are included

### Scenario: Multi-schema catalog strips non-universal ordering
- GIVEN a catalog spanning schemas with different property names (e.g., "name" vs "naam")
- WHEN ordering by a non-universal field is requested
- THEN only universal order fields (uuid, created, updated, published, depublished) are allowed
- AND non-universal order fields are stripped from the query

### Scenario: Get single publication with fallback location
- GIVEN a publication with UUID "xyz-789" exists
- WHEN a GET request is made to `/api/publications/xyz-789`
- THEN the controller first tries the catalog's register/schema combinations (fast path)
- AND if not found, findObjectLocation() searches all magic tables via UNION ALL query (fallback)
- AND if found, renders the entity with `_extend` parameters
- AND returns 404 if not found in any location

### Scenario: Get publication attachments
- GIVEN a published publication with UUID "abc-123" and attached files
- WHEN a GET request is made to `/api/publications/abc-123/attachments`
- THEN the publication is verified to exist in the catalog
- AND PublicationService.attachments() returns the file metadata

### Scenario: Traverse publication relations
- GIVEN a publication "abc" that references objects "def" and "ghi"
- WHEN a GET request is made to `/api/publications/abc/uses`
- THEN ObjectService.getObjectUses() returns "def" and "ghi" with RBAC enabled
- AND register/schema context is set via findObjectLocation for magic table routing

### Scenario: Filter extraction with OR syntax
- GIVEN a request with `?schemas[or]=1,2,3`
- WHEN extractFilterValues() processes the filter
- THEN it returns `[1, 2, 3]` as integer values
- AND these can be used to filter across multiple schemas

## Dependencies

- **OpenRegister ObjectService** - searchObjectsPaginated, searchObjects, find, renderEntity, getObjectUses, getObjectUsedBy, buildSearchQuery
- **CatalogiService** - getCatalogBySlug for catalog resolution and caching
- **PublicationService** - attachments(), download() for file operations
- **IDBConnection** - Direct SQL for findObjectLocation across all magic tables
- **Nextcloud IAppConfig** - Configuration for schema/register mappings

### Requirement: A publication keeps one identifier for life (REQ-CVS-006)

Every publication MUST carry one identifier, its UUID, that never changes. The
public API MUST return it as the publication's `id`, the DCAT feed MUST emit it
as `dct:identifier`, and `GET /api/{catalogSlug}/{uuid}` MUST keep answering for
it after the publication's title, slug or other fields are edited.

#### Scenario: A citation survives an edit
- **GIVEN** a public publication with UUID "4f2c9a1e-7b3d-4e8a-9c51-2d6f0b8e3a17" in catalogue `woo-requests`
- **WHEN** an editor changes its title and slug
- **THEN** `GET /api/woo-requests/4f2c9a1e-7b3d-4e8a-9c51-2d6f0b8e3a17` still returns it with the same `id`
- **AND** its dataset in the DCAT feed still carries that UUID as `dct:identifier`

### Requirement: An embargo refuses every write that would release the record early (REQ-PSG-001)

A publication SHALL be under embargo while its stored `publicationDate` lies after now. A guard, `OCA\OpenCatalogi\Service\Publication\EmbargoGuard`, SHALL refuse, for a caller without the `liftEmbargo` right: the `publish` and `publishWithoutReview` transitions (REQ-PLC-001) and `POST /api/publications/{id}/publish` on a publication under embargo; and any save, through OpenCatalogi or OpenRegister's object API, that sets `publicationDate` earlier than the stored value. The refusal SHALL answer 423 with the code `embargo-holds` and the embargo moment. Moving `publicationDate` later and clearing it SHALL be allowed. The guard SHALL run in a listener on OpenRegister's `ObjectUpdatingEvent` for the publication schema, so every write path meets it, and the controller SHALL check it first to answer with a clear message. When the guard cannot read the stored date or resolve the caller's groups it SHALL refuse.

#### Scenario: Publish now is refused under embargo
<!-- @e2e exclude API refusal; proven by PublicationStateControllerTest::testPublishNowIsRefusedUnderEmbargo, which fails on today's code because publish() refuses only public and archived publications. -->

- **GIVEN** an approved publication with `publicationDate` tomorrow 09:00 and an editor outside `publication_embargo_lift_groups`
- **WHEN** the editor calls `POST /api/publications/{id}/publish`
- **THEN** the answer is 423 with code `embargo-holds` and tomorrow 09:00
- **AND** anonymous readers still get 404 for the publication

#### Scenario: An earlier date is refused under embargo
<!-- @e2e exclude Pre-save refusal on OpenRegister's object API; proven by EmbargoGuardListenerTest::testAnEarlierDateThroughTheObjectApiIsRefused, built on the real ObjectUpdatingEvent. -->

- **GIVEN** a publication with `publicationDate` tomorrow 09:00
- **WHEN** an editor without the right saves it through `PUT /apps/openregister/api/objects/{register}/{schema}/{id}` with `publicationDate` today 08:00
- **THEN** the save is refused with `embargo-holds`
- **AND** the stored `publicationDate` is still tomorrow 09:00

#### Scenario: A later date is allowed
<!-- @e2e exclude Pre-save path; proven by EmbargoGuardListenerTest::testALaterDateIsAllowed. -->

- **GIVEN** a publication with `publicationDate` tomorrow 09:00
- **WHEN** an editor saves it with `publicationDate` next week
- **THEN** the save is stored

#### Scenario: The guard cannot read the caller's groups
<!-- @e2e exclude Fail-closed path; proven by EmbargoGuardTest::testAnUnresolvableCallerIsRefused. -->

- **GIVEN** a publication under embargo and a group lookup that throws
- **WHEN** a publish is attempted
- **THEN** it is refused

### Requirement: Lifting an embargo takes a named right and a reason, and is logged (REQ-PSG-002)

`POST /api/publications/{id}/lift-embargo` with `{reason, publicationDate?}` SHALL be allowed only to a user in a group listed in the app config `publication_embargo_lift_groups` (default `["admin"]`, editable in the publications admin settings). It SHALL require a non-empty reason. It SHALL set `publicationDate` to the given moment, or now, run the publish transition as the lifting user, and write one audit entry through `AuditTrailMapper::createAuditTrail()` with action `publication.embargo.lifted`, the user, the reason, the old and the new date. The publication page SHALL offer Lift embargo only to a user with the right.

#### Scenario: A lift is logged

- **GIVEN** a publication under embargo until next week and a user in `publication_embargo_lift_groups`
- **WHEN** the user chooses Lift embargo, enters the reason "Raad heeft het besluit vervroegd" and confirms
- **THEN** the publication is public
- **AND** its audit trail shows `publication.embargo.lifted` with the user, the reason and both dates

#### Scenario: A lift without the right or without a reason
<!-- @e2e exclude API refusal; proven by EmbargoLiftControllerTest::testALiftByAUserOutsideTheGroupsIsRefused and EmbargoLiftControllerTest::testALiftWithoutAReasonIsRefused. -->

- **GIVEN** a publication under embargo
- **WHEN** an editor outside the groups, or a user in the groups without a reason, calls the lift route
- **THEN** the answer is 403 or 422 and nothing changes

### Requirement: Automatic depublication is shown and the officer is reminded (REQ-PSG-003)

When a publication has a `depublicationDate` in the future, `PublicationVisibilityWidget.vue` SHALL show "Automatic depublication on <date>" and the publications list SHALL show a marker with the date in the state column. A save that sets or changes `depublicationDate` through OpenCatalogi SHALL answer with a `warnings` entry `automatic-depublication` naming the date, which the page shows as a notice. The publication schema SHALL declare an `x-openregister-notifications` rule `depublication-due-soon` with `trigger: {type: "scheduled", intervalSec: 86400, filter: {"depublicationDate": {"operator": "withinNext", "value": "P7D"}}}`, channel `nc-notification`, recipients `object-acl` with permission `manage`, and Dutch and English subjects naming the title and the date. Each publication SHALL be notified once per depublication date.

#### Scenario: Automatic depublication is shown

- **GIVEN** a public publication with `depublicationDate` on 1 December
- **WHEN** an editor opens the publications list and the publication page
- **THEN** the list row carries the marker with 1 December
- **AND** the page shows "Automatic depublication on 1 December"

#### Scenario: The officer is warned at save

- **GIVEN** a public publication without a depublication date
- **WHEN** an editor sets `depublicationDate` to 1 December and saves
- **THEN** the page shows a notice that the publication will be taken down automatically on 1 December

#### Scenario: The officer is reminded before the date
<!-- @e2e exclude Scheduled notification, no deterministic browser surface; proven by DepublicationReminderDeclarationTest::testTheRuleParsesAndMatchesAPublicationDueInFiveDays, which runs OpenRegister's ScheduledFilterParser and ScheduledFilterEvaluator over the shipped rule, and by the live check in tasks 3.3. -->

- **GIVEN** a public publication with `depublicationDate` five days from now and an editor with manage rights
- **WHEN** OpenRegister's scheduled notification job runs twice
- **THEN** the editor has one notification naming the publication and the date

### Requirement: Publishing a record with no documents asks twice (REQ-PSG-004)

`PublishPublicationDialog.vue` SHALL show the number of documents the publication carries: its attached files plus the `documentReference` objects that point at it. With zero it SHALL show a warning that the publication has no documents and SHALL require the editor to tick a confirmation before Publish is enabled. `POST /api/publications/{id}/publish` and the publish transitions run through OpenCatalogi SHALL refuse with 409 `no-documents` when the count is zero and the call does not carry `confirmNoDocuments: true`. When the count cannot be read it SHALL be treated as zero.

#### Scenario: Publishing with no documents asks twice

- **GIVEN** an approved publication with no files and no document references
- **WHEN** the editor opens Publish
- **THEN** the dialog says the publication has no documents and Publish stays disabled until the editor ticks the confirmation
- **AND** after confirming, the publication is public

#### Scenario: A reference counts as a document

- **GIVEN** an approved publication with no files and one document reference
- **WHEN** the editor opens Publish
- **THEN** the dialog shows one document and no warning

#### Scenario: The API refuses without the confirmation
<!-- @e2e exclude API refusal; proven by PublicationStateControllerTest::testPublishWithNoDocumentsIsRefusedWithoutConfirmation and ::testAnUnreadableDocumentCountCountsAsZero. -->

- **GIVEN** an approved publication with no documents
- **WHEN** a caller posts to `/api/publications/{id}/publish` without `confirmNoDocuments`
- **THEN** the answer is 409 `no-documents` and the publication is not public

### Requirement: A publication names its relation to another publication (REQ-PRS-001)

The publication schema SHALL declare `x-openregister-relation-types` with these entries, labels as i18n keys:

| key | label (nl) | inverse label (nl) | symmetric |
|---|---|---|---|
| `replaces` | vervangt | wordt vervangen door | no |
| `amends` | wijzigt | wordt gewijzigd door | no |
| `implements` | geeft uitvoering aan | wordt uitgevoerd door | no |
| `relatedTo` | hoort bij | | yes |

It SHALL declare one array property per key (`replaces`, `amends`, `implements`, `relatedTo`), each item a `$ref` to `#/components/schemas/publication` with `x-openregister-relation: {type: <key>}`. OpenRegister SHALL refuse an item that is not a publication. A publication SHALL NOT relate to itself; a pre-save check SHALL refuse it naming the property.

#### Scenario: Both sides read the relation

- **GIVEN** publication B "Parkeerbesluit 2027" whose `replaces` holds publication A "Parkeerbesluit 2024", both public
- **WHEN** a reader opens B's public detail, and then A's
- **THEN** B's response carries a relation to A labelled "vervangt"
- **AND** A's response carries a relation to B labelled "wordt vervangen door"
- **AND** the officer page of A shows "wordt vervangen door Parkeerbesluit 2027" in its Related panel

#### Scenario: A publication cannot replace itself
<!-- @e2e exclude Pre-save refusal; proven by PublicationRelationCheckTest::testASelfRelationIsRefused, built on the real ObjectUpdatingEvent. -->

- **GIVEN** a publication
- **WHEN** it is saved with its own id in `replaces`
- **THEN** the save is refused naming `replaces`

### Requirement: The public response carries the relations a reader may see (REQ-PRS-002)

`PublicationsController::show()` SHALL add `relations: [{type, label, direction, id, title, url}]` built from OpenRegister's `uses` and `used` rows for the four relation properties, with the label for the direction read. It SHALL include a related publication only when the public read path returns it. `GET /api/{catalogSlug}/{id}/uses` and `/used` SHALL carry the label per row, as OpenRegister answers it.

#### Scenario: A relation to a non-public publication is not shown
<!-- @e2e exclude Fail-closed contract on the public API; proven by PublicationRelationsResponseTest::testARelationToANonPublicPublicationIsLeftOut, which fails on today's code because the response carries no relations. -->

- **GIVEN** a public publication whose `amends` holds a draft
- **WHEN** an anonymous reader fetches the public publication
- **THEN** its `relations` is empty and nothing in the response names the draft

### Requirement: A publication carries its source identifiers, searchable (REQ-PRS-003)

The publication schema SHALL carry `sourceIdentifiers`: an array of `{system (string, required), identifier (string, required), public (boolean, default false)}`. A pre-save listener SHALL mirror a non-empty `caseReference` into `sourceIdentifiers` as `{system: "case", identifier: <caseReference>, public: false}` when no entry with that system and identifier exists, and SHALL never remove an entry. A repair step SHALL do the same once for every stored publication. The internal list endpoints and `GET /api/publications/ready` SHALL accept `sourceIdentifier=<system>:<identifier>` (exact match on both). The public list and search endpoints SHALL accept the same filter and SHALL match only entries with `public: true`, and the public response SHALL carry only those entries.

#### Scenario: A source system finds its record by its own number
<!-- @e2e exclude API contract for a calling system; proven by SourceIdentifierFilterTest::testTheInternalListFiltersOnSystemAndIdentifier, which fails on today's code because the property and the filter do not exist. -->

- **GIVEN** a publication with `sourceIdentifiers` `[{system: "decos", identifier: "Z-2026-0412"}]`
- **WHEN** an authenticated caller lists publications with `sourceIdentifier=decos:Z-2026-0412`
- **THEN** the answer holds exactly that publication

#### Scenario: An internal number stays internal
<!-- @e2e exclude Fail-closed contract on the public API; proven by SourceIdentifierFilterTest::testAPrivateIdentifierIsNeitherReturnedNorMatchedPublicly. -->

- **GIVEN** a public publication with `sourceIdentifiers` `[{system: "decos", identifier: "Z-2026-0412", public: false}, {system: "raad", identifier: "RB-2026-17", public: true}]`
- **WHEN** an anonymous reader fetches it, and searches with `sourceIdentifier=decos:Z-2026-0412`
- **THEN** the response carries only the `raad` entry
- **AND** the search finds nothing

#### Scenario: A dossiq handoff keeps working
<!-- @e2e exclude Cross-app save contract; proven by SourceIdentifierMirrorTest::testTheDossiqPayloadIsMirrored, which saves the payload dossiq's WooPublicationService::buildPayload() produces today. -->

- **GIVEN** dossiq's handoff payload with `caseReference` set to a case uuid
- **WHEN** it is saved as a publication
- **THEN** `sourceIdentifiers` holds `{system: "case", identifier: <that uuid>, public: false}`

### Requirement: The publication declares one lifecycle with named transitions (REQ-PLC-001)

The publication schema in `lib/Settings/publication_register.json` SHALL declare its `x-openregister-lifecycle` on the field `status` with the stored states `draft`, `in_review`, `approved`, `published` and `archived`, initial `draft` and final `archived`. It SHALL declare these named transitions and no other:

| action | from | to | note |
|---|---|---|---|
| `submit` | draft | in_review | |
| `approve` | in_review | approved | |
| `reject` | in_review | draft | declares a required input `reason` |
| `retract` | approved | draft | |
| `publish` | approved | published | sets `firstReleasedAt` to `@now` when empty |
| `publishWithoutReview` | draft | published | allowed only while the app config `publication_review_required` is `false`; sets `firstReleasedAt` when empty |
| `archive` | published | archived | the existing transition, unchanged |

The `status` enum SHALL list the five stored states. `firstReleasedAt` SHALL be a `date-time` property marked `immutable` (OpenRegister REQ-OAS-005), so it is set once and never changed. A list-form edit of `status` that no transition allows SHALL be refused by OpenRegister's `LifecycleValidationListener` with code `lifecycle-invalid-transition`. A named transition not allowed from the current state SHALL be refused by `TransitionEngine::transition()` with a message that names the action and the current state. `GET /apps/openregister/api/objects/{id}/available-actions` SHALL answer the moves allowed from the publication's current state, and `GET /api/publications/lifecycle` SHALL answer the whole transition table above as JSON for a caller that wants it without an object.

#### Scenario: An illegal move is refused by name
<!-- @e2e exclude API contract on OpenRegister's transition route; proven by PublicationLifecycleTest::testAnIllegalMoveIsRefusedByName, which fails on today's code because the schema declares only archive. -->

- **GIVEN** a publication in state `draft`
- **WHEN** a caller posts `{"action": "approve"}` to `/apps/openregister/api/objects/{id}/transition`
- **THEN** the answer is 422 and its message names `approve` and the state `draft`
- **AND** the publication is still `draft`

#### Scenario: A list-form edit that skips review is refused
<!-- @e2e exclude Pre-save refusal by OpenRegister's lifecycle listener; proven by PublicationLifecycleTest::testAListFormEditThatSkipsReviewIsRefused. -->

- **GIVEN** a publication in state `in_review`
- **WHEN** a caller saves it with `status` `published`
- **THEN** the save is refused with code `lifecycle-invalid-transition` naming `status`, `in_review` and `published`

#### Scenario: The allowed moves are published
<!-- @e2e exclude API contract; proven by PublicationLifecycleControllerTest::testTheTransitionTableIsPublished and PublicationLifecycleTest::testAvailableActionsFollowTheState. -->

- **GIVEN** a publication in state `in_review`
- **WHEN** a caller asks its available actions
- **THEN** the answer lists exactly `approve` and `reject`
- **AND** `GET /api/publications/lifecycle` lists all seven transitions with their from and to states

#### Scenario: Publishing without review is closed when review is required
<!-- @e2e exclude Guard on the transition; proven by PublicationLifecycleTest::testPublishWithoutReviewIsRefusedWhenReviewIsRequired. -->

- **GIVEN** `publication_review_required` is `true` and a publication in state `draft`
- **WHEN** a caller runs `publishWithoutReview`
- **THEN** the transition is refused and the publication stays `draft`

### Requirement: The state of a publication is one value the server computes (REQ-PLC-002)

`PublicationStateService::stateOf()` SHALL answer one of `draft`, `in_review`, `approved`, `scheduled`, `public`, `withdrawn` and `archived`. The stored states `draft`, `in_review`, `approved` and `archived` SHALL be answered as stored. For the stored state `published` it SHALL derive `scheduled`, `public` or `withdrawn` from `publicationDate` and `depublicationDate` exactly as it does today. An empty stored `status` SHALL answer `draft`. The publications list (`src/views/publications/`) SHALL show this value as a column and as a facet, and the publication detail page SHALL show it in its header. Both SHALL take it from the server (`GET /api/publications/{id}/visibility` and a `state` key the list endpoint adds per row), never compute it in the browser.

#### Scenario: One state on the list and the page

- **GIVEN** five publications: one `draft`, one `in_review`, one `published` with a publication date next week, one `published` with a publication date last week, and one `published` whose depublication date has passed
- **WHEN** an editor opens the publications list and then each detail page
- **THEN** the list shows Draft, In review, Scheduled, Public and Withdrawn
- **AND** each detail page shows the same state as its row

#### Scenario: A stored draft with a past date is still a draft
<!-- @e2e exclude Server-side derivation; proven by PublicationStateServiceTest::testAStoredDraftWithAPastPublicationDateIsADraft, which fails on today's code because stateOf reads only the dates. -->

- **GIVEN** a publication with stored state `draft` and a publication date last week
- **WHEN** its state is computed
- **THEN** the state is `draft`

### Requirement: A publication is public only in the published state, and every publish path moves the lifecycle (REQ-PLC-003)

The public read rule of the publication schema SHALL require the stored `status` to be `published` in addition to the date conditions (RET-001 as modified). Every path that makes a publication public SHALL run a named transition through `TransitionEngine` instead of writing `publicationDate` alone: `PublicationStateController::publish()`, `EventService::publishObject()`, `BatchPublicationWriter` and `src/modals/object/MassPublishObjects.vue`. Each SHALL run `publish` from `approved`, or `publishWithoutReview` from `draft` while review is not required, and SHALL set `publicationDate` in the same call through the transition's `data`. When the transition is refused, the path SHALL report the refusal and SHALL NOT write `publicationDate`. A batch publish from a Woo batch (`BatchPublicationWriter`) SHALL create the publication in state `approved` and run `publish`, because the batch review is its approval.

#### Scenario: A draft with a past date is not public
<!-- @e2e exclude Read rule on a public endpoint; proven by PublicationLifecycleReadRuleTest::testADraftWithAPastPublicationDateIsNotPublic, which reads the shipped read rule and fails on today's code because the rule matches dates only. -->

- **GIVEN** a publication with stored state `draft` and a publication date last week
- **WHEN** an anonymous reader fetches it on the public API, in search or in a sitemap
- **THEN** it is absent and the direct fetch answers 404

#### Scenario: Publish now moves the lifecycle

- **GIVEN** a publication in state `approved`
- **WHEN** an editor chooses Publish now on its page
- **THEN** its state is `published`, its publication date is now and `firstReleasedAt` is set
- **AND** the page shows Public

#### Scenario: Publish now on an unreviewed draft when review is required
<!-- @e2e exclude Controller refusal; proven by PublicationStateControllerTest::testPublishRefusesAnUnreviewedDraftWhenReviewIsRequired. -->

- **GIVEN** `publication_review_required` is `true` and a publication in state `draft`
- **WHEN** `POST /api/publications/{id}/publish` is called
- **THEN** the answer is 422 naming `publish` and `draft`
- **AND** no `publicationDate` is written

### Requirement: A caller lists exactly the publications ready to publish (REQ-PLC-005)

`GET /api/publications/ready` SHALL answer, for an authenticated caller, the publications whose stored state is `approved`, that the caller may read, as `{results: [{id, title, catalog, caseReference, approvedAt, updated}], total, since}`. It SHALL accept `since` (an ISO 8601 moment; only publications that entered `approved` at or after it) and `caseReference` (exact match, where the schema carries it). It SHALL answer nothing else: no draft, no publication in review, no published one. An anonymous caller SHALL get 401. The route SHALL be declared before any `/api/publications/{id}` route in `appinfo/routes.php`.

#### Scenario: A source system reconciles
<!-- @e2e exclude API contract for a calling system; proven by ReadyPublicationsControllerTest::testOnlyApprovedPublicationsAreListed, which fails on today's code because the route does not exist. -->

- **GIVEN** four publications: one `draft`, one `in_review`, two `approved` of which one entered `approved` yesterday, and one `published`
- **WHEN** an authenticated caller asks `GET /api/publications/ready?since=<today 00:00>`
- **THEN** the answer lists exactly the one approved today, with `total` 1

#### Scenario: An anonymous caller
<!-- @e2e exclude Auth posture; proven by ReadyPublicationsControllerTest::testAnAnonymousCallerIsRefused. -->

- **WHEN** an anonymous caller asks `GET /api/publications/ready`
- **THEN** the answer is 401

### Requirement: A public publication can be unlisted and put back (REQ-PLC-006)

The publication schema SHALL carry a boolean `unlisted`, default `false`. While `unlisted` is `true` the publication SHALL stay readable at its own public link (the public API by id, its attachments and downloads) and SHALL be absent from: `POST /api/publications/search` and every listing endpoint of `PublicationsController`, the DiWoo sitemaps (`SitemapService`), the DCAT feed (`DcatService`), the federation list (`GET /api/federation/publications`) and the OAI-PMH list verbs when that endpoint ships. `PlooiDeliveryService::deliver()` SHALL refuse an unlisted publication and record the reason `unlisted`. The filter SHALL sit in the one place each surface builds its query, not as a post-filter in a view. Unlisting SHALL NOT write a depublication and SHALL NOT send a withdrawal. Clearing `unlisted` SHALL put the publication back on every surface at the next request, and the detail page SHALL offer Unlist and List again to a user with the update right.

#### Scenario: Unlisted, still reachable

- **GIVEN** a public publication that an editor has unlisted
- **WHEN** an anonymous reader searches for its title, and opens its public link
- **THEN** search does not find it
- **AND** its public link still shows it with its files

#### Scenario: Unlisted publications stay off the machine surfaces
<!-- @e2e exclude Public XML and JSON feeds; proven by UnlistedPublicationTest::testAnUnlistedPublicationIsAbsentFromSitemapDcatAndFederation and PlooiDeliveryServiceTest::testAnUnlistedPublicationIsNotDelivered, which fail on today's code because no surface reads the flag. -->

- **GIVEN** a public, unlisted Woo publication
- **WHEN** its category sitemap page, the DCAT feed and the federation list are requested, and PLOOI delivery runs for it
- **THEN** none of them carries it and PLOOI delivery records `unlisted`

#### Scenario: Put back

- **GIVEN** an unlisted public publication
- **WHEN** an editor chooses List again
- **THEN** search finds it and its category sitemap page carries it

### Requirement: Existing publications get a stored state on upgrade (REQ-PLC-007)

A repair step `OCA\OpenCatalogi\Repair\BackfillPublicationLifecycleState`, registered post-migration in `appinfo/info.xml` after `InitializeSettings`, SHALL set the stored state of every publication whose `status` is empty: `published` with `firstReleasedAt` set to its `publicationDate` when a `publicationDate` is set, else `draft`. It SHALL leave `archived` as it is, and it SHALL report the counts. It SHALL be idempotent. It SHALL also rewrite the public read rule on an install where the schema import is skipped because the version did not move, as `WOO536RepairReadRules` does.

#### Scenario: A legacy public publication stays public
<!-- @e2e exclude Repair step; proven by BackfillPublicationLifecycleStateTest::testALegacyPublicationWithADateBecomesPublished. -->

- **GIVEN** a publication with an empty `status` and a publication date last year
- **WHEN** the repair step runs
- **THEN** its stored state is `published`, `firstReleasedAt` is that date, and it is still public

#### Scenario: A legacy publication without a date becomes a draft
<!-- @e2e exclude Repair step; proven by BackfillPublicationLifecycleStateTest::testALegacyPublicationWithoutADateBecomesADraft. -->

- **GIVEN** a publication with an empty `status` and no publication date
- **WHEN** the repair step runs
- **THEN** its stored state is `draft`
