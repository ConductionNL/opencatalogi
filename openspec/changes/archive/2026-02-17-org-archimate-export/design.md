# Design: org-archimate-export

## Architecture Overview

The organization-specific ArchiMate export builds on top of the existing export pipeline but adds a distinct enrichment layer. Rather than modifying the current `exportArchiMateXml()` method, we create a **new method** in `ArchiMateExportService` that:

1. Calls the existing `getObjectsFromDatabase()` to get all GEMMA base objects
2. Queries the Voorzieningen register for the target organization's modules/gebruik
3. Synthesizes new ArchiMate elements, relationships, and enriched view copies
4. Generates XML using the existing `addViewDataToXmlNode()` / `addNodeDataToXmlElement()` helpers

```
┌─────────────────┐     ┌──────────────────────┐     ┌─────────────────────┐
│ SettingsController│────▶│ ArchiMateService      │────▶│ ArchiMateExportService│
│ exportOrgArchimate│     │ exportOrgArchiMate()  │     │ exportOrgXml()        │
└─────────────────┘     └──────────────────────┘     └─────────────────────┘
                                  │                            │
                                  ▼                            ▼
                         ┌───────────────┐            ┌────────────────┐
                         │ SettingsService│            │ Existing helpers│
                         │ getAmefConfig()│            │ arrayToXml()   │
                         │ getSchemaId()  │            │ addNodeData()  │
                         └───────────────┘            └────────────────┘
```

The export is purely read-only — it queries existing data and generates XML. No database writes.

## API Design

### `POST /api/archimate/export/organization`

Export an enriched ArchiMate file for a specific organization.

**Request:**
```json
{
  "organization": "uuid-of-organization"
}
```

**Response:** Binary XML file download
- Content-Type: `application/xml`
- Content-Disposition: `attachment; filename="17-02-2026_Softwarecatalogus_AMEFF_export_Zeist.xml"`

**Error Response (400):**
```json
{
  "success": false,
  "message": "Organization UUID is required",
  "error": "MISSING_ORGANIZATION"
}
```

**Error Response (404):**
```json
{
  "success": false,
  "message": "Organization not found",
  "error": "ORGANIZATION_NOT_FOUND"
}
```

## Database Changes

No schema or migration changes needed. The export reads from:
- **AMEF register**: All existing GEMMA objects (elements, relationships, views, organizations, property definitions)
- **Voorzieningen register**: Organization objects (for name, UUID)
- **Voorzieningen register**: Module/gebruik objects (for application → referentiecomponent mappings)

## Nextcloud Integration

- **Controllers**: `SettingsController` — add `exportOrgArchiMate()` method (mirrors existing `exportArchiMate()` pattern)
- **Services**:
  - `ArchiMateService` — add `exportOrgArchiMate(?string $organizationUuid)` orchestrator
  - `ArchiMateExportService` — add `exportOrganizationArchiMateXml()` main export method
  - `SettingsService` — existing config methods (no changes)
- **DI**: No new services needed — `ArchiMateService` already has `ArchiMateExportService`, `SettingsService`, `ObjectService` via container
- **Annotations**: `@NoAdminRequired`, `@NoCSRFRequired` (same as existing export)

## File Structure

```
softwarecatalog/
  appinfo/
    routes.php                          # Add POST /api/archimate/export/organization
  lib/
    Controller/
      SettingsController.php            # Add exportOrgArchiMate() method
    Service/
      ArchiMateService.php              # Add exportOrgArchiMate() orchestrator
      ArchiMateExportService.php        # Add exportOrganizationArchiMateXml() + helpers
  src/
    views/settings/sections/
      ArchiMateImportExport.vue         # Add "Organization Export" button
```

## Key Design Decisions

### Decision 1: New method, not extending existing export

**Choice**: Add a new `exportOrganizationArchiMateXml()` method rather than adding organization filtering to `exportArchiMateXml()`.

**Rationale**: The existing export does a clean 1:1 reconstruction from stored XML blobs. The organization export needs to synthesize new elements, relationships, and modified view copies — fundamentally different logic. Keeping them separate avoids regression risk on the existing export.

### Decision 2: SpecializationRelationship for application → referentiecomponent

Per issue #72 acceptance criteria: use `SpecializationRelationship` (application "is a" referentiecomponent). This matches the GEMMA Online convention.

```xml
<relationship identifier="id-swc-rel-{uuid}" xsi:type="SpecializationRelationship"
    source="id-swc-app-{appUuid}" target="{referentiecomponentIdentifier}">
  <properties>
    <property propertyDefinitionRef="id-swc-propdef-bron">
      <value>Softwarecatalogus</value>
    </property>
  </properties>
</relationship>
```

### Decision 3: Organization folder structure in ArchiMate XML

