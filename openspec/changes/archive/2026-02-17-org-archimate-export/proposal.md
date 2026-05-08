# Proposal: org-archimate-export

## Summary
Add an organization-specific ArchiMate (AMEFF) export that takes the base GEMMA model, enriches it with the organization's applications plotted on referentiecomponenten across all views, and includes organization/software metadata — so users can import the result into architecture tools like Archi, BiZZdesign, or ADOIT.

Implements requirements from:
- [VNG-Realisatie/Softwarecatalogus#72](https://github.com/VNG-Realisatie/Softwarecatalogus/issues/72) — Exporteren ArchiMate (main feature)
- [VNG-Realisatie/Softwarecatalogus#109](https://github.com/VNG-Realisatie/Softwarecatalogus/issues/109) — Organisatie- en softwaregegevens integratie

## Motivation
The current ArchiMate export (`POST /api/archimate/export`) exports the raw GEMMA model as-is. Organizations have mapped their applications (modules) to referentiecomponenten via the software catalog, but this mapping only exists in the web UI. There is no way to export a model that includes the GEMMA baseline plus organization-specific applications. This means:

- Architects cannot import the enriched model into tools like Archi
- There is no offline, portable representation of an organization's architecture
- The [GEMMA Online AMEFF export spec](https://www.gemmaonline.nl/wiki/Softwarecatalogus_AMEFF_export) cannot be fulfilled

Per issue #72, the export must produce a valid AMEFF file that Archi imports without errors and correctly displays plotted applications inside referentiecomponenten.

## Affected Projects
- [x] Project: `softwarecatalog` — New export function, API endpoint, frontend export button

## Scope

### In Scope

**Elements (from #72 acceptance criteria):**
- Insert application elements with properties (including SWC-generated unique Object ID)
- Place application elements in folder `Applications > Applicaties (Softwarecatalogus)`
- All SWC-inserted objects get property `Bron=Softwarecatalogus`

**Relationships:**
- Insert SpecializationRelationship for each application → referentiecomponent mapping (application "is a" referentiecomponent)
- Place relationships in folder `Relations > Relaties (Softwarecatalogus)`
- Each relationship gets a unique Object ID

**Views:**
- Copy all views with property `Publiceren=Softwarecatalogus en GEMMA Online en redactie` to folder `Views > Views (Softwarecatalogus)`
- Name views from property `Titel view SWC` + organization name (e.g., "Applicatieservices bestuur Zeist")
- Plot applications inside their related referentiecomponenten on all SWC views:
  - Scale application nodes to fit inside the referentiecomponent
  - If referentiecomponent name spans two lines, second line may be covered
  - Create `<connection>` elements for each specialization relationship (in Archi these are invisible when nested, visible when dragged out)

**Organization integration (from #109):**
- Include software catalog organizations as ArchiMate elements
- Link applications to their owning organization via relationships
- Preserve metadata (organization name, software details)

**File naming and model:**
- File name: `DD-MM-YYYY_Softwarecatalogus_AMEFF_export_<OrgName>.xml`
- Model name: `Softwarecatalogus <OrgName>`

**API and frontend:**
- New API endpoint for organization-specific export
- Frontend export button with organization selector

### Out of Scope
- Modifying the existing base ArchiMate export — it stays as-is
- Importing enriched ArchiMate files back (round-trip of enrichment data)
- Deelnames/participation data in the export (future enhancement)
- Product data enrichment in the export

## Approach
1. **Analyse existing code**: The `ArchiMateExportService` (1896 lines) has a full pipeline for generating valid ArchiMate XML. The `ViewService.expandModulesToViewNodes()` creates overlay nodes for the enrichment API. Reuse patterns from both but create a new function.
2. **New export function**: Create a new method that:
   - Starts from the base GEMMA export (all elements, relationships, property definitions, organizations)
   - Queries the target organization's modules and gebruik data (reusing ViewService enrichment patterns)
   - For each module, creates an `<element xsi:type="ApplicationComponent">` with `Bron=Softwarecatalogus` property
   - For each module→referentiecomponent mapping, creates a `<relationship xsi:type="SpecializationRelationship">`
   - Copies qualifying views and for each, adds `<node>` children inside referentiecomponent nodes with `<connection>` elements
   - Places SWC-added objects in dedicated organization folders
   - Adds organization elements for cross-referencing
3. **Node positioning**: Calculate application node positions inside referentiecomponent parent nodes — scale to fit, stack vertically if multiple applications map to the same referentiecomponent
4. **Endpoint**: New route accepting organization identifier, returns XML file download
5. **Frontend**: Export button in settings, with organization dropdown pre-populated

## Cross-Project Dependencies
- **OpenRegister**: ObjectService for querying modules, gebruik, and organization data (existing dependency)
- **View Enrichment API** (spec: `view-enrichment-api`): Same enrichment concept (modules → referentiecomponenten), now applied to XML instead of JSON viewNodes
- **GEMMA Online**: Export format must match [Softwarecatalogus_AMEFF_export](https://www.gemmaonline.nl/wiki/Softwarecatalogus_AMEFF_export) specification

## Rollback Strategy
- Purely additive change — new function, new endpoint, new button
- The existing base export remains untouched
- Rollback: remove the new endpoint and function; no data migration needed
- If the new export produces invalid XML, users still have the base export as fallback

## Open Questions
- Which view property indicates `Publiceren=Softwarecatalogus en GEMMA Online en redactie`? Need to verify how this is stored in the database after ArchiMate import.
- Should applications without any referentiecomponent mappings still appear in the export as standalone elements?
- What ArchiMate element type should organizations be? BusinessActor seems natural but needs confirmation against the GEMMA Online spec.
