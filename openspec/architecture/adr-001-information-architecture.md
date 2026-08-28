# ADR-001: Information Architecture — five top-level menus split by question, not by spec

## Status
Accepted

## Date
2026-05-23

## Context

OpenCatalogi is the publication and catalogue surface for governments and
standards bodies: it publishes OpenRegister content as DCAT/OAI-PMH-compatible
catalogues, exposes them through a public Vue SPA with search/filtering and
cross-instance federation, and provides a lightweight CMS layer (pages, menus,
themes, glossary) so a catalogue site can stand alone without an external CMS.

The app implements the Dutch ecosystem standards — GEMMA gegevenscatalogus,
Forum Standaardisatie "pas-toe-of-leg-uit" registratie, NORA-architectuur
publishing, kerngegevensstelsel, Woo-compliance (sitemaps/robots/DIWOO),
eIDAS dienstencatalogus publishing — and feeds OpenRegister content out
through auto-publishing pipelines, downloadable bundles, and federation with
sibling OpenCatalogi instances.

The dual nature (**publish** *and* **browse**) is the IA's central tension:
editors need a publication-management surface, citizens/integrators need a
clean browse surface, and the architect needs the standards-mapping view.

Today the openspec catalogue contains ~20 specs covering catalogs,
publications, search, federation, download, content-management,
auto-publishing, cms-tool, the five standards (GEMMA, NORA, Forum, kerngegevens,
ArchiMate), DCAT/OAI-PMH, Woo-compliance, eIDAS, file-management, dashboard,
admin-settings and prometheus-metrics. Without a shared IA contract these tend
to land as 20 separate menu items, which collapses the navigation under its
own weight as new standards and adapters are added.

Cross-references:
- `/tmp/ia-doc-dec-cat-conn.md` § 3 — the combined IA proposal for docudesk,
  decidesk, opencatalogi and openconnector that this ADR codifies for the
  opencatalogi slice.
- hydra ADR-022 (`Tier 4` manifest-driven UI) — the manifest renders the IA
  shell; this ADR pins which top-level slots the manifest must expose.
- hydra ADR-010 (NL Design) — visual layer; IA is structural.

## Decision

Top-level navigation is fixed at **five** items, each answering one distinct
operator/citizen question. Every spec maps to exactly one *primary* home in
this structure; specs that legitimately span two angles (federation,
auto-publishing) are split deliberately between an authoring-side and an
operations-side home rather than duplicated.

### Top-level menu (5)

1. **Catalogi** — *"what's published?"* — catalog browser: catalogs,
   publications, search, download, federated view.
2. **Inhoud** — *"what's the content?"* — CMS authoring: pages, menus, themes,
   glossary, auto-publishing rules.
3. **Standaarden** — *"which standards do we conform to?"* — mapping &
   registration: GEMMA, NORA, Forum Standaardisatie, kerngegevensstelsel,
   ArchiMate export.
4. **Koppelvlakken** — *"how does it get out?"* — outbound publishing pipelines
   & inbound harvesting: DCAT/OAI-PMH, Woo-compliance, eIDAS, federation
   directory, auto-publishing runtime.
5. **Beheer** — *"who runs this?"* — dashboard, file-management, admin
   settings, CMS-tool (AI agent), Prometheus, federation directory admin.

### Numbered design rules

#### Rule 1 — Publish surface and browse surface are the same registers, different IA

*Catalogi* is the read/browse surface. *Inhoud* and *Koppelvlakken* are the
author/publish surfaces. The underlying publication objects live in
OpenRegister exactly once; never duplicate them into a second list.

**How to apply:** when a publication needs to be opened from the
auto-publishing rule editor or from a Woo-compliance run log, open the
*existing* publication detail page in-context (router navigation with a
breadcrumb back to the origin). Do not build a second publication-list view
inside *Inhoud* or *Koppelvlakken*. The same applies for catalogs and
schemas — one canonical detail page per object, opened from many entry
points.

#### Rule 2 — Standards mapping is a first-class section, not Settings

GEMMA, NORA, Forum Standaardisatie, kerngegevensstelsel and ArchiMate export
are the value proposition for Dutch government — they earn their own
top-level item. They are not toggles on an admin page and they are not tabs
hidden under *Beheer*.

**How to apply:** every new Dutch- or EU-government standard that
OpenCatalogi maps to or registers against gets a dedicated page under
*Standaarden* with the same five tabs (Mapping · Validatie · Publicatie-status
· Bewijslast/evidence · Versies). Conformity scoring and missing-mapping
widgets live here, not on a dashboard. Auth keys for a standard's registry
endpoint still live in *Beheer*; the mapping surface itself does not.

#### Rule 3 — Koppelvlakken is the operator surface for pipelines (in + out); Standaarden is mapping/registration

These two are easy to confuse and must be kept distinct. *Koppelvlakken*
answers "what runs, when, and did it succeed?" — endpoints, schedules,
run-historie, error logs, DLQ-style retry. *Standaarden* answers "are we
compliant and where are the gaps?" — mapping tables, validatie, evidence.

**How to apply:** anything that produces a scheduled HTTP/feed output or
consumes a scheduled inbound feed (DCAT, OAI-PMH, Woo sitemaps, eIDAS
dienstencatalogus) lives in *Koppelvlakken*. Anything that asks "does our
data model conform to X?" lives in *Standaarden*. When in doubt, ask: "would
an integrator open this at 23:00 to debug a failing run?" — if yes, it
belongs in *Koppelvlakken*.

