# Design: nora-architectuur-publishing

## Architecture Overview

The NORA architecture catalog is implemented as a typed OpenCatalogi catalog (`catalogType: nora-architecture`) using five OpenRegister schemas. All items inherit SKOS metadata (prefLabel, altLabel, definition, scopeNote), DCAT-AP-NL provenance (creator, publisher, issued, modified), and stable URIs under `{organization}/architectuur/{type}/{code}`. Version history and audit trails are handled via OpenRegister's standard `AuditTrailService`. The mapping table (`NoraMapping`) stores relationships to upstream NORA/GEMMA/PETRA/WILMA/MARIJ/EAR concepts as structured records, enabling fast reverse-lookups via OpenRegister's relation index.

See specs/nora-architectuur-publishing/spec.md for detailed requirements and user scenarios.

## Reuse Analysis

- **OpenRegister schemas & CRUD**: Uses `ObjectService.saveObject()`, `deleteObject()`, `findAll()` — no custom persistence layer
- **Versioning & audit**: Leverages `AuditTrailService` for version history; OpenRegister status-machine handles draft → vastgesteld → vervallen transitions
- **Relation index**: Uses OpenRegister's `RelationService` for bidirectional mappings and reverse lookups
- **Search & faceting**: Uses `IndexService` for full-text search and `FacetBuilder` for type-faceted results
- **Content negotiation**: Uses `SerializerFactory` (existing OpenRegister pattern) to dispatch JSON-LD, RDF-XML, HTML responses based on Accept headers
- **File storage**: Uses `FileService` for PDF/HTML/Turtle export artifacts

## Data Model

### NoraPrinciple (Architecture Principle)
- **id** (UUID): Stable identifier
- **code** (string): Human-readable code (e.g., "AP-01", "GP-03")
- **title** (string): Localized title (via i18n)
- **statement** (text): Core principle statement
- **rationale** (text): Why this principle exists
- **implications** (array): Expected consequences/constraints imposed
- **domain** (enum): informatie|applicatie|technologie|governance
- **parentId** (UUID, optional): Parent principle for hierarchy
- **adoptsFromUri** (URI, optional): Links to national NORA principle via `NoraMapping`
- **profileOfUri** (URI, optional): Links to GEMMA/PETRA principle via `NoraMapping`
- **status** (enum): draft|vastgesteld|vervallen
- **version** (string): Semantic versioning
- **validFrom** (date): When this version took effect
- **validTo** (date, optional): Expiry date
- **owner** (reference to Contact): Responsible architect
- **SKOS metadata**: prefLabel, altLabel, definition, scopeNote
- **DCAT-AP-NL metadata**: creator, publisher, issued, modified

### NoraBuildingBlock (Bouwsteen / Capability)
- **id** (UUID): Stable identifier
- **code** (string): Bouwsteen code (e.g., "BS-23", "ZA-01")
- **title** (string): Localized title
- **description** (text): Capability description
- **capabilityArea** (enum): zaak|document|identity|integration|data|klantcontact|basisregistratie
- **realizesIds** (array of UUID): Principles this building block realizes
- **composedOfIds** (array of UUID): Other building blocks it's composed from
- **standardIds** (array of UUID): Standards this building block complies with
- **maturityLevel** (enum): initial|repeatable|defined|managed|optimized
- **owner** (reference to Contact): Responsible team
- **lifecycleStatus** (enum): planned|in-use|deprecated|retired
- **SKOS metadata**: prefLabel, altLabel, definition, scopeNote
- **DCAT-AP-NL metadata**: creator, publisher, issued, modified

### NoraService (Dienst / Service)
- **id** (UUID): Stable identifier
- **code** (string): Service code (e.g., "SRV-12")
- **title** (string): Localized service name
- **description** (text): What this service provides
- **deliversIds** (array of UUID): Building blocks this service delivers
- **serviceLevel** (enum): best-effort|bronze|silver|gold|platinum
- **consumerTypes** (array of enum): gemeente|provincie|waterschap|rijksorgaan|andere
- **provider** (reference to Contact): Organization providing the service
- **technicalEndpoint** (URI, optional): Service API base URL
- **governanceContact** (reference to Contact): Escalation contact
- **slaUri** (URI, optional): Link to SLA document
- **SKOS metadata**: prefLabel, altLabel, definition, scopeNote
- **DCAT-AP-NL metadata**: creator, publisher, issued, modified

