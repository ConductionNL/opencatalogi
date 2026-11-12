# Design: gemma-gegevenscatalogus

## Architecture Overview

The GEMMA Gegevenscatalogus integration adds five new schemas to opencatalogi's data model:

- **GemmaCatalogus**: Root object representing a GEMMA release (e.g., v3.0, v2.6)
- **GemmaObjecttype**: An object type from the catalogus (e.g., `Persoon`, `Verblijfsobject`)
- **GemmaAttribuut**: An attribute of an object type (e.g., `geboortedatum` on `Persoon`)
- **GemmaRelatie**: A relationship between two object types (e.g., `Persoon` → `Adres`)
- **GemmaMapping**: A mapping record linking a local openregister Schema to a GEMMA object type, with per-attribute transformations and validation status

The frontend browse UI integrates into the opencatalogi admin console and uses a standard React component library (NcCard, NcButton, etc. from Nextcloud). The import pipeline is a backend async job (PHP + database transactions) that parses SKOS Turtle/RDF into the schema set. Compliance reporting is a query-time aggregation over Registers and their Schemas' mapped GemmaMappings.

## Data Model

### Schema: GemmaCatalogus

| Field | Type | Required | Notes |
|-------|------|----------|-------|
| `id` | UUID | Yes | Primary key |
| `versie` | String | Yes | e.g., "3.0", "2.6" |
| `releaseDatum` | Date | Yes | Official release date from VNG |
| `status` | Enum | Yes | `concept`, `vastgesteld`, `vervallen` |
| `taal` | String | Yes | BCP-47 tag, default `nl-NL` |
| `bron` | URI | Yes | Link to VNG-published file |
| `importedAt` | DateTime | Yes | When import completed |
| `importedBy` | FK(User) | Yes | Admin who triggered import |
| `aantalObjecttypen` | Integer | Yes | Count of GemmaObjecttype records |
| `aantalAttributen` | Integer | Yes | Count of GemmaAttribuut records |
| `aantalRelaties` | Integer | Yes | Count of GemmaRelatie records |
| `wijzigingstype` | Enum | No | `major`, `minor`, `patch` (vs. previous) |
| `voorganger` | FK(GemmaCatalogus) | No | Reference to prior release version |

### Schema: GemmaObjecttype

| Field | Type | Required | Notes |
|-------|------|----------|-------|
| `id` | UUID | Yes | |
| `catalogus` | FK(GemmaCatalogus) | Yes | Which release this objecttype belongs to |
| `naam` | String | Yes | e.g., `Persoon`, `Verblijfsobject` |
| `nameSpace` | String | Yes | VNG URN namespace |
| `urn` | String | Yes | Canonical URN for cross-version matching |
| `definitie` | Text | Yes | Formal VNG-provided definition |
| `toelichting` | Text | No | Elaboration/context |
| `synoniemen` | Array(String) | No | Alternative names from GEMMA |
| `domein` | Enum | Yes | `BAG`, `BRP`, `BRK`, `BGT`, `WMO`, `JW`, `Zaak`, `Document`, `Belasting`, `Subsidie`, `Vergunning`, etc. |
| `subtype` | Enum | Yes | `object`, `gegevenselement`, `attribuutsoort`, `relatiesoort` |
| `parent` | FK(GemmaObjecttype) | No | Generalization reference |
| `abstract` | Boolean | No | Whether this is an abstract/mixin type |
| `herkomst` | Text | No | Legislation/registry source |
| `geldigVan` | Date | No | When GEMMA considers it valid (from) |
| `geldigTot` | Date | No | When GEMMA considers it valid (to) — null = ongoing |
| `vervangenDoor` | FK(GemmaObjecttype) | No | Deprecation pointer (if geldigTot is set) |

### Schema: GemmaAttribuut

| Field | Type | Required | Notes |
|-------|------|----------|-------|
| `id` | UUID | Yes | |
| `objecttype` | FK(GemmaObjecttype) | Yes | Parent object type |
| `naam` | String | Yes | e.g., `geboortedatum` |
| `definitie` | Text | Yes | VNG-provided definition |
| `datatype` | Enum | Yes | `string`, `integer`, `decimal`, `datum`, `datumtijd`, `boolean`, `code`, `opsomming`, `AN`, `N` |
| `formaat` | String | No | Regex or code/enumeration reference |
| `lengte` | String | No | e.g., `4..16`, `1`, `*` |
| `cardinaliteit` | Enum | Yes | `0..1`, `1..1`, `0..*`, `1..*` |
| `autoriteit` | String | No | Registry/authority (e.g., "Basisregister BRP") |
| `herkomstWetgeving` | String | No | Legal source |
| `kerngegeven` | Boolean | No | VNG-marked as core data element |
| `gevoeligheid` | Enum | No | `openbaar`, `persoonsgegeven`, `bijzonderPersoonsgegeven`, `strafrechtelijkPersoonsgegeven` |
| `voorbeelden` | Array(String) | No | Examples from VNG documentation |