#### Rule 4 — CMS stays light: four content types, no more

*Inhoud* covers exactly four content types — **Pagina's**, **Menu's**,
**Thema's**, **Begrippenlijst/Glossarium** — plus the auto-publishing rule
editor. We do not grow *Inhoud* into a full headless-CMS.

**How to apply:** if a tenant needs richer authoring (long-form articles,
case studies, multi-author workflows, granular permissions), the answer is
"publish a register" — model the content as an OpenRegister schema, expose
it as a publication, render it through the public Vue SPA. New content
types must clear a high bar: they have to be load-bearing for catalogue
publication itself, not a generic CMS feature. The CMS-Tool spec (AI agent
integration) stays in *Beheer* — it is a tool-provider config, not a content
authoring surface, and surfacing it under *Inhoud* would invite editors to
confuse it with the block editor.

#### Rule 5 — Split-by-design specs go in two homes; never duplicate

Two specs legitimately span two angles and are placed in both. **Federation**
appears on the browse side (as a result-augmenter on cross-instance search,
under *Catalogi*) and in admin (peer-instance directory, under *Beheer* /
*Koppelvlakken*) — same feature, two angles. **Auto-publishing** appears
in *Inhoud* (rule authoring, a content-team workflow) and in *Koppelvlakken*
(runtime/run-historie, a pipeline operator's view).

**How to apply:** before adding a third entry point for either, ask whether
the new view is genuinely a different angle (authoring vs. ops, or browse
vs. admin) or whether it is duplication. Only the authoring-vs-operations
and browse-vs-admin splits justify two homes. Three homes is always
duplication.

### Spec-to-placement mapping

| spec_slug | placement | parent |
|---|---|---|
| catalogs | Catalogi > Catalogi lijst | Catalogi |
| publications | Catalogi > Publicaties + Publicatie detail | Catalogi |
| search | Catalogi > Zoeken | Catalogi |
| federation | Catalogi > Federatie-zoekresultaat + Beheer/Koppelvlakken > Federatie directory | split |
| download-service | Catalogi > Publicatie detail > Download tab + global download action | Catalogi |
| content-management | Inhoud > Pagina's/Menu's/Thema's/Begrippenlijst | Inhoud |
| auto-publishing | Inhoud > Auto-publicatie regels + Koppelvlakken > Auto-publicatie | split |
| cms-tool | Beheer > CMS-Tool | Beheer |
| gemma-gegevenscatalogus | Standaarden > GEMMA gegevenscatalogus | Standaarden |
| nora-architectuur-publishing | Standaarden > NORA-architectuur | Standaarden |
| forum-standaardisatie-pas-toe-of-leg-uit | Standaarden > Forum Standaardisatie | Standaarden |
| kerngegevens-stelsel-registratie | Standaarden > Kerngegevensstelsel | Standaarden |
| org-archimate-export | Standaarden > ArchiMate-export | Standaarden |
| dcat-oai-pmh-harvesting | Koppelvlakken > DCAT/OAI-PMH harvesting | Koppelvlakken |
| woo-compliance | Koppelvlakken > Woo-compliance | Koppelvlakken |
| eidas-koppeling-publicatie | Koppelvlakken > eIDAS dienstencatalogus | Koppelvlakken |
| file-management | Beheer > Bestanden | Beheer |
| dashboard | Beheer > Dashboard & Directory | Beheer |
| admin-settings | Beheer > Admin-instellingen | Beheer |
| prometheus-metrics | Beheer > Prometheus | Beheer |

## Consequences

- The Tier 4 manifest (hydra ADR-022) MUST expose exactly the five top-level
  slots above. Adding a sixth requires an ADR superseding this one — not a
  manifest edit in passing.
- New standards (e.g. a future EU interoperability framework registration)
  ship as a new page under *Standaarden* with the five-tab template — no new
  top-level item, no new menu negotiation.
- New publishing pipelines (e.g. a future Peppol-style outbound feed) ship as
  a new page under *Koppelvlakken* with the five-tab endpoint template —
  again no new top-level item.
- The CMS deliberately stays at four content types. Pressure to extend
  *Inhoud* (a tenant asking for "news articles" or "case studies") must be
  redirected to "publish a register" or escalated to a new ADR.
- *Beheer* absorbs every operator-only and ops-endpoint surface (dashboards,
  file overview, AI-tool config, Prometheus, federation directory admin).
  A case-worker or editor should never have a reason to open *Beheer*; if
  they do, that is an IA bug to file, not a feature.
- The split-by-design pattern (federation, auto-publishing) is allowed *only*
  on the authoring-vs-operations and browse-vs-admin axes. Three entry points
  for the same feature is always duplication and must be flagged in review.
- Cross-app consistency: docudesk uses a 4-item shell, decidesk a 6-item,
  openconnector a 5-item — all four apps share the same "primary noun +
  specialised surfaces + single Beheer drawer" skeleton (see
  `/tmp/ia-doc-dec-cat-conn.md` summary). Future Conduction apps SHOULD
  adopt the same skeleton to keep cross-app navigation predictable.

## Evidence

- `/tmp/ia-doc-dec-cat-conn.md:209-297` — full opencatalogi IA section the
  rules in this ADR derive from (purpose, top-level nav, sub-architecture,
  mapping table, implementation phases, design rules, notes on split specs).
- `openspec/specs/` — the ~20 spec directories the mapping table covers.
- hydra `openspec/architecture/adr-022-*` — manifest-driven UI contract that
  consumes the top-level slots defined here.
