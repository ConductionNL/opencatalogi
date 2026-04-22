# Design: enhance-amef-export-org-data

## Architecture Overview

The current org export flow is:

```
Frontend (POST + JSON body)
  → SettingsController.exportOrgArchiMate()
    → ArchiMateService.exportOrgArchiMate(orgUuid)
      → fetches org, gebruik, modules from Voorzieningen register
      → ArchiMateExportService.exportOrganizationArchiMateXml(...)
        → builds base GEMMA + SWC app elements + relationships + views + folders
        → returns XML string
    → returns XML as file download
```

This change modifies the flow to:

```
Frontend (GET + query params)
  → SettingsController.exportOrgArchiMate(organizationUuid)
    → reads ?modules=true&deelnames=true&gebruik=true from query
    → ArchiMateService.exportOrgArchiMate(orgUuid, options)
      → fetches org, gebruik, modules (existing)
      → NEW: fetches deelname gebruik (RBAC off, deelnemers query)
      → ArchiMateExportService.exportOrganizationArchiMateXml(..., deelnamesData, options)
        → builds base GEMMA
        → generates elements/relationships per type into typed folders
        → returns XML string
    → returns XML as file download
```

## API Design

### `GET /api/archimate/export/organization/{organizationUuid}`

Replaces the current `POST /api/archimate/export/organization`.

**Request:**
```
GET /index.php/apps/softwarecatalog/api/archimate/export/organization/550e8400-e29b-41d4-a716-446655440000?modules=true&deelnames=true&gebruik=true
```

Query parameters (all optional, default `false`):
| Parameter | Type | Description |
|-----------|------|-------------|
| `modules` | bool | Include module elements and specialization relationships to referentiecomponenten |
| `deelnames` | bool | Include gebruik objects where this org is in `deelnemers` (RBAC off) |
| `gebruik` | bool | Include the org's own gebruik data |

If no query parameters are provided, the export returns the base GEMMA model with the organisation's modules (current default behavior, same as `modules=true`).

**Response (success):** XML file download
```
HTTP/1.1 200 OK
Content-Type: application/xml
Content-Disposition: attachment; filename="19-02-2026_Softwarecatalogus_AMEFF_export_Zeist.xml"
Content-Length: 1234567

<?xml version="1.0" encoding="UTF-8"?>
<model xmlns="http://www.opengroup.org/xsd/archimate/3.0/" ...>
  ...
</model>
```

**Response (error):**
```json
{
  "success": false,
  "message": "Organization not found: 550e8400-...",
  "error": "NOT_FOUND"
}
```

## Data Flow: Deelname Retrieval

A "deelname" is not a separate entity — it's a **gebruik object owned by another organisation** where the current org's UUID appears in the `deelnemers` array property.

### Query Pattern

The pattern already exists in `ViewService.getGebruikData()` step 2 (line 633-666). For the export, the same approach is used in `ArchiMateService`:

```php
// Query gebruik where current org is a deelnemer (RBAC disabled to cross org boundaries)
$deelnameQuery = [
    '@self' => [
        'register' => $orgRegisterId,
        'schema' => $gebruikSchemaId
    ],
    'deelnemers' => $organizationUuid,
    '_limit' => 10000
];
$deelnamesData = $objectService->searchObjects(
    query: $deelnameQuery,
    _rbac: false,
    _multitenancy: false
);
```

This returns gebruik objects from all organisations that list the current org as a participant. These are then processed through the same `buildModuleLookupMaps()` pipeline to extract module → referentiecomponent mappings.

## Folder Structure in Export XML

Currently the export builds a single flat structure under the org name:

```
<organizations>
  ... (base GEMMA folders) ...
  <item>
    <label>Zeist</label>
    <item>
      <label>Applicaties (Softwarecatalogus)</label>
      <item identifierRef="id-swc-app-..." />
    </item>
    <item>
      <label>Relaties (Softwarecatalogus)</label>
      <item identifierRef="id-swc-rel-..." />
    </item>
    <item>
      <label>Views (Softwarecatalogus)</label>
      <item identifierRef="id-swc-view-..." />
    </item>
  </item>
</organizations>
```

The new structure separates by relationship type:

```
<organizations>
  ... (base GEMMA folders) ...
  <item>
    <label>Zeist</label>
    <item>
      <label>Gebruikt (Softwarecatalogus)</label>        <!-- org's own gebruik -->
      <item identifierRef="id-swc-app-..." />
    </item>
    <item>
      <label>Aangeboden (Softwarecatalogus)</label>      <!-- org provides -->
      <item identifierRef="id-swc-app-..." />
    </item>
    <item>
      <label>Deelnames (Softwarecatalogus)</label>       <!-- org participates -->
      <item identifierRef="id-swc-app-..." />
    </item>
    <item>
      <label>Relaties (Softwarecatalogus)</label>
      <item identifierRef="id-swc-rel-..." />
    </item>
    <item>
      <label>Views (Softwarecatalogus)</label>
      <item identifierRef="id-swc-view-..." />
    </item>
  </item>
</organizations>
```

Each folder only appears if the corresponding query parameter is `true` and data exists for that type. Empty folders are omitted.

## File Changes

### Modified Files

```
appinfo/routes.php                                    — Replace POST route with GET + {organizationUuid}
lib/Controller/SettingsController.php                 — Refactor exportOrgArchiMate() to accept path param + query params
lib/Service/ArchiMateService.php                      — Add deelname query + pass options array
lib/Service/ArchiMateExportService.php                — Accept deelnames data, build typed folders
lib/Service/ViewService.php                           — Implement getDeelnamesGebruikData() and getNodeDeelnamesGebruik()
src/views/settings/sections/ArchiMateImportExport.vue — Switch POST to GET with query string
```

