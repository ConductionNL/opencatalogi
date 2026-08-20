# Proposal: nora-architectuur-publishing

## Summary
Publish organization-specific reference architectures (NORA-style) as first-class catalogs in OpenCatalogi, enabling enterprises to store, version, search, and compare structured architectures (principes, bouwstenen, diensten, standaarden, patronen) with stable URIs, SKOS metadata, and compliance tracking.

## Motivation
Dutch government organizations maintain reference architectures following the NORA (Nederlandse Overheid Referentie Architectuur) family: national NORA, GEMMA (municipal), PETRA (provincial), WILMA (waterschap), MARIJ/EAR (federal). Today these are published as static HTML wikis (nora-online.nl, gemmaonline.nl, etc.) that are difficult to query, version-track, mechanically compare, or use as automated input to architecture reviews. This creates four pain points:

1. **No querying**: Architects grep wiki PDFs instead of asking "which diensten depend on bouwsteen X?" or "which principes does this project violate?"
2. **No comparison**: Cross-organization comparisons are manual instead of mechanical (e.g., "60% of gemeente A's principes adopt NORA verbatim, 30% profile, 10% are local")
3. **No automation**: Tenders cannot cite stable URIs that resolve to machine-readable definitions; project docs cannot auto-update with architecture changes
4. **No compliance visibility**: Auditors (BIO, ENSIA, Forum Standaardisatie) see snapshots instead of live sources; comply-or-explain workflows are unstructured

## Scope
- **Five core schemas**: NoraPrinciple (architecture principles), NoraBuildingBlock (bouwstenen/capabilities), NoraService (diensten), NoraStandard (verplichte/aanbevolen open standaarden), NoraPattern (architecture patterns)
- **Stable URIs & versioning**: `{organization}/architectuur/{type}/{code}` resolvable to JSON-LD/HTML/RDF with full version history
- **Mapping table**: Link local concepts to upstream NORA/GEMMA/PETRA/WILMA/MARIJ/EAR URIs (adopts/profiles/extends/replaces/contradicts)
- **RDF/Turtle & content negotiation**: SKOS + DCAT-AP-NL exports; JSON-LD/RDF-XML/HTML responses per URI
- **Browser UI**: Search, principle hierarchy tree, building-block dependency graphs, change-history timelines, mapping coverage dashboards
- **Comply-or-explain workflow**: Structured justification for non-adopted standards with expiry dates
- **Forum Standaardisatie integration**: Nightly sync of mandatory standards list; flag non-compliance

## Key features
- **REQ-NORA-001**: Five core schemas with full SKOS metadata
- **REQ-NORA-002**: Stable URI scheme resolvable to JSON-LD and HTML
- **REQ-NORA-003**: Version history and audit trail per item
- **REQ-NORA-004**: Mapping table linking to national/regional NORA-family URIs
- **REQ-NORA-005**: RDF/Turtle export using SKOS + DCAT-AP-NL
- **REQ-NORA-007**: Full-text search with type-faceted results
- **REQ-NORA-008**: Principle hierarchy browser (parent/child navigation)
- **REQ-NORA-013**: Comply-or-explain workflow per standard
- **REQ-NORA-016**: Public read endpoints; editing requires architect role