### NoraStandard (Open Standard)
- **id** (UUID): Stable identifier
- **code** (string): Standard code (e.g., "STUF-ZKN-0310", "NEN-3610", "API-STRATEGY")
- **title** (string): Standard name
- **issuer** (enum): Forum-Standaardisatie|NEN|ISO|W3C|ETSI|OpenGroup|andere
- **type** (enum): open-standaard|norm|guideline|specification
- **status** (enum): verplicht|aanbevolen|in-onderzoek|afgeraden
- **forumStandaardisatieUri** (URI, optional): Link to forum standardization entry
- **complianceStatus** (enum): toegepast|niet-toegepast
- **complianceReasoning** (text, optional): Justify non-adoption (comply-or-explain)
- **complianceExpiryDate** (date, optional): Re-evaluation deadline for "niet-toegepast"
- **alternativeStandardIds** (array of UUID): Standards used instead
- **SKOS metadata**: prefLabel, altLabel, definition, scopeNote
- **DCAT-AP-NL metadata**: creator, publisher, issued, modified

### NoraPattern (Architecture Pattern)
- **id** (UUID): Stable identifier
- **code** (string): Pattern code (e.g., "PAT-async-events")
- **title** (string): Pattern name
- **problem** (text): Context & problem this pattern solves
- **solution** (text): Proposed solution & structure
- **consequences** (text): Trade-offs & implications
- **appliesToIds** (array of UUID): Building blocks where this pattern applies
- **examples** (array of object): Real-world usage examples
  - **exampleTitle** (string): Example name
  - **exampleUri** (URI): Link to reference implementation or documentation
- **SKOS metadata**: prefLabel, altLabel, definition, scopeNote
- **DCAT-AP-NL metadata**: creator, publisher, issued, modified

### NoraMapping (Cross-Architecture Mapping)
- **id** (UUID): Stable identifier
- **localUri** (URI): Stable URI of local concept
- **upstreamUri** (URI): Stable URI of national/regional NORA-family concept
- **mappingType** (enum): adopts|profiles|extends|replaces|contradicts
- **justification** (text, optional): Why this mapping exists; used for "contradicts" to explain conflicts
- **mappingAuthor** (reference to Contact): Who decided this mapping
- **mappingDate** (date): When mapping was established

## Seed Data

### Gemeente Amsterdam - NORA Principles (3 examples)

```json
{
  "@self": {
    "register": "nora-catalogues",
    "schema": "nora-principle",
    "slug": "gem-ams-ap-01-open-first"
  },
  "code": "AP-01",
  "title": "Open by Default",
  "statement": "All government architecture decisions prioritize open standards, open data, and public access unless security/privacy/operational concerns override.",
  "rationale": "Open standards reduce vendor lock-in and enable interoperability across organizations. Accessibility and transparency build public trust.",
  "implications": [
    "Procurement must prioritize products using open standards (STUF, API-NL-Strategie, OData)",
    "Data sharing APIs default to REST+JSON over proprietary formats",
    "Architecture decisions documented in public wiki; only security-sensitive items redacted"
  ],
  "domain": "informatie",
  "parentId": null,
  "adoptsFromUri": "https://noraonline.nl/pages/AP01",
  "status": "vastgesteld",
  "version": "2.1",
  "validFrom": "2024-01-01",
  "validTo": null,
  "owner": {
    "fn": "Bert de Architect",
    "email": "b.dearchitect@amsterdam.nl",
    "tel": "+31201234567"
  },
  "prefLabel": "Open by Default",
  "altLabel": ["Default Open", "Open-First Beginsel"],
  "definition": "Gemeente Amsterdam adopts NORA principle AP-01: all new systems and shared services default to open standards.",
  "scopeNote": "Exceptions require CIO approval and documented justify-and-explain.",
  "creator": "City of Amsterdam Enterprise Architecture",
  "publisher": "City of Amsterdam",
  "issued": "2024-01-01",
  "modified": "2024-06-15"
}
```