SWC-added objects go into dedicated organization folders within existing sections:

```xml
<organizations>
  <!-- Original GEMMA organization tree -->
  <item><label>Applications</label>
    <item><label>Applicaties (Softwarecatalogus)</label>
      <item identifierRef="id-swc-app-{uuid}"/>  <!-- app elements -->
    </item>
  </item>
  <item><label>Relations</label>
    <item><label>Relaties (Softwarecatalogus)</label>
      <item identifierRef="id-swc-rel-{uuid}"/>  <!-- relationships -->
    </item>
  </item>
  <item><label>Views</label>
    <item><label>Views (Softwarecatalogus)</label>
      <item identifierRef="id-swc-view-{uuid}"/>  <!-- enriched views -->
    </item>
  </item>
</organizations>
```

### Decision 4: View copying and node injection

For each qualifying view (those with `Publiceren` property containing "Softwarecatalogus"):
1. Deep-copy the view's XML blob
2. Assign a new identifier: `id-swc-view-{originalViewIdentifier}`
3. Rename using `Titel view SWC` property + organization name
4. Walk the view's `node` tree; for each node with an `elementRef` matching a referentiecomponent that has mapped applications:
   - Calculate child node positions (stack vertically inside parent, fitting within parent bounds)
   - Add `<node>` children with `elementRef` pointing to the new SWC application elements
   - Add `<connection>` elements for each specialization relationship

### Decision 5: Node positioning algorithm

Application nodes inside a referentiecomponent:
- **Width**: parent `_w` minus padding (10px each side)
- **Height**: fixed 18px per application
- **Y position**: stack from bottom of parent, working upward (same approach as the frontend overlay)
- **Gap**: 2px between stacked nodes

```
┌─ Referentiecomponent (w=200, h=80) ─┐
│ Component Name                        │
│                                       │
│ ┌─ App A ──────────────────────────┐ │
│ └──────────────────────────────────┘ │
│ ┌─ App B ──────────────────────────┐ │
│ └──────────────────────────────────┘ │
└───────────────────────────────────────┘
```

### Decision 6: Property `Bron=Softwarecatalogus` on all SWC objects

Per #72, all SWC-inserted elements, relationships, and views get a property:
```xml
<properties>
  <property propertyDefinitionRef="id-swc-propdef-bron">
    <value>Softwarecatalogus</value>
  </property>
</properties>
```

We add a `propertyDefinition` for "Bron" if it doesn't already exist in the model.

### Decision 7: File and model naming

- **File name**: `DD-MM-YYYY_Softwarecatalogus_AMEFF_export_{OrgName}.xml` (per #72)
- **Model name**: `Softwarecatalogus {OrgName}` (per #72)

The organization name comes from the `naam` field of the organization object in the Voorzieningen register.

## Security Considerations

- **Auth**: Same as existing export — `@NoAdminRequired` (any logged-in user can export)
- **CORS**: Not applicable — export is same-origin only (Nextcloud frontend)
- **Input validation**: Validate `organization` UUID format before querying
- **CSRF**: `@NoCSRFRequired` with `requesttoken` header (same pattern as existing export)
- **Data access**: The export reads all GEMMA data (public) plus organization-specific modules filtered by the provided UUID. No cross-organization data leakage since we only query modules/gebruik for the specified organization.

## NL Design System

Not applicable — this is a backend export feature. The frontend trigger is a simple button in the existing ArchiMate settings section using Nextcloud Vue components (NcButton, NcSelect).

## Trade-offs

### Considered: Modify existing export to accept enrichment flags
**Rejected**: Too much complexity in one function. The existing export has round-trip fidelity guarantees we don't want to risk. Separate function is cleaner.

### Considered: Stream XML directly without loading all objects
**Rejected**: The GEMMA model is ~8000 objects, plus organization data adds a few hundred. This fits in memory (tested: existing export handles 8000+ objects in seconds). Streaming complexity isn't justified.

### Considered: Generate the export client-side by merging base export + enrichment API
**Rejected**: The enrichment API returns JSON viewNodes, not ArchiMate XML. Converting back to valid ArchiMate XML with proper identifiers, connections, and organization folders is complex and best done server-side where we have direct database access.

### Risk: Views without `Publiceren` property
**Mitigation**: If no views have the `Publiceren` property matching "Softwarecatalogus", fall back to copying all views. Log a warning so administrators know the property isn't set. This ensures the export is useful even without that specific property configured.

### Risk: Large number of applications per referentiecomponent
**Mitigation**: Cap at a reasonable number (e.g., 20 applications per referentiecomponent in a view node). Beyond that, applications overflow the parent bounds and make the ArchiMate diagram unreadable. Log the overflow count.