### No New Files

No new files, database tables, or migrations are needed.

## Detailed Changes Per File

### `appinfo/routes.php`

```php
// Remove:
['name' => 'settings#exportOrgArchiMate', 'url' => '/api/archimate/export/organization', 'verb' => 'POST'],

// Add:
['name' => 'settings#exportOrgArchiMate', 'url' => '/api/archimate/export/organization/{organizationUuid}', 'verb' => 'GET'],
```

### `SettingsController.php`

The `exportOrgArchiMate()` method changes from reading JSON body to accepting a path parameter and query params:

```php
public function exportOrgArchiMate(string $organizationUuid): Response
{
    // Read boolean query parameters
    $modules = $this->request->getParam('modules', 'true') === 'true';
    $deelnames = $this->request->getParam('deelnames', 'false') === 'true';
    $gebruik = $this->request->getParam('gebruik', 'false') === 'true';

    $options = [
        'modules' => $modules,
        'deelnames' => $deelnames,
        'gebruik' => $gebruik,
    ];

    $result = $this->archiMateService->exportOrgArchiMate($organizationUuid, $options);
    // ... same response handling ...
}
```

### `ArchiMateService.php`

`exportOrgArchiMate()` gains an `$options` parameter and a new deelname query:

```php
public function exportOrgArchiMate(string $organizationUuid, array $options = []): array
{
    // ... existing org lookup, gebruik, modules queries ...

    // NEW: query deelnames if enabled
    $deelnamesData = [];
    if ($options['deelnames'] ?? false) {
        $deelnameQuery = [
            '@self' => [
                'register' => $orgRegisterId,
                'schema' => $gebruikSchemaId
            ],
            'deelnemers' => $organizationUuid,
            '_limit' => 10000
        ];
        $deelnamesData = $objectService->searchObjects(
            query: $deelnameQuery, _rbac: false, _multitenancy: false
        );
    }

    // Pass all data + options to export service
    $xml = $this->exportService->exportOrganizationArchiMateXml(
        $objectService, $registerId, $schemaIdMap,
        $orgName, $organizationUuid,
        $gebruikData, $modulesData, $deelnamesData, $options
    );
}
```

### `ArchiMateExportService.php`

`exportOrganizationArchiMateXml()` gains `$deelnamesData` and `$options` parameters:

1. Build separate lookup maps for each data source (gebruik, deelnames)
2. Generate app elements tagged with their source type
3. `buildSwcOrganizationFolders()` creates typed folders (`Gebruikt`, `Aangeboden`, `Deelnames`) based on which options are enabled and which data exists

### `ViewService.php`

Implement the two stub methods:

**`getDeelnamesGebruikData()`** — Uses the same query pattern as `getGebruikData()` step 2: queries gebruik objects with `deelnemers => $currentOrg` and RBAC off. Returns data indexed by elementRef.

**`getNodeDeelnamesGebruik()`** — Matches a model node's elementRef against the deelnames data to return participation info for that specific node.

### `ArchiMateImportExport.vue`

```javascript
// Before:
const response = await fetch('/index.php/apps/softwarecatalog/api/archimate/export/organization', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ organization: orgUuid })
});

// After:
const params = new URLSearchParams();
if (this.includeModules) params.set('modules', 'true');
if (this.includeDeelnames) params.set('deelnames', 'true');
if (this.includeGebruik) params.set('gebruik', 'true');
const url = `/index.php/apps/softwarecatalog/api/archimate/export/organization/${orgUuid}?${params}`;
const response = await fetch(url);
```

The frontend also needs checkboxes for the three toggle options (modules, deelnames, gebruik).

## Security Considerations

- **Auth**: The endpoint stays behind Nextcloud auth (no `#[PublicPage]` annotation). Only authenticated users can trigger exports.
- **RBAC bypass**: Deelname queries intentionally bypass RBAC (`_rbac: false`) to see gebruik from other organisations. This is the intended behavior — same pattern already used in ViewService.
- **Path traversal**: The `organizationUuid` path parameter should be validated as a UUID format to prevent injection.
- **Memory**: Large exports (Zeist with 271 packages) are generated in-memory. The existing approach uses `memory_limit = 4G` in the controller. No change needed.

## Trade-offs

### GET vs POST for export

**Chosen: GET** — An export is idempotent and safe (reads data, produces a file). GET is the semantically correct HTTP method. It enables browser-native downloads, bookmarking, and caching.

**Alternative: Keep POST** — POSTs can have larger request bodies, but we only need a UUID + 3 booleans, well within URL length limits. POST was rejected because it's semantically wrong for a read operation.

### Separate folders vs flat structure

**Chosen: Separate folders per type** (`Gebruikt`, `Aangeboden`, `Deelnames`) — Gives architects clear visibility into which applications come from which relationship type. Makes it easy to filter in Archi.

**Alternative: Single folder** — Simpler but loses the distinction between own applications and participations. Architects would need to inspect properties to tell them apart.

### Query parameter defaults

**Chosen: All false except modules** — `modules=true` by default preserves current behavior. Deelnames and gebruik must be explicitly requested. This prevents unexpected large exports and keeps backward compatibility for existing integrations.

**Alternative: All true by default** — Would give the "complete" export by default, but could be slow for orgs with many deelnames and could surprise users who expect the current output format.
