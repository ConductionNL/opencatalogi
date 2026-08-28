---
status: draft
---

# NORA-architectuur publishing

## Placement & Information Architecture

**Placement type:** `SUB_PAGE` — Sub-page beneath a top-level menu entry. Renders as a page inside the parent surface (usually reachable via a router child route or a tab on the parent index page).

**Lives at:** Standaarden > NORA-architectuur / Standaarden

**Rationale:** Standards-mapping page  
_Source: /tmp/ia-doc-dec-cat-conn.md_

> **Implementation note for builders:** Respect the placement above. Do not promote this spec to a top-level menu item, sub-page, or new route unless the placement type explicitly says so. If the placement is `DETAIL_TAB`, `WIDGET`, `ACTION`, `SETTING`, or `INFRA`, the feature must NOT introduce a new entry in the app sidebar. When in doubt, ask before creating a new top-level surface.

## Purpose

Publish organization-specific reference architectures (NORA-style) as first-class catalogs in OpenCatalogi. The Dutch government maintains a layered reference-architecture family: NORA (Nederlandse Overheid Referentie Architectuur) at the national level, with derived domain architectures (GEMMA for gemeenten, PETRA for provincies, WILMA for waterschappen, MARIJ for the Rijk, EAR for executive agencies). Each municipality, province, or executive agency additionally maintains its own organization-specific reference architecture that adopts, profiles, or extends one of these. Today these architectures are published as static HTML wiki sites (nora-online.nl, gemmaonline.nl, petra-online.nl, wilma.werkenmetwilma.nl) with hand-maintained crosslinks, embedded ArchiMate exports, and PDF "vastgestelde versies" stamped by a CIO-board. They are hard to query, hard to version, impossible to mechanically compare across organizations, and impossible to use as automated input to project-architecture reviews or tender requirements.

This spec lets an organization publish its reference architecture as a structured catalog: principes (architecture principles), bouwstenen (building blocks / capabilities), diensten (services), patronen (patterns), and standaarden (standards). Each item gets a stable URI, a SKOS-style relationship graph, machine-readable metadata, and full version history. A browser UI lets architects search across the architecture, browse the principle hierarchy, follow bouwsteen-to-dienst relationships, inspect provenance, and view diffs between vastgestelde versions. An RDF/Turtle export makes the catalog interoperable with linked-data tooling. Mappings link local concepts to their parent in the landelijke (national) NORA-family, so an organization-specific principe can declare "adopts NORA principe AP01" or "profiles GEMMA bouwsteen BS-23" or "contradicts WILMA principe WP07 (justification: regional waterschap context)".

This unlocks four long-standing pain points: (1) architects can finally query their architecture ("which diensten depend on bouwsteen X?", "which principes does this project violate?", "which standaarden are pas-toe-of-leg-uit-verplicht and not yet adopted by us?") instead of grep-ing wiki PDFs and Excel "principes-overzicht v3.4 FINAL DEFINITIEF.xlsx"; (2) cross-organization comparison becomes mechanical — the catalog can show that 60% of gemeente A's principes adopt NORA verbatim, 30% profile, 10% are organization-specific, and which other gemeenten have made similar choices, fuelling Dimpact / VNG Realisatie convergence efforts; (3) tender requirements and project-architecture documents can cite stable URIs ("MUST realize principe https://gemeente.example/architectuur/principe/AP-04") that resolve to machine-readable definitions and update automatically as the architecture evolves; (4) auditors (BIO, ENSIA, Forum Standaardisatie pas-toe-of-leg-uit-toetsing) get a single live source of truth instead of a snapshot PDF that was already out of date the day it was signed.

The spec also formalises the comply-or-explain workflow that Forum Standaardisatie mandates: every verplichte open standaard from the pas-toe-of-leg-uit lijst must either be marked "toegepast" with realising bouwstenen, or "niet toegepast" with a structured belangenafweging text and an expiry date for re-evaluation. The same workflow applies internally to organisation-specific principes that contradict their NORA/GEMMA parent.

