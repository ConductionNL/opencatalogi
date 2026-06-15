# Design: dcat-ap-harvest

## Architecture Overview

A read-only **rendering layer**, exactly like the existing sitemap surface
(woo-compliance) and download surface (download-service): no new storage, no
new state, no new visibility rules. The pipeline is:

```
published objects (OR, @self.published predicate)
        │  searchObjects / zoeken-filteren (same query path as PUB-001)
        ▼
DcatMappingService  ──  x-dcat schema annotation + default mapping
        ▼
DcatSerializer  ──  JSON-LD (default) / Turtle / RDF/XML
        ▼
GET /api/dcat , GET /api/catalogs/{slug}/dcat   (public, CORS, cached)
```

## Key decisions

### 1. Same visibility rule as every other public surface
A dataset appears in the feed **iff** the underlying object is publicly
visible per the `@self.published` predicate — identical to PUB-001/WOO-001.
The DCAT layer never re-decides publication. This means the known
magic-mapping `@self.published` gap automatically heals the feed once fixed
upstream; the feed must not work around it.

### 2. Mapping declared on the schema (`x-dcat`), not in PHP
Mirroring `x-openregister-lifecycle` (APB-SM-001) and
`x-openregister-notifications` (ADR-031), the publication-schema-to-DCAT
field mapping is a schema annotation:

```json
{
  "x-dcat": {
    "class": "dcat:Dataset",
    "mapping": {
      "dct:title": "title",
      "dct:description": "summary",
      "dcat:theme": "category",
      "dct:license": "license",
      "dcat:keyword": "tags[]"
    }
  }
}
```

- Unannotated schemas in a DCAT-enabled catalog fall back to a conservative
  built-in default (title/description/modified/landingPage) so the feed is
  never empty just because annotation lagged.
- `null` / absent `x-dcat` with `"x-dcat": false` opts a schema out entirely.
- Hard-required DCAT-AP-NL properties that the object cannot supply
  (publisher, contactPoint, license default) come from catalog-level
  configuration (admin-settings), falling back to the owning Organisation
  object.

### 3. Stable IRIs
Dataset IRI = the publication's existing canonical public URL
(`…/api/publications/{catalogSlug}/{uuid}` per PUB-002); distribution IRI =
the existing download URL (PUB-007 / download-service). Harvesters dedupe on
IRI stability across runs — reusing the canonical URLs guarantees that for
free and gives harvested portal entries a working landing page.

### 4. Content negotiation, not separate routes
One route per scope; `Accept` header selects `application/ld+json` (default),
`text/turtle`, `application/rdf+xml`. A `?format=` query parameter mirrors the
header for harvesters that cannot set headers (CKAN's dcat harvester can, but
data.overheid.nl's NGR bridge historically struggles). Serialization uses a
small RDF serializer; JSON-LD is generated natively (it is just shaped JSON +
`@context`), Turtle/RDF-XML derived from the same intermediate graph array.

### 5. Pagination for large catalogs
`hydra:PagedCollection` (`hydra:next` / `hydra:previous`) with the same
page-size ceiling as sitemaps (1000, WOO-005). DCAT-AP harvesters (CKAN
`dcat_rdf` harvester) follow hydra paging natively.

### 6. Federation discovery, not a parallel directory
The Listing schema gains an optional `dcatEndpoint` property which
DirectoryService includes when broadcasting/serving the directory — remote
instances and the national directory thereby learn where to harvest each
instance. No new broadcast channel, no new cron: the existing directory sync
carries the field.

### 7. Caching
`Last-Modified` = max `@self.published`-object modification time in scope;
`ETag` over (catalog id, count, max-modified). Harvesters poll daily;
a conditional-GET 304 keeps the endpoint cheap. Response generation is
streaming/iterative like sitemap generation — no full-catalog in-memory graph
for the XML/Turtle paths.

## What is explicitly NOT built (ADR-022)
- No DCAT objects/registers stored in OR — the graph is derived per request.
- No bespoke query layer — `searchObjects` with the catalog's
  register/schema filter, as PUB-003 already specifies.
- No inbound harvesting (OpenConnector's domain).
- No re-derivation of retry/backoff for directory broadcasts (FED-OR-001/002
  already govern that; the new field rides the existing mechanism).

## Validation
Admin-settings gains a "Validate DCAT feed" action that runs the generated
JSON-LD against the bundled DCAT-AP-NL SHACL shapes (or, minimally, a
mandatory-property checklist) and reports per-dataset violations — the same
ux pattern as the existing configuration import/export validation. CI carries
a Newman collection asserting feed shape + mandatory properties; this is an
API-only surface (no Playwright).

## Open questions
1. Exact DCAT-AP-NL version pin (3.0 now; 2.x compatibility flag for
   data.overheid.nl during their migration window?).
2. Whether instance-level `/api/dcat` should also embed remote federated
   catalogs as `dcat:Catalog` references (directory knows them) — proposed:
   yes, as catalog *references* only, never proxied datasets, so harvesters
   harvest each instance at its source.
3. SHACL validation in-process (heavy) vs. checklist validation (light) —
   proposed: checklist in-app, full SHACL in CI only.
