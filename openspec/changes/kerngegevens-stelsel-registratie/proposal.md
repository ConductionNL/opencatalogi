# Proposal: kerngegevens-stelsel-registratie

## Summary
Add structured stelselregistratie (system positioning registry) metadata to opencatalogi datasets, enabling proper classification of datasets within the Dutch government data stelsel layers and supporting federation with national data catalogs, compliance with DCAT-AP-NL 2.1, and data quality tracking through maintenance cycles and validity periods.

## Motivation
Dutch government datasets exist within a layered structure: ten statutory basis registers (basisregistraties), sector-wide core data sets (kerngegevens), domain-specific registers (healthcare, education, justice, mobility), and derived local datasets. Current opencatalogi metadata only captures DCAT-AP basics, leaving dataset positioning ambiguous in free-text descriptions. This breaks automatic federation with data.overheid.nl, Forum Standaardisatie registries, and the Stelselcatalogus (2026), and leaves reusers without reliable source dates, maintenance information, validity windows, or feedback mechanisms - all required under DCAT-AP-NL 2.1.

## Scope
- **Stelselregistratie metadata schemas**: StelselPositie, KerngegevensSet, BijhoudCyclus, Geldigheidsperiode, TerugmeldEndpoint
- **Validation and UI wizards**: Enforce mandatory metadata per stelsel type (basis register requires legal basis + feedback endpoint, etc.)
- **Maintenance and lifecycle tracking**: Data freshness indicators, historical snapshots, validity periods
- **DCAT-AP-NL 2.1 export**: RDF/XML and JSON-LD output with proper vocabularies and IRI mappings
- **Federation API integration**: Automated push to Stelselcatalogus with vocabulaire mappings
- **Integration**: Links to openregister (schema validation), opencatalogi (dataset base entity), openconnector (federation targets), softwarecatalog (organization reference)
