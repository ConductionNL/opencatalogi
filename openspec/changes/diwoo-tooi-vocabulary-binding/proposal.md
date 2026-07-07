---
kind: mixed
depends_on: []
---

# Proposal: diwoo-tooi-vocabulary-binding

## Summary

Bind OpenCatalogi's DIWOO sitemap output (`woo-compliance`, WOO-001…010) to the
official Dutch government controlled vocabularies — **TOOI** (Thesauri en
Ontologieën voor Overheidsinformatie) and the **DiWoo waardelijsten** — so the
`diwoo:Document` metadata the national Woo-index (KOOP) harvests is
*Woo-index-valid*, not free text. Three metadata axes that DIWOO already emits
as unconstrained values become value-list-bound and validated:

1. **`diwoo:informatiecategorie`** (WOO-TOOI-001): resolved to an official TOOI
   informatiecategorie URI from the 17-category waardelijst — not the
   free-object `tooiCategorieNaam`/`tooiCategorieUri` pair the mapping trusts today.
2. **`diwoo:publisher @resource`** (WOO-TOOI-002): resolved to a valid TOOI
   *organisatie* identifier URI (`https://identifier.overheid.nl/tooi/id/…`) —
   not the OpenRegister organisation UUID it emits today.
3. **`diwoo:soortHandeling`** (WOO-TOOI-003): resolved to the DiWoo
   soortHandeling waardelijst — not the hard-coded literal `"ontvangst"` it
   emits today for every document.

A bundled reference set of TOOI/DiWoo value lists plus a "Validate DIWOO output"
affordance (WOO-TOOI-004) let publishers see, before KOOP harvests them, which
documents would be rejected.

## Motivation

WOO/open-overheid is the single strongest demand driver for this app in the
Dutch market. Intelligence (Specter) maps the market leaders **Notubiz** and
**iBabs**; Notubiz sells exactly these as premium features:

- **"DiWoo Woo-index koppeling"** (premium)
- **"TOOI thesaurus-binding"** (premium)

The Woo-index (KOOP/DiWoo) does not accept free-text metadata: `publisher`,
`informatiecategorie`, and `soortHandeling` must be URIs drawn from the official
TOOI/DiWoo waardelijsten, or the document is rejected on ingest. Verified live
state at HEAD confirms OpenCatalogi emits unbound values:

- `woo-compliance/spec.md:150-157` maps `diwoo:publisher @resource` to
  `publication.@self.organisation` (an OR UUID), `diwoo:informatiecategorie` to
  loose `publication.tooiCategorieNaam`/`tooiCategorieUri` object fields, and
  `diwoo:soortHandeling` to the constant `"ontvangst"`.
- Nothing validates these against the official value lists (grep: no
  `waardelijst`/`thesaur`/`SKOS`/vocabulary anywhere in specs or `lib/`).

So today's DIWOO sitemaps are *syntactically* correct but *semantically*
unharvestable by the Woo-index whenever an organisation URI, a real handling
type, or an official category URI is required. Binding the three axes to the
value lists is what turns "we emit DIWOO XML" into "our publications appear in
the national Woo-index" — the outcome government publishers actually buy.

## Goals

1. Bundle the TOOI/DiWoo value lists (informatiecategorieën, organisatie-
   identificatoren, soortHandeling) as reference data and resolve the three
   DIWOO axes through them at sitemap render time.
2. Fail safe: a value that cannot resolve to an official URI MUST NOT be emitted
   as a free-text literal — it is omitted and reported, never leaked into the feed.
3. Provide a "Validate DIWOO output" admin/publisher affordance that reports,
   per document, which required axis is unresolved before KOOP harvests it.

## Non-Goals

- **No active push to KOOP/Woo-index.** The national model is harvest-pull of
  the DIWOO sitemaps (WOO-004 robots.txt + sitemaps); this change makes those
  harvestable feeds *valid*, it does not invent a submit API.
- **No new storage or visibility rule.** The value-list bindings are rendering
  concerns over the same OR object-search path; visibility stays the OR
  `publicatiedatum <= now` RBAC predicate.
- **DCAT theme / HVD binding** (the open-data axis) is the sibling change
  `dcat-national-portal-federation`. This change binds only the DIWOO/WOO axes.
- **Document anonymisation/redaction** is already covered by `woo-transparency`
  (Redaction with WOO context, consuming Docudesk) — out of scope here.

## High-Level Approach

Extend the WOO catalog/publication configuration with value-list-backed
resolution for the three axes; `SitemapService::mapDiwooDocument()` looks each
value up in the bundled TOOI/DiWoo value lists and emits the official URI (or
omits + records a violation). The "Validate DIWOO output" action reuses the
sitemap generation path in a dry-run mode that collects per-document violations
rather than serving XML.
