# Proposal: dcat-ap-harvest

## Summary
Expose every OpenCatalogi catalog as a machine-readable **DCAT-AP-NL** catalog
document that national and EU open-data portals can harvest. The README already
claims DCAT-AP as the app's metadata standard, but no spec or code provides the
canonical interoperability surface: an RDF (JSON-LD / Turtle / RDF/XML) feed of
`dcat:Catalog` → `dcat:Dataset` → `dcat:Distribution` that data.overheid.nl
(NGR/CKAN harvesters) and data.europa.eu can poll. Sitemaps (woo-compliance,
WOO-001..010) serve KOOP/DIWOO crawlers; they do **not** serve DCAT harvesters.
This change adds the missing harvest endpoint as a thin, read-only rendering
layer over existing published objects.

Per **hydra ADR-022**, this change builds NO storage, NO search, and NO new
publication mechanism:

- **Source of truth** is the existing published-object set: only objects whose
  `@self.published` predicate makes them publicly visible appear in the feed —
  the exact same visibility rule as the public publications API (PUB-001..015)
  and the sitemaps (WOO-001).
- **Querying** delegates to OR (`searchObjects` / `zoeken-filteren`), the same
  path the publications endpoints use. No bespoke query layer.
- **Mapping** publication schemas → DCAT properties is declared on the schema
  (an `x-dcat` annotation, mirroring how `x-openregister-lifecycle` and
  `x-openregister-notifications` declare behaviour on schemas), with a
  conservative built-in default mapping for unannotated schemas.
- **Discovery** extends the existing federation directory (DirectoryService /
  Listing objects) so each listing advertises its DCAT endpoint — extending the
  existing federation machinery, not reinventing it.

## Motivation
For a government open-data / WOO publication catalog, DCAT-AP is *the* exchange
contract: data.overheid.nl, data.europa.eu, and provincial/ministerial
aggregators harvest DCAT-AP-NL — nothing else. CKAN, the de-facto reference
open-data portal, ships DCAT output natively; an OpenCatalogi instance that
cannot be harvested is invisible to the national open-data infrastructure, and
every municipality running it must hand-register datasets at
data.overheid.nl. One public endpoint removes that entire manual chain and
makes the federation network harvestable as a whole.

## Scope
- Public, CORS-enabled, content-negotiated DCAT endpoints:
  `GET /api/dcat` (instance-level `dcat:Catalog` of catalogs) and
  `GET /api/catalogs/{catalogSlug}/dcat` (per-catalog document with datasets).
- DCAT-AP-NL 3.0 mapping: Catalog → `dcat:Catalog`, Publication →
  `dcat:Dataset`, attachment/download URL → `dcat:Distribution`, Organisation →
  `foaf:Agent` publisher; mandatory-property completion and TOOI-register URIs.
- Schema-level `x-dcat` mapping annotation + built-in default mapping.
- Harvester-grade behaviour: stable dataset IRIs, pagination via
  `hydra:PagedCollection`, `Last-Modified`/`ETag` caching headers.
- Directory/listing advertisement of the DCAT endpoint (federation discovery).
- Admin settings: enable/disable per catalog, publisher defaults, feed
  validation action (DCAT-AP-NL SHACL profile).

## Out of scope (consumed, not built)
- Object storage, publication state, RBAC, search — owned by OpenRegister.
- Publication visibility semantics — the `@self.published` predicate is the one
  and only publication mechanism; this feed only *renders* it.
- Harvesting *inbound* DCAT from other portals — that is source synchronization,
  owned by OpenConnector.
- Sitemap/robots surfaces — owned by the existing `woo-compliance` spec.
- Visual portal rendering of datasets — external frontends.

## References
- hydra ADR-022 — apps consume OpenRegister abstractions.
- DCAT-AP-NL 3.0 (geonovum/logius profile of DCAT-AP 3.0), DCAT-AP 3.0 (EU),
  W3C DCAT v3.
- TOOI registers (organisaties, themataxonomie) for publisher/theme URIs.
- Existing specs: `publications` (public API + visibility), `woo-compliance`
  (sitemaps — adjacent but distinct surface), `federation` (directory/listing
  machinery being extended), `admin-settings`.