## Data Model

Five core schemas:

- **NoraPrinciple** — id, code (e.g. `AP01`), title, statement, rationale, implications[], domain (informatie/applicatie/technologie/governance), parent (hierarchy), adoptsFrom (link to national NORA principle URI), profileOf (link to GEMMA/PETRA principle), status (draft/vastgesteld/vervallen), version, validFrom, validTo, owner, changeLog[].
- **NoraBuildingBlock** (bouwsteen) — id, code, title, description, capability area (zaak/document/identity/integration/data), realizes[] (principles), composedOf[] (other building blocks), standards[] (NoraStandard), maturityLevel, owner, lifecycleStatus.
- **NoraService** (dienst) — id, code, title, description, delivers[] (building blocks), serviceLevel, consumer[] (organization-types), provider, technicalEndpoint (optional), governanceContact, slaURI.
- **NoraStandard** — id, code (e.g. `NEN-3610`, `STUF-ZKN-0310`), title, issuer (Forum Standaardisatie / NEN / ISO / w3c), type (open standard / norm / guideline), status (verplicht/aanbevolen/in onderzoek), forumStandaardisatieURI, comply-or-explain reasoning, alternativeStandards[].
- **NoraPattern** — id, title, problem, solution, consequences, appliesTo[] (building blocks), examples[].