### Schema: GemmaRelatie

| Field | Type | Required | Notes |
|-------|------|----------|-------|
| `id` | UUID | Yes | |
| `vanObjecttype` | FK(GemmaObjecttype) | Yes | Source object type |
| `naarObjecttype` | FK(GemmaObjecttype) | Yes | Target object type |
| `naam` | String | Yes | e.g., `heeftBewoners` |
| `definitie` | Text | Yes | VNG definition |
| `cardinaliteit` | String | No | e.g., `1..* : 0..*` (source : target) |
| `rol` | String | No | Forward role label |
| `omgekeerdeRol` | String | No | Reverse role label |
| `aggregatieType` | Enum | No | `associatie`, `aggregatie`, `compositie`, `generalisatie` |

### Schema: GemmaMapping

| Field | Type | Required | Notes |
|-------|------|----------|-------|
| `id` | UUID | Yes | |
| `gemmaObjecttype` | FK(GemmaObjecttype) | Yes | Target GEMMA object type |
| `localSchema` | FK(Schema/openregister) | Yes | Local schema being mapped |
| `mappingKwaliteit` | Enum | Yes | `volledig` (all required attrs mapped), `partieel` (some), `geen` (attempted but failed) |
| `attribuutMappings` | JSONB | No | Array of `{gemmaAttribuut: UUID, localProperty: string, transformatie: string, status: validated\|conflict\|suggested}` |
| `relatieMappings` | JSONB | No | Array of `{gemmaRelatie: UUID, localRelation: string, status: validated\|conflict\|suggested}` |
| `validatieStatus` | Enum | Yes | `gevalideerd`, `gewaarschuwd`, `conflict` |
| `gevalideerdOp` | DateTime | No | Last validation run |
| `gevalideerdDoor` | FK(User) | No | User who validated |
| `opmerkingen` | Text | No | Reviewer notes, deviations, justifications |
| `createdAt` | DateTime | Yes | |
| `updatedAt` | DateTime | Yes | |
| `status` | Enum | No | `active`, `vervallen` (soft-delete for audit trail) |

### Extensions to Existing Schemas

**Catalogus** (in openregister):
- Add `gemmaCompliant` (Boolean, optional) — Whether this catalogus claims GEMMA compliance
- Add `gemmaCompliancePercentage` (Decimal 0–100, optional) — Latest compliance score
- Add `actieveCatalogus` (FK to GemmaCatalogus, optional) — Which GEMMA release this catalogus maps to

**Schema** (in openregister):
- Add `gemmaObjecttype` (FK to GemmaObjecttype, optional) — Canonical GEMMA reference for this schema
- Add `gemmaMappingKwaliteit` (Enum, optional) — Denormalized mapping quality from latest GemmaMapping

## Seed Data

### Example 1: GemmaCatalogus (GEMMA 3.0)
```json
{
  "id": "uuid-gemma-3.0",
  "versie": "3.0",
  "releaseDatum": "2025-01-15",
  "status": "vastgesteld",
  "taal": "nl-NL",
  "bron": "https://github.com/VNG-Realisatie/Gemeentelijk-Gegevensmodel/releases/download/3.0/gemma-3.0.ttl",
  "importedAt": "2025-01-20T14:30:00Z",
  "importedBy": "admin-user-uuid",
  "aantalObjecttypen": 612,
  "aantalAttributen": 9847,
  "aantalRelaties": 3421,
  "wijzigingstype": "major",
  "voorganger": "uuid-gemma-2.6"
}
```

### Example 2: GemmaObjecttype (Persoon)
```json
{
  "id": "uuid-objecttype-persoon",
  "catalogus": "uuid-gemma-3.0",
  "naam": "Persoon",
  "nameSpace": "https://gemmaonline.nl/index.php/Objecttype:Persoon",
  "urn": "urn:vng:gemma:objecttype:persoon",
  "definitie": "Een mens, al dan niet ingeschreven in het gemeentelijk basisadministratie.",
  "toelichting": "Personen kunnen ingezetenen zijn, maar ook niet-ingezetenen met bepaalde relaties tot de gemeente.",
  "synoniemen": ["Individu", "Burger", "Inwoner"],
  "domein": "BRP",
  "subtype": "object",
  "parent": null,
  "abstract": false,
  "herkomst": "Basisregistratie Personen (BRP), Wet BAV",
  "geldigVan": "2025-01-15",
  "geldigTot": null,
  "vervangenDoor": null
}
```