```json
{
  "@self": {
    "register": "nora-catalogues",
    "schema": "nora-principle",
    "slug": "gem-ams-ap-07-user-centricity"
  },
  "code": "AP-07",
  "title": "User-Centric Design",
  "statement": "Every citizen-facing service is designed with end-user needs first; digital accessibility (WCAG 2.1 AA minimum) and Dutch language are mandatory.",
  "rationale": "Citizens using government services have diverse abilities, education levels, and digital literacy. User-centered design reduces errors, improves satisfaction, and ensures legal compliance.",
  "implications": [
    "All UI development follows NL-Design systeem guidelines",
    "User research and usability testing required before launch",
    "Accessibility audits mandatory; WCAG 2.1 AA compliance is non-negotiable"
  ],
  "domain": "applicatie",
  "parentId": null,
  "adoptsFromUri": "https://noraonline.nl/pages/AP07",
  "status": "vastgesteld",
  "version": "1.0",
  "validFrom": "2024-03-01",
  "validTo": null,
  "owner": {
    "fn": "Anja Digitaal",
    "email": "a.digitaal@amsterdam.nl",
    "tel": "+31201234568"
  },
  "prefLabel": "User-Centric Design",
  "definition": "Gemeente Amsterdam adopts NORA principle AP-07 with Amsterdam-specific extensions for rijkshuisstijl and local accessibility standards.",
  "scopeNote": "Overseen by Chief Digital Officer; exceptions require approval by digital steering committee.",
  "creator": "City of Amsterdam Enterprise Architecture",
  "publisher": "City of Amsterdam",
  "issued": "2024-03-01",
  "modified": "2024-06-15"
}
```

```json
{
  "@self": {
    "register": "nora-catalogues",
    "schema": "nora-principle",
    "slug": "gem-ams-gp-15-data-sovereignty"
  },
  "code": "GP-15",
  "title": "Data Sovereignty",
  "statement": "Personal data of Amsterdam residents is stored and processed within the Netherlands; cross-border transfers require explicit legal basis and privacy impact assessment.",
  "rationale": "GDPR compliance and local data protection expectations. Citizens expect their data to remain under Dutch jurisdiction and regulatory oversight.",
  "implications": [
    "Cloud services must have data residency guarantees (NL region or equivalents)",
    "Data transfer agreements must explicitly prohibit onward transfer outside NL",
    "Data processing contracts include Dutch DPA requirements and supervision rights"
  ],
  "domain": "governance",
  "parentId": null,
  "adoptsFromUri": null,
  "profileOfUri": "https://gemmaonline.nl/pages/GP15-data-sovereignty",
  "status": "vastgesteld",
  "version": "1.0",
  "validFrom": "2024-02-01",
  "validTo": null,
  "owner": {
    "fn": "Rieke Rechtsgelearde",
    "email": "r.rechtsgelearde@amsterdam.nl",
    "tel": "+31201234569"
  },
  "prefLabel": "Data Sovereignty",
  "definition": "Amsterdam-specific principle extending GEMMA's data governance to include residency and jurisdiction requirements.",
  "scopeNote": "Enforced by Data Protection Officer; violations escalated to CIO and legal review.",
  "creator": "City of Amsterdam, Legal & Data Protection Office",
  "publisher": "City of Amsterdam",
  "issued": "2024-02-01",
  "modified": "2024-06-15"
}
```

### Gemeente Amsterdam - NORA Building Blocks (3 examples)

```json
{
  "@self": {
    "register": "nora-catalogues",
    "schema": "nora-building-block",
    "slug": "gem-ams-bs-zaak"
  },
  "code": "BS-ZK",
  "title": "Zaak Management",
  "description": "Centralized case management capability: create, track, route, and close cases (zaken) across all citizen-facing services. Supports workflow states (ontvangen, in-behandeling, afgehandeld, beroep) and SLA monitoring.",
  "capabilityArea": "zaak",
  "realizesIds": ["gem-ams-ap-01", "gem-ams-ap-07"],
  "composedOfIds": [],
  "standardIds": ["stuf-zkn-0310", "zaken-api-1.5"],
  "maturityLevel": "managed",
  "owner": {
    "fn": "Zaak-team Amsterdam",
    "email": "zaak-team@amsterdam.nl"
  },
  "lifecycleStatus": "in-use",
  "prefLabel": "Zaak (Case) Management",
  "definition": "OpenCatalogi implementation of GEMMA bouwsteen 'Zaken': handles all incoming citizen requests, service cases, complaints, and administrative decisions with full audit trail.",
  "scopeNote": "Implemented via Zaken-API 1.5 against OpenZaak; achieves SLA compliance for 98% of zaken within deadline.",
  "creator": "City of Amsterdam",
  "publisher": "City of Amsterdam",
  "issued": "2023-06-01",
  "modified": "2024-06-15"
}
```

