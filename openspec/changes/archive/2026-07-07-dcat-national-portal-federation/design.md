# Design: dcat-national-portal-federation

## Context

`dcat-ap-harvest` (status `done`) already renders a DCAT-AP-NL 3.0 feed over the
OR object-search path. Verified live state at HEAD `4d8b395`:

- `lib/Service/DcatMappingService.php:74` declares
  `'profile' => 'https://data.overheid.nl/dcat-ap-nl/3.0'` in the JSON-LD context.
- `DcatService.php` emits `dcat:Catalog` / `dcat:Dataset` / `dcat:Distribution`,
  publisher fallback (DCAT-005), pagination + ETag (DCAT-008).
- Theme handling is a soft SHOULD only: DCAT-005 says themes "SHOULD be emitted
  as TOOI/overheid-thema taxonomy URIs when the source value maps to one" — no
  binding, no rejection of free-text.
- No HVD, no `applicableLegislation`, no national-portal registration exist
  (grep confirms zero hits for `hvd`/`data.overheid…register`/`ckan` beyond the
  profile URI string).

## Decisions

### D1 — Registration is guidance + validation, not a push endpoint
data.overheid.nl harvests a *registered feed URL* via its CKAN/DONL harvester;
onboarding is an operator step (submit the instance feed URL to the DONL
harvester config). We therefore do NOT model a submit API. Instead:
- the instance document carries the source metadata DONL requires, and
- admin settings display the canonical harvest-source URL (`…/api/dcat`) and a
  "Validate for data.overheid.nl" action that runs the DCAT-010 validator with
  the **DONL profile** rule-set, so the operator registers a feed already known
  to pass. This keeps the change honest (no fictional endpoint) while removing
  the real blocker (an unconformant feed DONL silently drops).

### D2 — HVD is declarative, on schema + catalog, never hard-coded
`x-dcat` (DCAT-004) gains an optional `hvd` block:
```json
"x-dcat": { "hvd": { "categoryProperty": "hvdCategorie",
                     "legislation": "http://data.europa.eu/eli/reg_impl/2023/138/oj" } }
```
Per-object the category comes from the mapped property; a catalog-level default
category covers whole-catalog HVD sets. When no HVD is declared, no HVD triples
are emitted (HVD is opt-in — most WOO publications are not HVD). Category values
are constrained to the six ODD HVD categories via a bundled value list.

### D3 — Theme binding uses a controlled value list, fail-safe
`dcat:theme` resolves through a value list mapping source values → EU MDR
`data-theme` authority URIs
(`http://publications.europa.eu/resource/authority/data-theme/*`) and, for WOO
overlap, `overheid:thema`. An unresolvable source theme MUST NOT be emitted as a
literal string (that is exactly what breaks DONL ingestion and mirrors the
`oc-mapping-literal-leak` failure mode) — it is dropped and reported by the
DCAT-010 validator. This upgrades DCAT-005's SHOULD to a MUST-bind-or-omit.

## Requirement map

| ID | Adds to dcat-ap-harvest |
|----|-------------------------|
| DCAT-NPF-001 | National-portal harvest-source metadata + admin registration/validation affordance |
| DCAT-NPF-002 | Optional HVD category + applicableLegislation, declarative |
| DCAT-NPF-003 | Controlled DCAT theme binding (MDR data-theme), fail-safe (no literal leak) |

## Testing

Newman API assertions against `GET /api/dcat` and
`GET /api/catalogs/{slug}/dcat`: instance source metadata present; an
HVD-declared publication renders `dcatap:hvdCategory` + `dcatap:applicableLegislation`;
a publication with an unmapped theme emits no `dcat:theme` literal; the
"Validate for data.overheid.nl" action reports HVD/theme/source violations.
Pure backend/API (`@e2e exclude`) consistent with the parent spec.
