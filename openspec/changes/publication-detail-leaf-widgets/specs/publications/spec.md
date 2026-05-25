---
status: draft
---

# publications Specification (delta)

This delta places OpenRegister integration **leaf widgets** on OpenCatalogi
detail pages via the app manifest (hydra ADR-022, ADR-024 / ADR-036): the **maps
leaf** on geo publications, the **contacts leaf** on the Organisation detail, and
optionally the **photos** and **bookmarks** leaves on publications. These are
net-new consume placements — no bespoke map / contact / photo / bookmark code is
added.

## ADDED Requirements

### Requirement: Maps leaf widget on geo publications (PUB-MAP-001)
The system MUST surface the geometry of a publication's `geo` GeoJSON property by
**placing the OpenRegister maps leaf widget** on the publication detail page via
the app manifest (`detail.config` widgets, ADR-024 / ADR-036) — NOT by building a
bespoke Leaflet/map component in OpenCatalogi (hydra ADR-022). The widget binds to
`publication.geo` and renders points / areas / routes on a map.

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