```json
{
  "@self": {
    "register": "nora-catalogues",
    "schema": "nora-building-block",
    "slug": "gem-ams-bs-document"
  },
  "code": "BS-DOC",
  "title": "Document Management",
  "description": "Document lifecycle management: ingest, index, store, retrieve, version, and dispose. Supports structured document types (besluiten, beleidsdocumenten, WOO disclosure), full-text search, and OCR for scanned originals.",
  "capabilityArea": "document",
  "realizesIds": ["gem-ams-ap-01"],
  "composedOfIds": [],
  "standardIds": ["odf-open-document", "pdf-unicode"],
  "maturityLevel": "managed",
  "owner": {
    "fn": "Documententeam",
    "email": "doc-team@amsterdam.nl"
  },
  "lifecycleStatus": "in-use",
  "prefLabel": "Document Management",
  "definition": "Handles all document types generated by municipality: permits, decisions, policies, correspondence. Integrated with WOO (Wet Open Overheid) transparency requirements.",
  "scopeNote": "Runs on OpenDocument Format (ODF) internally; exports to PDF/A for long-term archival. Achieves WCAG 2.1 AA for scanned PDFs via OCR+tagging.",
  "creator": "City of Amsterdam",
  "publisher": "City of Amsterdam",
  "issued": "2022-09-01",
  "modified": "2024-06-15"
}
```

```json
{
  "@self": {
    "register": "nora-catalogues",
    "schema": "nora-building-block",
    "slug": "gem-ams-bs-klantcontact"
  },
  "code": "BS-KC",
  "title": "Customer Contact Management",
  "description": "Unified customer contact capability: phone, email, chat, social media, and in-person visits tracked in a single CRM. Supports multi-channel routing, sentiment analysis, and complaint escalation.",
  "capabilityArea": "klantcontact",
  "realizesIds": ["gem-ams-ap-07"],
  "composedOfIds": [],
  "standardIds": ["open-standards-communication"],
  "maturityLevel": "repeatable",
  "owner": {
    "fn": "Citizen Services Team",
    "email": "citizens@amsterdam.nl"
  },
  "lifecycleStatus": "in-use",
  "prefLabel": "Customer Contact & Complaints",
  "definition": "Single view of citizen across all communication channels (phone, email, website contact form, social media). Complaint routing and SLA tracking.",
  "scopeNote": "Currently in rollout phase; migrating from legacy phone system + separate email queue to unified omnichannel platform.",
  "creator": "City of Amsterdam",
  "publisher": "City of Amsterdam",
  "issued": "2024-01-15",
  "modified": "2024-06-15"
}
```

### NORA Standards (3 examples)

```json
{
  "@self": {
    "register": "nora-catalogues",
    "schema": "nora-standard",
    "slug": "standard-stuf-zkn-0310"
  },
  "code": "STUF-ZKN-0310",
  "title": "STUF Zaken NVB 3.10",
  "issuer": "VNG Realisatie",
  "type": "open-standaard",
  "status": "verplicht",
  "forumStandaardisatieUri": "https://forumstandaardisatie.nl/open-standaarden/stuf-zaken",
  "complianceStatus": "toegepast",
  "complianceReasoning": null,
  "complianceExpiryDate": null,
  "alternativeStandardIds": ["zaken-api-1.5"],
  "prefLabel": "STUF Zaken NVB 3.10",
  "definition": "STUF protocol for case/zaak data exchange between Dutch government organizations. Forum Standaardisatie verplicht standard with transition path to Zaken-API.",
  "scopeNote": "Gemeente Amsterdam complies via OpenZaak (Zaken-API 1.5 compliant implementation). STUF maintained for legacy integrations until 2026 sunset date.",
  "creator": "Forum Standaardisatie",
  "publisher": "Forum Standaardisatie",
  "issued": "2010-01-01",
  "modified": "2024-06-15"
}
```

