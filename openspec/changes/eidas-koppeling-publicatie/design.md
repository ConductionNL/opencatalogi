# Design: eidas-koppeling-publicatie

## Architecture Overview

The eIDAS publication layer extends opencatalogi's service catalog with EU-wide publication capabilities. The architecture consists of four main components:

### 1. Publication Configuration
**DienstPublicatie** wraps an existing opencatalogi service and adds publication metadata:
- Scope: local, regional, national, or cross-border
- Publication status: concept → internal → published → withdrawn
- Target destinations: website, overheid.nl, your-europe, eidas (configurable per service)
- Publication date and target-specific sync history

### 2. eIDAS Classification & Compliance
**EidasMetadata** standardizes services for European catalogs:
- SDG Annex classification (I = online available, II = partly online, III = fully online)
- Procedure category: registration, identification, document issue, or payment
- Minimum assurance level (low, substantial, or high) linked to authentication backend
- Cross-border applicability flag and supported language list
- Validation gate: publication blocked unless assurance-level matches authentication endpoint capabilities

### 3. Federative Authentication
**SamlMetadata** configures SAML 2.0 authentication integration with the Dutch eIDAS node (Logius):
- SP entity ID, SAML endpoints, signing/encryption certificates
- Supported eIDAS attributes (BSN, natural person MDS, legal person MDS)
- Certificate lifecycle management with refresh notifications on update
- SAML 2.0 SP metadata XML auto-generated and published at fixed public URL for automated eIDAS node consumption

### 4. Cross-Border Support
**CrossBorderProces** + **TaalVariant** enable EU service delivery:
- OOTS evidence broker endpoint registration (article 14 compliance)
- Supported input credential types (eIDAS eID, EUDI Wallet tokens)
- Language-specific service descriptions (title, summary, procedure steps)
- Translation lineage tracking: manual, eTranslation (CEF), or professional
- Auto-translation proposal via eTranslation API (24 EU language templates)

### 5. Publication Fan-Out
**PublicatieLog** tracks outbound sync to multiple targets:
- Per-target push: REST to website, SOAP to overheid.nl, OData to your-europe-API, SAML metadata to Logius
- Atomic completion: publication marked successful only when all configured targets succeed
- Partial-fail mode: status=published_with_warning if some targets fail
- Compliance auditing: payload hash, response status, error details logged per sync attempt

### 6. Feedback Integration
Periodic harvesting of Your-Europe user feedback (ratings, comments) and presentation to service owner within opencatalogi, linked to DienstPublicatie for centralized content improvement tracking.

## Cross-App Dependencies

- **openregister**: Provides register + schema infrastructure for DienstPublicatie and TaalVariant storage
- **openconnector**: Adapters to Your-Europe-API, Logius eIDAS node, OOTS evidence broker, CEF eTranslation REST API
- **docudesk**: Archiving of published versions (metadata snapshot per publication date)
- **decidesk**: Formal approval gate for cross-border service launch (DPIA, processor agreements)
- **softwarecatalog**: CPSV-AP uplink to software components delivering the service

See specs/eidas-koppeling-publicatie/spec.md for detailed requirements and scenarios.
