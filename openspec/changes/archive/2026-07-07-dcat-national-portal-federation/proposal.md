---
kind: mixed
depends_on: []
---

# Proposal: dcat-national-portal-federation

## Summary

Elevate OpenCatalogi's existing DCAT-AP-NL 3.0 harvest feed
(`dcat-ap-harvest`, DCAT-001…010) from a *passive, unregistered pull feed*
into a first-class harvest source for the national open-data portal
**data.overheid.nl (DONL)** — and, through it, the EU portal
**data.europa.eu**. Three additions land on top of the existing feed, all as
schema-/config-driven rendering over the same OR object-search path (no new
storage, no new visibility rule):

1. **National-portal harvest-source conformance + registration guidance**
   (`DCAT-NPF-001`): the instance-level DCAT document carries the source-level
   metadata DONL's harvester requires, and admin settings expose the feed URL
   plus a validated "register with data.overheid.nl" affordance.
2. **High-Value Dataset (HVD) classification** (`DCAT-NPF-002`): publications
   MAY declare an EU HVD category (Open Data Directive Implementing Regulation
   (EU) 2023/138); when declared, the `dcat:Dataset` emits
   `dcatap:hvdCategory` + `dcatap:applicableLegislation`.
3. **Controlled DCAT theme binding** (`DCAT-NPF-003`): themes are emitted as EU
   MDR `data-theme` authority URIs (and/or `overheid:thema`) resolved through a
   controlled value list, upgrading DCAT-005's soft "SHOULD map to a taxonomy
   URI" into a validated binding so DONL/EU accept and re-federate the feed.

## Motivation

Intelligence (Specter) ranks open-data publication squarely against **CKAN**,
**Socrata**, **Magda**, **Dataportal.se** and the national portal
**data.overheid.nl** itself (a direct competitor row, EUPL-1.2). Their shared
table-stakes are not "having a DCAT feed" — OpenCatalogi already has one
(verified at HEAD: `DcatController` serves `GET /api/dcat` +
`GET /api/catalogs/{slug}/dcat`, DCAT-AP-NL 3.0, profile
`https://data.overheid.nl/dcat-ap-nl/3.0`). The table-stakes are being an
*actually harvested* source of the national catalog:

- **data.overheid.nl** exposes "Harvesting", "DCAT-AP-DONL metadata",
  "High-Value Dataset (HVD) tagging", "Thematic browse (16 categories)" and
  "Federation with EU data.europa.eu" as first-class features. A feed that is
  not registered, does not tag HVD, and emits free-text themes is invisible to
  the national catalog and cannot flow up to the EU portal.
- The EU Open Data Directive makes six HVD categories (Geospatial, Earth
  observation & environment, Meteorological, Statistics, Companies & company
  ownership, Mobility) a legal obligation for public bodies; DONL and
  data.europa.eu filter on them. OpenCatalogi emits none today (verified: no
  `hvd`/`applicableLegislation`/HVD anywhere in `lib/`).

Making the existing feed DONL/EU-harvestable is the smallest change that turns
"we render DCAT" into "our publications appear in the national and EU open-data
catalogs" — the outcome government publishers actually buy.

## Goals

1. Emit the source-level metadata DONL's harvester requires on the
   instance-level `dcat:Catalog` (resolvable `dct:publisher` with a valid TOOI
   organisation URI, `dct:license`, `dcat:contactPoint`, `dct:modified`,
   `foaf:homepage`), and surface the harvest-source URL + a validating
   "register with data.overheid.nl" action in admin settings.
2. Add optional HVD classification (per-publication and per-catalog default)
   that renders `dcatap:hvdCategory` + `dcatap:applicableLegislation` on
   datasets, driven by a controlled HVD value list — no hard-coded per-schema
   PHP.
3. Bind `dcat:theme` to the EU MDR `data-theme` authority (and `overheid:thema`
   where a WOO source value maps), rejecting/omitting unresolvable free-text
   themes rather than leaking literals into the feed.

## Non-Goals

- **No active push API.** DONL and data.europa.eu are harvest-pull consumers;
  this change makes the pull feed conformant and provides registration
  *guidance/validation*, it does not invent a fictional submit endpoint.
- **No new storage or visibility rule.** HVD category and theme are ordinary OR
  object/catalog fields; visibility stays the OR `publicatiedatum <= now` RBAC
  predicate (DCAT-003). No `@self.published` predicate is reintroduced.
- **TOOI *WOO* vocabulary binding** (informatiecategorie, soortHandeling,
  organisation identifiers for DIWOO sitemaps) is the sibling change
  `diwoo-tooi-vocabulary-binding` (extends `woo-compliance`). This change binds
  only the *DCAT* theme axis and the DONL/EU source metadata.
- **Metadata-quality scoring (MQA/FAIR)** is the sibling change
  `publication-quality-metrics`.

## High-Level Approach

Extend the `x-dcat` schema annotation and catalog-level DCAT config
(DCAT-004/DCAT-010) with an optional `hvd` map and a `theme` value-list
reference; `DcatMappingService`/`DcatService` render the extra triples when
present and complete source metadata from the catalog's owning Organisation
(reusing the DCAT-005 publisher fallback chain). Admin settings gain the
harvest-source URL display + a "register with data.overheid.nl" action that
runs the existing DCAT-010 validator against the DONL mandatory-property
profile before handing the operator the feed URL to submit.