```json
{
  "@self": {
    "register": "nora-catalogues",
    "schema": "nora-standard",
    "slug": "standard-nen-3610"
  },
  "code": "NEN-3610",
  "title": "NEN 3610 - Basisgegevensschema Informatiemodellen",
  "issuer": "NEN",
  "type": "norm",
  "status": "verplicht",
  "forumStandaardisatieUri": "https://forumstandaardisatie.nl/open-standaarden/nen-3610",
  "complianceStatus": "niet-toegepast",
  "complianceReasoning": "NEN-3610 applies to geographic data and base registries (BAG, BGT, Kadaster). Amsterdam's geographic data is published via PDOK (national geo-data clearing house) which handles NEN-3610 compliance. Amsterdam's internal systems use geographic references via PDOK API rather than direct NEN-3610 implementation.",
  "complianceExpiryDate": "2025-12-31",
  "alternativeStandardIds": [],
  "prefLabel": "NEN 3610: Geographic Base Data Schema",
  "definition": "Dutch geographic data standard for location/address/boundary information. Mandatory for organizations publishing or using geographic data.",
  "scopeNote": "Compliance reviewed Q1 2025; re-evaluation scheduled to confirm ongoing PDOK reliance vs. direct adoption.",
  "creator": "Nederlands Normalisatie-comité (NEC)",
  "publisher": "NEN",
  "issued": "2011-01-01",
  "modified": "2024-06-15"
}
```

```json
{
  "@self": {
    "register": "nora-catalogues",
    "schema": "nora-standard",
    "slug": "standard-api-nl-strategie"
  },
  "code": "API-NL-STRATEGIE",
  "title": "Dutch API Strategy & Web Guidelines",
  "issuer": "Logius / Forum Standaardisatie",
  "type": "guideline",
  "status": "aanbevolen",
  "forumStandaardisatieUri": "https://forumstandaardisatie.nl/open-standaarden/api-strategy",
  "complianceStatus": "toegepast",
  "complianceReasoning": null,
  "complianceExpiryDate": null,
  "alternativeStandardIds": [],
  "prefLabel": "Dutch API Strategy & Guidelines",
  "definition": "OpenCatalogi-endorsed guidelines for building RESTful APIs in Dutch government: versioning, security (OAuth2/MTLS), pagination, filtering, hypermedia, error handling, rate-limiting.",
  "scopeNote": "All new APIs at Amsterdam comply. Legacy SOAP/WS-* services have deprecation roadmap; no new SOAP development permitted.",
  "creator": "Logius / Forum Standaardisatie",
  "publisher": "Logius / Forum Standaardisatie",
  "issued": "2020-06-01",
  "modified": "2024-06-15"
}
```

### NORA Mappings (3 examples)

```json
{
  "@self": {
    "register": "nora-catalogues",
    "schema": "nora-mapping",
    "slug": "map-ams-ap-01-to-nora"
  },
  "localUri": "https://amsterdam.nl/architectuur/principe/AP-01",
  "upstreamUri": "https://noraonline.nl/pages/AP01",
  "mappingType": "adopts",
  "justification": "Amsterdam fully adopts NORA AP-01 (Open by Default) without profile/modifications.",
  "mappingAuthor": {
    "fn": "Bert de Architect",
    "email": "b.dearchitect@amsterdam.nl"
  },
  "mappingDate": "2024-01-01"
}
```

```json
{
  "@self": {
    "register": "nora-catalogues",
    "schema": "nora-mapping",
    "slug": "map-ams-ap-07-to-nora"
  },
  "localUri": "https://amsterdam.nl/architectuur/principe/AP-07",
  "upstreamUri": "https://noraonline.nl/pages/AP07",
  "mappingType": "profiles",
  "justification": "Amsterdam profiles NORA AP-07 with extensions: mandatory rijkshuisstijl visual design, stricter accessibility (AA minimum vs guideline), and Amsterdam-specific performance targets (3-second page load).",
  "mappingAuthor": {
    "fn": "Anja Digitaal",
    "email": "a.digitaal@amsterdam.nl"
  },
  "mappingDate": "2024-03-01"
}
```

```json
{
  "@self": {
    "register": "nora-catalogues",
    "schema": "nora-mapping",
    "slug": "map-ams-gp-15-local-only"
  },
  "localUri": "https://amsterdam.nl/architectuur/principe/GP-15",
  "upstreamUri": null,
  "mappingType": null,
  "justification": "Amsterdam-specific governance principle with no direct NORA/GEMMA counterpart. Addresses local data-residency requirements imposed by municipal council in 2023 privacy resolution.",
  "mappingAuthor": {
    "fn": "Rieke Rechtsgelearde",
    "email": "r.rechtsgelearde@amsterdam.nl"
  },
  "mappingDate": "2024-02-01"
}
```