All five inherit common metadata: stable URI under `{organization}/architectuur/{type}/{code}`, SKOS labels (prefLabel, altLabel, definition, scopeNote), DCAT-AP-NL provenance (creator, publisher, issued, modified), and bidirectional relations stored as references (so reverse lookups work via OpenRegister's index).

Mapping table **NoraMapping** records the relationship between a local concept and an upstream one: localUri, upstreamUri, mappingType (adopts/profiles/extends/replaces/contradicts), justification, mappingAuthor, mappingDate.

## Requirements

| ID | Requirement | Priority |
|----|-------------|----------|
| NORA-001 | Five core schemas (principle, building block, service, standard, pattern) with full SKOS metadata | Must |
| NORA-002 | Stable URI scheme `{organization}/architectuur/{type}/{code}` resolvable to JSON-LD and HTML | Must |
| NORA-003 | Version history on every item (status transitions tracked via OpenRegister audit log) | Must |
| NORA-004 | Mapping table linking local concepts to NORA/GEMMA/PETRA/WILMA/MARIJ/EAR upstream URIs | Must |
| NORA-005 | RDF/Turtle export of full catalog using SKOS + DCAT-AP-NL vocabulary | Must |
| NORA-006 | RDF/XML and JSON-LD content negotiation on item URIs | Should |
| NORA-007 | Browser UI: search across all five item types with type-faceted results | Must |
| NORA-008 | Browser UI: principle hierarchy tree (parent/child navigation) | Must |
| NORA-009 | Browser UI: building-block dependency graph (visualizes composedOf + realizes) | Should |
| NORA-010 | Browser UI: change-history timeline per item with diff view between versions | Must |
| NORA-011 | Browser UI: mapping coverage dashboard (% adopted/profiled/extended/local) per domain | Should |
| NORA-012 | Import wizard: bulk-import GEMMA/NORA reference set via SKOS/Turtle file | Should |
| NORA-013 | Comply-or-explain workflow per standard with required-justification text field | Must |
| NORA-014 | SPARQL endpoint over the catalog (read-only) | Could |
| NORA-015 | ArchiMate 3.1 export of building-block + service layer (uses opencatalogi/org-archimate-export) | Should |
| NORA-016 | Public read endpoints (no auth) for principles/standards; editing requires authenticated architect role | Must |
| NORA-017 | Forum Standaardisatie sync: nightly pull of mandatory standards list, flag local non-compliance | Should |

## Standards & Sources

- **NORA (Nederlandse Overheid Referentie Architectuur)** — noraonline.nl, owned by ICTU. Provides the AP (Architectuur Principes), the bouwsteenmodel, the vijflaagse model (Grondslagen / Bedrijfsarchitectuur / Informatiearchitectuur / Applicatiearchitectuur / Technische architectuur) and the metamodel that all derived architectures inherit. Our principle.adoptsFrom URIs resolve to NORA wiki pages.
- **GEMMA 2.x** — gemmaonline.nl (VNG Realisatie). Gemeentelijke referentiearchitectuur. Defines ~120 bedrijfsfuncties, the bouwsteenmodel (Zaken, Documenten, Klantcontact, Basisregistraties, etc.), GEMMA-procesmodellen and the GEMMA-softwarecatalogus that lists product implementations per bouwsteen. Most municipal architectures profile GEMMA rather than directly profile NORA.
- **PETRA** — Provinciale Enterprise Referentie Architectuur (IPO). Provincial reference architecture.
- **WILMA** — Waterschap Informatie- en Logisch Model Architectuur (Het Waterschapshuis). Waterschappen reference architecture.
- **MARIJ** — Model Architectuur Rijksdienst (Rijksbreed). Federal reference architecture.
- **EAR** — Enterprise Architectuur Rijksdienst (executive agencies, ICTU). Derived from MARIJ.
- **HORA** — Hoger Onderwijs Referentie Architectuur (SURF). Used by universities and hogescholen; not strictly "overheid" but increasingly cited.
- **ZIRA** — Ziekenhuis Referentie Architectuur (Nictiz). Used by zorg-overheid intersection.
- **SKOS (Simple Knowledge Organization System)** — W3C recommendation 2009. Provides prefLabel/altLabel/broader/narrower/related/exactMatch/closeMatch/relatedMatch — the relationship vocabulary for cross-architecture mappings, plus scopeNote / definition / historyNote for richer concept documentation.
- **SKOS-XL** — extension for label-as-resource (lets a single concept have multiple equivalent prefLabels, each first-class).
- **DCAT-AP-NL 2.1** — Dutch profile of DCAT. Provides dataset/catalog/distribution metadata. Forum Standaardisatie pas-toe-of-leg-uit-verplicht for government open-data catalogs and a natural fit for publishing the architecture catalog itself as a discoverable dataset.
- **ArchiMate 3.1** (The Open Group) — modeling language used by every Dutch government enterprise architect. Building blocks export as ArchiMate Capability + Business Service elements; principles export as ArchiMate Principle elements; standaarden as ArchiMate Constraint elements.
- **TOGAF 9.2 ADM** — architecture development method that frames how the catalog content is produced (phases B/C/D produce the items the catalog stores).
- **Forum Standaardisatie pas-toe-of-leg-uit lijst** — the verplichte open standaarden list (~50 items: STUF, Digikoppeling, DigiD, eHerkenning, NEN-3610, NL-API-Strategie, BWB, HTTPS+HSTS, IPv6, DNSSEC, etc.) that government MUST adopt or formally explain non-adoption. Published as machine-readable list at forumstandaardisatie.nl with a stable JSON-feed.
- **NEN-ISO/IEC/IEEE 42010** — international standard for architecture description; informs the metamodel choices (viewpoints, concerns, stakeholders).
- **W3C PROV-O** — provenance ontology; used to record who decided which architecture item and when.
- **TOOI** (Thesaurus Overheid Informatie) — controlled vocabularies for Dutch government metadata (organisaties, taxonomieën, locaties); used in catalog publisher fields.
- **DON (Digitale Overheid Norm)** — emerging registry of normen-en-eisen voor digitale overheid; future binding target for principle adoption.

## Cross-app integration

- **OpenRegister** — all five schemas registered with full audit log + version history; status transitions (draft → in-review → vastgesteld → vervallen) flow through the standard OpenRegister state-machine. The mapping table uses OpenRegister's relation index for fast reverse-lookups so "find all principles that adopt NORA AP04" is a single indexed query.
- **OpenCatalogi base** — a NORA catalog is just a typed OpenCatalogi catalog (`catalogType: nora-architecture`), so existing search/listing/federation infrastructure works for free. A municipality's NORA catalog can federate with VNG's GEMMA catalog so "show me all principles" includes both local and national without code changes. The catalog auto-publishing rules apply: a principe marked `status: vastgesteld` becomes visible to the public site immediately.
- **OpenConnector** — used for the Forum Standaardisatie nightly sync (pas-toe-of-leg-uit lijst JSON-feed → local NoraStandard records) and for outbound mapping-URI resolution against noraonline.nl / gemmaonline.nl (which expose linked-data fragments / RDF endpoints). Also used to pull GEMMA-softwarecatalogus product references when a bouwsteen has known implementations.
- **opencatalogi/dcat-oai-pmh-harvesting** — the NORA catalog itself is exposed as a DCAT-AP-NL dataset and an OAI-PMH set, so other organizations can harvest it as a peer architecture and so the architecture appears in data.overheid.nl.
- **opencatalogi/federation** — multiple municipalities can federate their NORA catalogs to enable cross-organization queries ("which 10 gemeenten have already implemented bouwsteen BS-47 from GEMMA 2.5?", "which provincies have moved off STUF-ZKN and onto Zaken-API 1.5?"). Federation results feed Dimpact / VNG Realisatie convergence reporting.
- **opencatalogi/org-archimate-export** — extends to ArchiMate 3.1 export of the architecture layer; principles export as ArchiMate Principle elements, building blocks as Capability + Business Service, standards as Constraint.
- **docudesk** — architecture decisions (ADRs) and standards can be linked from documents; docudesk's metadata-enrichment can auto-tag uploaded design documents with the principles they realize. WOO-published architecture documents stay in sync with the live catalog version.
- **softwarecatalog** — a future use-case: link each bouwsteen to the products in the softwarecatalogus that implement it, so the architecture catalog and the product catalog reinforce each other.
- **nldesign** — UI uses rijkshuisstijl architecture-visualisation patterns (dependency graphs, hierarchy trees) with WCAG AA compliance.
- **mydash** — architecture KPIs (% principles vastgesteld, % standards toegepast, mapping coverage to national NORA, comply-or-explain backlog) surface on the architect dashboard.

## Target users

**Primary:**
- **Enterprise architects** at municipalities, provinces, waterschappen, and executive agencies. They currently maintain reference architectures in Confluence/SharePoint/static HTML and want machine-readable structure + cross-organization comparison.
- **VNG Realisatie / IPO / Het Waterschapshuis / ICTU** as upstream publishers — they publish the national reference architectures (GEMMA, PETRA, WILMA, MARIJ, EAR) and want to track adoption.

**Secondary:**
- **Project architects** who need to demonstrate compliance: "this project realizes principles AP04, AP07, AP12 and uses bouwstenen BS-23 + BS-47".
- **Procurement teams** referencing standards in tenders ("solution MUST comply with NEN-3610 unless comply-or-explain is filed; bouwsteen mapping to GEMMA BS-47 expected").
- **Auditors** (BIO, ENSIA, Forum Standaardisatie pas-toe-of-leg-uit-toetsing) checking that the organization's architecture genuinely covers the mandatory standards.
- **Forum Standaardisatie** monitoring compliance with the pas-toe-of-leg-uit list across the public sector.
- **CIO / CDO** approving the vastgestelde versie of the architecture and reviewing the comply-or-explain backlog quarterly.

Tertiary: vendors and integrators who need to understand a specific organization's architecture before responding to a tender; academic researchers studying inter-organizational architecture convergence; civic-tech developers building tools against open architecture catalogs.
