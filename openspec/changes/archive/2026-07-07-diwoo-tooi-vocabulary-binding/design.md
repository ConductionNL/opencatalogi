# Design: diwoo-tooi-vocabulary-binding

## Context

`woo-compliance` (status `done`) generates DIWOO XML sitemaps that KOOP/DiWoo
is expected to crawl. Verified DIWOO mapping at HEAD
(`openspec/specs/woo-compliance/spec.md:141-157`, `lib/Service/SitemapService.php`):

| DIWOO field | Current source | Problem for Woo-index |
|-------------|----------------|-----------------------|
| `diwoo:publisher @resource` | `publication.@self.organisation` (OR UUID) | not a TOOI organisatie URI |
| `diwoo:informatiecategorie @resource` | `publication.tooiCategorieUri` (free field) | unvalidated; may be blank/wrong |
| `diwoo:soortHandeling` | constant `"ontvangst"` | never reflects the real handling type |

The 17 informatiecategorieën are enumerated in the spec (WOO-003) but only as
sitemap *route codes* (`infocat001…017`), not bound to the object metadata as
official TOOI category URIs.

## Decisions

### D1 — Value lists are bundled reference data, resolved at render
Three value lists ship in the OpenCatalogi register bundle as reference data:
- **informatiecategorieën** — the 17 Woo categories → official TOOI URIs
  (`https://identifier.overheid.nl/tooi/def/thes/kern/…`).
- **organisatie-identificatoren** — a lookup that maps the catalog's / object's
  organisation to its TOOI organisatie URI
  (`https://identifier.overheid.nl/tooi/id/…`); the OR organisation object gains
  a `tooiIdentifier` property that carries the URI.
- **soortHandeling** — the DiWoo handling-type waardelijst
  (`ontvangst`, `vaststelling`, `ondertekening`, …).

`SitemapService::mapDiwooDocument()` resolves each axis through the lists at
render time. No values are hard-coded in PHP beyond the value-list references.

### D2 — Fail safe: bind-or-omit, never leak a literal
If a value cannot be resolved to an official URI (e.g. an organisation without a
`tooiIdentifier`, or a category with no mapping), the axis is omitted and a
per-document violation is recorded — exactly the discipline the
`oc-mapping-literal-leak` gotcha warns about. A partially-valid document still
serves; the operator sees the gap via the validator (D3) rather than shipping an
invalid `@resource` the Woo-index silently drops.

### D3 — "Validate DIWOO output" reuses the render path in dry-run
The validator runs the same `mapDiwooDocument()` path in a mode that collects
`{ documentLoc, axis, reason }` violations instead of emitting XML, surfaced in
admin/publisher settings. Advisory, never a serving gate (consistent with the
DCAT-010 validator pattern in the sibling change).

### D4 — soortHandeling default remains, but becomes overridable + bound
`"ontvangst"` stays the default (backwards-compatible) but is now a *value-list
member*, and a publication/catalog MAY declare a different handling type that
resolves through the soortHandeling list.

## Requirement map

| ID | Adds to woo-compliance |
|----|------------------------|
| WOO-TOOI-001 | `informatiecategorie` bound to the official TOOI category value list |
| WOO-TOOI-002 | `publisher @resource` bound to a TOOI organisatie URI |
| WOO-TOOI-003 | `soortHandeling` bound to the DiWoo handling-type value list |
| WOO-TOOI-004 | Bundled value lists + "Validate DIWOO output" affordance |

## Testing

Newman assertions over the DIWOO sitemap endpoints: a publication with a mapped
category/organisation/handling renders official TOOI/DiWoo URIs; a publication
whose organisation has no `tooiIdentifier` omits `diwoo:publisher @resource` and
appears in the validator report. Pure backend/API (`@e2e exclude`) consistent
with `woo-compliance`.
