# Organization ArchiMate Export

## Overview

Export an organization-enriched ArchiMate (AMEFF) XML file that includes the base GEMMA model plus the organization's applications plotted on referentiecomponenten. The exported file imports cleanly into Archi and other AMEFF-compatible tools.

## Usage

The export endpoint accepts query parameters to control which data layers are included:

- `include_modules` - Include module application mappings
- `include_gebruik` - Include owned usage data
- `include_deelnames` - Include shared/deelnames usage data

## Output Structure

The AMEFF XML organizes content into typed folders:

- **Applicaties** - ApplicationComponent elements with Bron (source) property
- **Relaties** - SpecializationRelationship linking applications to referentiecomponenten
- **Views** - Copies of GEMMA views with applications plotted inside referentiecomponenten

## View Naming

View copies use the `Titel view SWC` property for naming, maintaining recognizable view names in the export.