### Example 3: GemmaAttribuut (geboortedatum on Persoon)
```json
{
  "id": "uuid-attr-geboortedatum",
  "objecttype": "uuid-objecttype-persoon",
  "naam": "geboortedatum",
  "definitie": "De dag waarop het natuurlijk persoon geboren is.",
  "datatype": "datum",
  "formaat": "YYYY-MM-DD",
  "lengte": "10",
  "cardinaliteit": "0..1",
  "autoriteit": "Basisregister BRP",
  "herkomstWetgeving": "Burgerlijke Stand; BRP Wetgeving",
  "kerngegeven": true,
  "gevoeligheid": "persoonsgegeven",
  "voorbeelden": ["1980-05-15", "1923-12-31"]
}
```

### Example 4: GemmaMapping (MijnPersoon → Persoon)
```json
{
  "id": "uuid-mapping-mijnpersoon",
  "gemmaObjecttype": "uuid-objecttype-persoon",
  "localSchema": "uuid-schema-mijnpersoon",
  "mappingKwaliteit": "partieel",
  "attribuutMappings": [
    {
      "gemmaAttribuut": "uuid-attr-geboortedatum",
      "localProperty": "birthDate",
      "transformatie": "string -> datum (ISO 8601)",
      "status": "validated"
    },
    {
      "gemmaAttribuut": "uuid-attr-voornamen",
      "localProperty": "givenName",
      "transformatie": "direct",
      "status": "validated"
    }
  ],
  "validatieStatus": "gewaarschuwd",
  "gevalideerdOp": "2025-02-10T10:00:00Z",
  "gevalideerdDoor": "architect-uuid",
  "opmerkingen": "Nog 4 verplichte attributen te mappen: achternaam, geboorteland, geslacht, nationaliteit.",
  "createdAt": "2025-01-25T09:15:00Z",
  "updatedAt": "2025-02-10T10:00:00Z",
  "status": "active"
}
```

### Example 5: GemmaRelatie (Persoon → Adres)
```json
{
  "id": "uuid-relatie-persoon-adres",
  "vanObjecttype": "uuid-objecttype-persoon",
  "naarObjecttype": "uuid-objecttype-adres",
  "naam": "woontAan",
  "definitie": "Een persoon kan op één of meerdere adressen wonen.",
  "cardinaliteit": "0..* : 0..*",
  "rol": "woontAan",
  "omgekeerdeRol": "isWoningvan",
  "aggregatieType": "associatie"
}
```

## User Journeys

### Journey 1: Data Architect Maps Schema to GEMMA
1. Opens openregister → Schema "MijnPersoon"
2. Clicks "Koppel aan GEMMA-standaard"
3. System suggests `Persoon` (match score 98%)
4. Selects `Persoon`, sees drag-drop canvas
5. Drags local property `birthDate` onto GEMMA attribute `geboortedatum`
6. System shows datatype mismatch warning (string vs. datum) with suggested transformation
7. Confirms transformation, saves mapping
8. System validates mapping, shows "Partieel" status (4/8 required attrs mapped)
9. See recommendation: "Map achternaam, geslacht, nationaliteit to complete"
10. Returns to mapping UI, maps remaining attributes
11. Saves; system auto-validates to "Volledig" status; schema gets "GEMMA-conform" badge

### Journey 2: Informatiemanager Reviews Compliance Report
1. Opens opencatalogi → Compliance Reports
2. Selects Register "Burgers"
3. System generates real-time report: "75% GEMMA-conform (12 of 16 schemas complete)"
4. Clicks "Details" → sees per-schema breakdown
5. Finds 3 schemas with "Partieel" mappings; 1 with none
6. Clicks "MijnInkomsten" (partieel); sees missing attributes and "auto-suggest fix" button
7. Sends link to data team: "Please complete these mappings by 2025-03-31"
8. Exports report as PDF for board meeting; attaches compliance attestation from softwarecatalog

### Journey 3: Developer Discovers New GEMMA Version
1. Admin publishes GEMMA 3.0 via import (new release after 2.6)
2. System detects changes: 80 object types modified, 12 deprecated, 25 new
3. Sends notifications to data architects with active mappings on changed object types
4. Developer opens "GEMMA 3.0 Migration Report"
5. Sees: "Your mapping `MijnPersoon → Persoon` is COMPATIBLE (no breaking changes)"
6. Another mapping `MijnZaak → Zaak` shows "CONFLICT: Zaak.eigenaar renamed to Zaak.verantwoordelijke"
7. Sees suggested auto-mapping fix (fuzzy match + URN history)
8. Accepts suggestion; mapping re-validates to "Volledig"
9. All mappings now "green"; compliance report auto-updates

## Integration Points

- **openregister Schema creation flow**: When user creates a new schema, suggest GEMMA object types and auto-populate attributes
- **openregister Object validation**: Hook into validation pipeline to optionally run GEMMA-level checks (strict/warn/off)
- **softwarecatalog**: Display GEMMA mapping proof on software product pages; expose compliance as claim/attestation
- **mydash KPI export**: Compliance percentages, unmapped schemas, avg mapping age
- **docudesk PDF export**: Render compliance reports with official letterhead and timestamps
- **openconnector**: Use GEMMA mappings as transformation vocabulary for external system integration
