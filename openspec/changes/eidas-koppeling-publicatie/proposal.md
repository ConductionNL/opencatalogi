# Proposal: eidas-koppeling-publicatie

## Summary
Add eIDAS publication layer to opencatalogi, enabling machine-readable metadata publication of public services to the EU Single Digital Gateway (SDG), Your-Europe portal, and the Dutch eIDAS-knoopoint (Logius). Services can be configured for cross-border EU access with automatic metadata sync, SAML federation, and machine translation support.

## Motivation
The eIDAS regulation (EU 910/2014) and Single Digital Gateway directive (EU 2018/1724) require member states to publish essential public services in a standardized, machine-readable format accessible across EU borders. Dutch municipalities must now:

- Classify services according to SDG Annex (I/II/III) and procedure type
- Support multilingual content for cross-border users
- Configure eIDAS authentication requirements (low/substantial/high assurance)
- Provide SAML metadata for federative authentication
- Sync metadata to the national Your-Europe connection point and eIDAS node (Logius)

Without this spec, opencatalogi cannot participate in EU digital services interoperability and Dutch municipalities cannot meet their SDG compliance obligations.

## Scope
- **DienstPublicatie** entity: service classification, scope (local/regional/national/cross-border), publication status and targets
- **EidasMetadata** entity: SDG classification, assurance levels, supported languages, cross-border applicability
- **SamlMetadata** entity: SAML SP configuration, certificate management, eIDAS attribute support
- **CrossBorderProces** entity: OOTS evidence requirements, supported credentials, service-localization options
- **PublicatieLog** entity: sync history per publication target (website, overheid.nl, your-europe, eidas)
- **TaalVariant** entity: language-specific service descriptions with translation lineage (manual/eTranslation/professional)
- Publication fan-out to multiple targets (REST/SOAP/OData adapters)
- CEF eTranslation integration for 24 EU languages
- Your-Europe feedback harvesting and display
- Once-Only Principle (OOTS) evidence broker integration
- SAML 2.0 metadata generation and publication for eIDAS node registration
- SDG compliance validation at publication time
