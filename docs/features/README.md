# OpenCatalogi — Features

OpenCatalogi is a federated data catalogue for Nextcloud that enables Dutch government organizations to publish, discover, and synchronize open data across organizational boundaries. It implements the DCAT-AP NL standard for dataset metadata and provides a public-facing reading room without requiring authentication.

OpenCatalogi maps to the **Catalogus** / **Data-catalogus** component within the GEMMA reference architecture (category `data-catalogue`).

## Standards Compliance

| Standard | Status | Description |
|----------|--------|-------------|
| DCAT-AP NL | Beschikbaar | Dutch application profile for DCAT dataset metadata |
| EU DCAT-AP | Beschikbaar | European standard for data catalogue interoperability |
| EU SIMPL | Gepland | Single Market Interoperability Layer |
| Schema.org | Beschikbaar | Linked data metadata enrichment |
| WOO (Wet open overheid) | Beschikbaar | Document publication and redaction workflow |
| WCAG 2.1 AA | Via platform | Accessibility via Nextcloud and NL Design app |
| GDPR / AVG | Via platform | Data subject rights via OpenRegister |

## Features

| Feature | Description | Docs |
|---------|-------------|------|
| [Catalogus & Publicatiebeheer](./org-archimate-export.md) | Create and manage publications, catalogs, listings with full lifecycle (draft → published) | — |
| [Federatie & Synchronisatie](./register-i18n.md) | Cross-organization catalogue synchronization with source configuration and directory sync | — |
| [WOO Transparantie](./woo-transparency.md) | WOO/FOIA document queue, redaction workflow, weigeringsgronden, inventarislijst | [woo-transparency.md](./woo-transparency.md) |
| [Meertalige content](./register-i18n.md) | Multi-language publications and catalogs (NL + EN minimum), EU SDG-compliant | [register-i18n.md](./register-i18n.md) |
| [GEMMA ArchiMate Export](./org-archimate-export.md) | Export organization-enriched AMEFF XML for Archi/BiZZdesign/ADOIT with modules, gebruik, deelnames | [org-archimate-export.md](./org-archimate-export.md) |
| [View Enrichment API](./view-enrichment-api.md) | Unified API aggregating base GEMMA view data with organization-specific module mappings | [view-enrichment-api.md](./view-enrichment-api.md) |
| [Module Overlay Rendering](./module-overlay-rendering.md) | Renders organization application nodes on top of GEMMA ArchiMate views with visual distinction | [module-overlay-rendering.md](./module-overlay-rendering.md) |
| [Deelnames Gebruik](./deelnames-gebruik.md) | Query and display shared usage (inter-organizational cooperation) alongside owned modules | [deelnames-gebruik.md](./deelnames-gebruik.md) |
| [Prometheus Metrics](./prometheus-metrics.md) | Monitoring endpoint in Prometheus text format: publication counts, catalog metrics, listing health | [prometheus-metrics.md](./prometheus-metrics.md) |
| Publiek zoeken | Unauthenticated full-text search with filters and facets | — |
| DCAT-AP export | Dataset metadata export in DCAT-AP NL and EU DCAT-AP format | — |
| Organisatiebeheer | Organizations as owners of publications with RBAC | — |

## Architecture

OpenCatalogi builds on **OpenRegister** as its data layer. Catalogs, publications, listings, and organizations are stored as register objects. The app adds:

- A public-facing API layer (no authentication required for reading)
- Cross-organization federation via the `Listing` and `Source` entities
- ArchiMate/GEMMA integration for the software catalogue use case
- WOO publication workflow on top of the generic publication model

## GEMMA Mapping

| GEMMA Component | OpenCatalogi Role |
|-----------------|-------------------|
| Catalogus | Primary implementation |
| Data-catalogus | DCAT-AP NL publication |
| Architectuurregistratie | Via AMEFF export to Archi |
| Zaaksysteem | Integration point via OpenZaak/Procest |
