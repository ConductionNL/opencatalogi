---
status: proposed
---

# Forum Standaardisatie "Pas toe of leg uit" Compliance Specification

## Purpose

Defines how government organizations register which open standards (from the Forum Standaardisatie list of 115) they apply to their IT systems, document deviations with evidence, and generate annual compliance reports in the format expected by Forum Standaardisatie. This enables continuous monitoring of the "pas toe of leg uit" (apply-or-explain) regime across the Dutch public sector.

## Context

The Forum Standaardisatie maintains an authoritative list of 115 open standards that all government organizations must apply or formally explain why they cannot. Currently, compliance is tracked via manual annual surveys, leaving no real-time visibility. This spec integrates that list into opencatalogi and provides:

- Automatic import of the Forum list via API
- Per-application/component/register assignment of standards with status and reasoning
- Evidence validation (optional, configurable per domain)
- Annual rapportage generation in Forum-expected format
- Revision cycles with automatic reminders
- Public API for transparency (anonymized by default)

**Relation to existing specs:**
- Builds on OpenRegister's ObjectService for persistence (Standaard, Toepassing, Evidence, Rapportage objects)
- Extends softwarecatalog (Applicatie/Component) with `toepassingen` field
- Integrates with openconnector (Sources/Adapters can have Toepassingen)
- Uses docudesk for PDF generation and cryptographic signature
- Integrates with gemma-gegevenscatalogus for NEN 2660 mapping validation
- Publishes benchmarks via mydash KPI widgets
- Links evidence to decidesk decisions (formal exemptions)

**Relation to existing entities:**
- `Standaard` — OpenRegister object in "Forum Standaardisatie" register, schema "Standaard"
- `Toepassing` — OpenRegister object, polymorphic reference to Applicatie/Component/Register/Source
- `Evidence` — OpenRegister object, references FileService for artifact storage
- `Rapportage` — OpenRegister object, aggregates all Toepassingen for a period
- `Applicatie` / `Component` — extended with `toepassingen` array field

## Requirements

### Requirement: REQ-FOR-001 — Initiële import en periodieke sync met Forum Standaardisatie

The system MUST import the complete list of 115 standards from Forum Standaardisatie on first installation and automatically sync weekly to detect additions, removals, and status changes.

#### Scenario: Initial import on fresh installation
- GIVEN a fresh installation with no Standaard records
- AND Forum Standaardisatie API is accessible at `https://www.forumstandaardisatie.nl/api/standaarden`
- WHEN an admin runs `forum-sync --initial` (or system runs during setup)
- THEN the system MUST retrieve all 115 Standaard records from the API within 5 minutes
- AND MUST create OpenRegister objects in the "Forum Standaardisatie" register with schema "Standaard"
- AND MUST populate fields: `naam`, `volledigeNaam`, `forumId`, `categorie`, `status`, `domein`, `eigenaar`, `versie`, `webURL`, `specificatieURL`, `verplichteToepassingsgebied`, `functioneleToepassing`, `werkingsgebied`, `datumOpname`, `datumHerbevestiging`, `vervangenDoor`, `relatieMetAndereStandaarden`
- AND MUST set `lastSyncedAt` to current timestamp
- AND MUST NOT create duplicate records if re-run with same data

#### Scenario: Weekly sync detects new standard
- GIVEN a regular sync cron running every 7 days
- AND Forum Standaardisatie has added a new standard (e.g., "NEN 7517 Medisch dossier delen")
- WHEN the weekly sync executes
- THEN the system MUST detect the new standard within the API response
- AND MUST create a new Standaard object with `status: "in-procedure"` (or as returned by API)
- AND MUST send a notification to the CISO with subject "Nieuw Forum-standaard gedetecteerd: NEN 7517" including the standard's `naam`, `domein`, `werkingsgebied`, and link to detail view
- AND MUST update `lastSyncedAt` on all synced Standaard records

#### Scenario: Weekly sync detects status change
- GIVEN a Standaard "IPv6 extensie X" currently has `status: "in-procedure"`
- AND Forum Standaardisatie has moved it to `status: "opname"`
- WHEN the weekly sync executes
- THEN the system MUST detect the status change
- AND MUST update the Standaard record's `status` field to "opname"
- AND MUST send a notification to the CISO with subject "Forum-standaard status gewijzigd: IPv6 extensie X nu verplicht" including the old/new status and asking to review all related Toepassingen
- AND MUST NOT modify Toepassing records automatically (human review required)

#### Scenario: Forum API is temporarily unavailable
- GIVEN the weekly sync is scheduled
- AND the Forum API is returning HTTP 503 Service Unavailable
- WHEN the sync request executes
- THEN the system MUST catch the failure
- AND MUST retry with exponential backoff: 5 minutes, 1 hour, 4 hours (3 attempts total over ~24 hours)
- AND if all 3 retries fail, MUST send an alert to the CISO with subject "Forum Standaardisatie sync mislukt — handmatige check vereist"
- AND MUST NOT corrupt or delete existing Standaard records

---

### Requirement: REQ-FOR-002 — Per Applicatie aangeven welke standaarden van toepassing zijn

An admin MUST be able to register which standards apply to each application, with status (Toegepast / Gedeeltelijk / Niet / N.v.t.) and documented reasoning for non-application.

#### Scenario: Admin opens Applicatie detail and selects applicable standards
- GIVEN an Applicatie detail view with "Standaarden" tab
- WHEN the tab loads for the first time
- THEN the system MUST show all 115 Forum standards in a searchable/filterable list
- AND MUST display suggested standards based on the Applicatie's `type` field (e.g., a webapplicatie automatically suggests TLS 1.3, HTTPS, WCAG 2.2, SAML 2.0)
- AND MUST show a badge "Nog geen Toepassingen ingesteld" if no standards are yet registered for this app
- AND each standard row MUST have an "Toepassen" or "Status wijzigen" button

#### Scenario: Admin marks a standard as "Toegepast" with evidence
- GIVEN the standards list is open
- WHEN the admin clicks "Toepassen" on "TLS 1.3"
- THEN the system MUST open a ToepassingFormDialog with fields:
  - `status` dropdown (default: "toegepast")
  - `toelichting` text (optional initially, required for "niet-toegepast"/"gedeeltelijk")
  - `evidence` file upload (if evidence is required for the standard's domein; see REQ-FOR-004)
  - `revisieDatum` date picker (default: today + 12 months)
  - `verantwoordelijke` person picker (default: current user)
- AND when the admin clicks "Opslaan", MUST validate:
  - IF `status: "niet-toegepast"` THEN `afwijkingReden` AND `afwijkingsToelichting` are required
  - IF evidence required for this standard's domein AND none uploaded THEN show error "Evidence verplicht"
  - IF `revisieDatum` is in the past THEN show error "Revisiedatum moet in toekomst liggen"
- AND on successful save, MUST:
  - Create an OpenRegister Toepassing object with all fields populated
  - Set `vastgesteldOp: now()`, `vastgesteldDoor: currentUser`
  - Link the Toepassing to the Applicatie via the `toepassingen` array field
  - Return user to the Applicatie detail view with inline success message "Standaard TLS 1.3 als toegepast geregistreerd"

#### Scenario: Admin marks a standard as "Niet-toegepast" with reasoning
- GIVEN the standards list is open
- WHEN the admin clicks "Toepassen" on "IPv6"
- THEN opens the ToepassingFormDialog
- AND the admin selects `status: "niet-toegepast"`
- AND the system MUST reveal fields:
  - `afwijkingReden` dropdown with options: "kosten", "geen-leverancier-ondersteuning", "technisch-niet-mogelijk", "in-realisatie", "anders"
  - `afwijkingsToelichting` required text field
  - `eindstandDatum` optional date (expected remediation date)
- AND when saved, MUST:
  - Create Toepassing with `status: "niet-toegepast"`, reason, and explanation populated
  - If `eindstandDatum` is set, MUST add a reminder task for the date's 30-days-prior and on the date itself
  - Show inline message "Afwijking van IPv6 geregistreerd — revisie vereist op [date]"

---

### Requirement: REQ-FOR-003 — Status-indicators in lijst- en detailweergaves

All application/component list views and detail pages MUST visually indicate standards compliance status.

#### Scenario: Application list view shows compliance mini-indicator
- GIVEN the Applicatie listview is open (e.g., softwarecatalog's applications table)
- WHEN a row is rendered for an Applicatie
- THEN the system MUST display a new column "Standaarden" (or integrate into existing row) showing:
  - A mini-badge with format "12/15" (number toegepast / total applicable)
  - A color-coded circle: GREEN if ≥90%, ORANGE if 70–89%, RED if <70%
  - Tooltip on hover showing breakdown: "12 Toegepast, 2 Gedeeltelijk, 1 Niet-van-toepassing"
- AND this MUST work for all rows even if standards are still being registered (show 0/n until fully reviewed)
- AND clicking the badge MUST navigate to the Applicatie detail "Standaarden" tab

#### Scenario: Application detail "Standaarden" tab shows full table
- GIVEN an Applicatie detail view
- WHEN the user clicks the "Standaarden" tab
- THEN the system MUST display a table with rows per Standaard (sorted by domein, then alphabetically), columns:
  - **Standaard naam** — "TLS 1.3"
  - **Domein** — "beveiliging"
  - **Status badge** — "Toegepast" (green), "Gedeeltelijk" (orange), "Niet-toegepast" (red), "Niet van toepassing" (gray)
  - **Toelichting** — snippet of `toelichting` or `afwijkingsToelichting`
  - **Vastgesteld op** — date
  - **Revisie vereist** — `revisieDatum`, with visual warning (⚠️) if within 30 days
  - **Actions** — "Bewerk", "Verwijder", "Bekijk evidence"
- AND clicking "Bekijk evidence" MUST open an EvidenceViewDialog showing all Evidence records for that Toepassing

#### Scenario: Public catalogue view shows anonymized status
- GIVEN a citizen/journalist accesses the public catalogue page (e.g., via a public-facing view)
- WHEN the page loads
- THEN the system MUST show applications/components with:
  - Application name
  - Standards compliance percentage (same color-coding as internal view)
  - Generic "Toegepast / Niet-toegepast / Niet van toepassing" counts (no individual details)
  - NO internal verantwoordelijken, NO evidence details, NO afwijkingsredenen
- AND a note: "For detailed compliance information, contact [organization email]"

---

### Requirement: REQ-FOR-004 — Verplichte audit-hook met evidence-link voor toegepaste standaarden

The system MUST allow optional or mandatory Evidence collection for specific standard categories, configurable per `domein`.

#### Scenario: Evidence required for beveiligingsstandaarden
- GIVEN app settings have `evidenceRequired: true` for domein "beveiliging"
- AND an admin is registering TLS 1.3 (which belongs to domein "beveiliging")
- WHEN the admin clicks "Toepassen" and selects `status: "toegepast"`
- AND clicks "Opslaan" without uploading Evidence
- THEN the system MUST show error: "Evidence verplicht voor beveiligingsstandaarden. Voeg een testrapport, certificaat of configuratie-export toe."
- AND MUST NOT save the Toepassing

#### Scenario: Admin uploads Evidence artifact
- GIVEN the ToepassingFormDialog with evidence upload field visible
- WHEN the admin clicks "Bestand kiezen" and selects a file (e.g., "SSL_Labs_Report_example.com.pdf")
- THEN the system MUST:
  - Display filename and file size
  - Compute SHA256 hash of the file in the browser
  - On save, upload to FileService with `retentionYears: 7` (per Archiefwet)
  - Create an Evidence object with fields:
    - `type: "testresultaat"` (auto-inferred from .pdf extension or user-selected)
    - `naam: "SSL_Labs_Report_example.com"`
    - `bestand: "{fileService-uri}"`
    - `sha256: "{computed-hash}"`
    - `geldigVan: today()`
    - `geldigTot: null` (or auto-set for dated certs: "+365 days" for test reports)
    - `uploadedAt: now()`
    - `uploadedBy: currentUser`
  - Link the Evidence to the Toepassing via `evidence` array

#### Scenario: System detects expired Evidence
- GIVEN a Toepassing with Evidence where `geldigTot: "2026-03-01"` and today is 2026-05-22
- WHEN the daily cron runs
- THEN the system MUST:
  - Query all Evidence records with `geldigTot < today()`
  - For each expired Evidence, flag its parent Toepassing with a warning indicator
  - Send notification to the `verantwoordelijke`: "Evidence verlopen voor TLS 1.3 toepassing op [App] — hercertificering vereist"
  - Show ⚠️ icon in the detail view's Toepassingen table next to the standard

---

### Requirement: REQ-FOR-005 — Jaarlijkse rapportage-generator

The system MUST generate annual compliance reports in the format expected by Forum Standaardisatie.

#### Scenario: CIO generates 2026 rapport
- GIVEN a CIO opens `/rapportage/genereer` (new route)
- WHEN the page loads, MUST show:
  - Year selector (dropdown: 2026, 2025, etc.) with default: current year
  - "Genereer rapport" button
  - Previously generated rapportages in a list (name, date, status, download link)
- WHEN the CIO selects year 2026 and clicks "Genereer rapport"
- THEN the system MUST:
  - Aggregate all Toepassingen objects for alle Applicaties/Componenten/Registers/Sources owned by the organization (via RBAC: organization_id filter)
  - Count per status: `toegepast`, `gedeeltelijk-toegepast`, `niet-toegepast`, `niet-van-toepassing`
  - Create a Rapportage object with fields:
    - `jaar: 2026`
    - `organisatie: currentOrganization`
    - `periode: "2026"` (or "2026-Q4" if quarterly)
    - `gegenereerdOp: now()`
    - `gegenereerdDoor: currentUser`
    - `status: "concept"`
    - `metrics: { totaalStandaarden: 115, toegepast: 87, gedeeltelijk: 12, niet: 10, nvt: 6 }`
  - Store reference to all Toepassingen used in aggregation (for audit trail)
  - Redirect to rapportage detail view with preview

#### Scenario: CIO reviews and approves rapport
- GIVEN the generated rapportage is displayed
- WHEN the CIO opens the rapportage detail view
- THEN MUST show:
  - **Summary metrics** in cards: "87 Toegepast (75.6%)", "12 Gedeeltelijk (10.4%)", "10 Niet-toegepast (8.7%)", "6 Niet van toepassing (5.2%)"
  - **Per-domein breakdown** in chart: domein (beveiliging, toegankelijkheid, etc.) on x-axis, % applied on y-axis
  - **Detailed table**: all 115 standaarden with application count per status
  - **PDF preview** (generated by docudesk integration) showing formatted report
  - **"Vaststellen" button** (visible only if `status: "concept"`)
- WHEN the CIO clicks "Vaststellen"
- THEN MUST show a dialog:
  - Checkbox: "Ik bevestig dat deze rapportage nauwkeurig is en onder mijn verantwoordelijkheid"
  - Text field: "Volle naam van vaststellende CIO/bestuurder"
  - Buttons: "Bevestigen", "Annuleren"
- WHEN the CIO confirms
- THEN MUST:
  - Update Rapportage: `status: "vastgesteld"`, `vastgesteldDoor: "[name]"`, `vastgesteldOp: now()`
  - Integrate with docudesk: request cryptographic signature of the PDF using organization's certificate
  - Store signed PDF in `pdfBestand` field
  - Prevent future edits (mark record immutable)
  - Show message: "Rapportage vastgesteld en ondertekend. U kunt deze nu indienen bij Forum Standaardisatie."

#### Scenario: CIO exports rapport for Forum submission
- GIVEN the Rapportage is vastgesteld
- WHEN the CIO clicks "Exporteren" or "Indienen"
- THEN MUST offer downloads:
  - **PDF** (signed, A4 format, huisstijl, includes metrics summary and per-domain breakdown) — suitable for email to Forum
  - **JSON** (Forum Standaardisatie API format, structure: `{ "jaar": 2026, "organisatie": "...", "domeinBreakdown": {...}, "standaardenStatus": [...] }`) — suitable for API indiening

---

### Requirement: REQ-FOR-006 — Revisie-cyclus en herinnering

Every Toepassing MUST have a revision date and automatic reminders MUST be sent as the date approaches.

#### Scenario: Revisie reminder 30 days before deadline
- GIVEN a Toepassing with `revisieDatum: "2026-06-01"` and `verantwoordelijke: [user-id]`
- WHEN the daily cron runs on 2026-05-01 (30 days before)
- THEN the system MUST:
  - Query all Toepassingen with `revisieDatum` in the range `[today+29, today+31]`
  - Send notification to each `verantwoordelijke` with subject: "Revisie vereist over 30 dagen: TLS 1.3 op [App]"
  - Notification body: "Standaard TLS 1.3 moet op [date] opnieuw worden geverifieerd. [Applicatie]. Klik hier om revisie uit te voeren."
  - Include a deep link to the Toepassing edit dialog

#### Scenario: Overdue revisie escalates to CISO
- GIVEN a Toepassing with `revisieDatum: "2026-05-01"` (overdue)
- WHEN the daily cron runs on 2026-05-02
- THEN the system MUST:
  - Query all Toepassingen with `revisieDatum < today()`
  - For each overdue record, update status to `"revisie-vereist"` (new enum value)
  - Send escalation notification to the CISO (configurable role) with subject: "ESCALATIE: Overdue revisie TLS 1.3 op [App]"
  - Escalation notification MUST include: standard name, app name, overdue days, verantwoordelijke email, deep link
  - Show ⚠️ escalation badge in the detail view next to the standard

#### Scenario: Verantwoordelijke performs revisie
- GIVEN a Toepassing detail with "Revisie uitvoeren" button visible
- WHEN the verantwoordelijke clicks the button
- THEN MUST open a dialog:
  - Message: "Is deze standaard nog steeds van toepassing op [App]?"
  - Radio buttons: "Ja, nog steeds van toepassing", "Nee, niet meer van toepassing", "Nog steeds niet van toepassing"
  - If "Ja": show confirmation and save
  - If changed status: require `afwijkingReden`, `afwijkingsToelichting`, and optionally new Evidence
- WHEN confirmed with "Ja"
- THEN MUST:
  - Set `vastgesteldOp: now()`, `revisieDatum: today() + 12 months`
  - Reset `revisie-vereist` status to original status
  - Send confirmation notification: "Revisie bevestigd voor TLS 1.3 op [App] — volgende revisie: [new-date]"

---

### Requirement: REQ-FOR-007 — Inkoopondersteuning — standaarden voorschrijven bij aanbesteding

When creating a procurement request (aanbesteding) for new software, the system MUST recommend applicable standards.

#### Scenario: Inkoper creates aanbesteding for zorgapplicatie
- GIVEN an inkoper opens a new aanbesteding form in opencatalogi's procurement module
- WHEN the form shows a "Type applicatie" dropdown
- AND the inkoper selects "Zorgapplicatie"
- THEN the system MUST:
  - Query all Standaard records and filter by `werkingsgebied` containing org's scope
  - Auto-suggest standards relevant to "zorgapplicatie" type (e.g., by tagging or domain matching): NEN 7510, TLS 1.3, SAML 2.0, WCAG 2.2, Digikoppeling
  - Display suggestions in a checklist: each standard with name, eigenaar, status, webURL
  - Default: all suggestions are checked
  - Allow the inkoper to uncheck irrelevant standards
- AND provide text: "Forum Standaardisatie voorschrijft deze standaarden als 'van toepassing' voor zorgapplicaties."

#### Scenario: Aanbesteding publishes with standards-blok
- GIVEN the inkoper has configured applicable standards
- WHEN the inkoper publishes the aanbesteding
- THEN the system MUST:
  - Generate a "Vereiste standaarden"-section in the aanbestedingstekst with:
    - Introduction: "Op grond van de Instructie Rijksdienst en artikel 3 van de Wet digitale overheid dient deze applicatie de volgende open standaarden toe te passen:"
    - List of standards (naam, link to forumstandaardisatie.nl detail page)
    - Boilerplate: "Afwijkingen van deze standaarden dienen te worden verantwoord conform het 'pas toe of leg uit'-regime."
  - Insert this block into the tender document (e.g., as a generated section in a Word/PDF)
  - Store reference to applicable standards in the aanbesteding record (for later link to successful tenderer)

#### Scenario: New applicatie created from successful aanbesteding
- GIVEN a tenderer wins the aanbesteding and a new Applicatie is created in the catalogus
- WHEN the Applicatie creation wizard shows "Gekoppelde aanbesteding" or similar
- AND the user selects the winning aanbesteding
- THEN the system MUST:
  - Auto-populate the Applicatie's `toepassingen` field with the aanbesteding's recommended standards
  - Set all auto-populated Toepassingen to `status: "in-realisatie"` (default, can be overridden)
  - Show message: "Uit aanbesteding [name]: 5 standaarden toegevoegd. Voeg evidence toe en zet op 'toegepast' als implementatie voltooid."

---

### Requirement: REQ-FOR-008 — Cross-organisatie benchmark (geanonimiseerd)

The system MUST provide anonymized cross-organization compliance benchmarks for consenting organizations.

#### Scenario: Organization opts into benchmarking
- GIVEN the CISO is on the "Instellingen" → "Forum Standaardisatie" page
- WHEN a checkbox is visible: "Deelnemen aan geanonimiseerde benchmarking (helpt het Forum bij beleidsevaluatie)"
- AND the CISO checks the box
- THEN the system MUST:
  - Set organization's setting `benchmarkingOptIn: true`
  - Send annual aggregated metrics to Forum (or store locally for Forum export, depending on architecture):
    - Toepassing counts per domein and per standard, **without organization name or specific app names**
    - Anonymized as "Org-XX" (random ID)
  - Show confirmation: "Bedankt! Uw geanonimiseerde gegevens helpen Forum Standaardisatie het beleid beter vorm te geven."

#### Scenario: CIO views benchmarks
- GIVEN the benchmarkingOptIn is true
- WHEN the CIO opens `/benchmark` dashboard
- THEN the system MUST show:
  - **Percentile comparison** per standard: "TLS 1.3: Uw organisatie 92% | Mediaan 87% | P75 94%"
  - **Domein-level comparison**: bar chart showing org's average vs. peer averages per domein
  - **Trend over time** (if multiple years of data): line chart showing org's % applied per domein over 3 years
  - Footer: "Data includes X participating organizations (anonymized)"
  - No competitor identification; data anonymized

#### Scenario: Organization opts out of benchmarking
- GIVEN benchmarkingOptIn is true
- WHEN the CISO unchecks the checkbox
- THEN the system MUST:
  - Stop sending new aggregate data
  - Retain existing benchmark history (for own organizational learning) but do not share further updates
  - Show message: "U bent uitgeschreven. Uw historische benchmark-gegevens blijven zichtbaar voor uw eigen analyse."

---

### Requirement: REQ-FOR-009 — Importeren van nieuwe standaarden via Forum API

When Forum Standaardisatie adds a new standard, the system MUST detect and flag it for review.

#### Scenario: New standard detected in weekly sync
- GIVEN the weekly sync is running (per REQ-FOR-001)
- AND Forum API returns a new Standaard "NEN 7517 Medisch dossier delen"
- WHEN the sync processes the response
- THEN the system MUST:
  - Create Standaard object with all fields from API
  - Set `status: "new"` (internal flag, separate from Forum's status) or similar marker
  - Send notification to CISO: "Nieuwe Forum-standaard: NEN 7517 Medisch dossier delen (domein: beveiliging, werkingsgebied: zorgorganisaties)"
  - On the Standaard detail page, show banner: "NIEUW: Deze standaard is recent toegevoegd door Forum Standaardisatie. Controleer of deze van toepassing is op uw applicaties."

#### Scenario: CISO reviews which apps should apply new standard
- GIVEN the new Standaard detail page is open
- WHEN the page loads
- THEN the system MUST show:
  - Standard full details (naam, domein, eigenaar, werkingsgebied, specification URL)
  - **"Relevante applicaties"** section with suggestions:
    - Query all Applicaties/Componenten/Registers with `type` matching the new standard's `werkingsgebied` or domein (e.g., if NEN 7517 is for "zorgorganisaties", suggest all Applicaties tagged "zorg")
    - Show list with "Markeer als van toepassing" button per item
  - **"Handmatig toewijzen"** button to manually link the standard to specific items

#### Scenario: Standard status changes from in-procedure to opname
- GIVEN weekly sync detects status change "in-procedure" → "opname"
- WHEN the sync updates the Standaard record
- THEN the system MUST:
  - Query all Applicaties with `toepassingen` referencing this Standaard
  - For those with Toepassing `status: "niet-van-toepassing"` (and NO `afwijkingReden`), send CISO alert: "Standaard [name] is nu verplicht. [X] applicaties zijn gemarkeerd als 'niet van toepassing' — controleer of deze nog geldig zijn."

---

### Requirement: REQ-FOR-010 — Publieke API voor transparantie

Toepassings-status MUST be available via a public API for citizens, journalists, and authorized auditors.

#### Scenario: Burger/journalist calls public endpoint
- GIVEN endpoint `GET /api/public/transparantie/toepassingen` is available
- WHEN a burger or journalist calls the endpoint without authentication
- THEN the system MUST return:
  - Anonymized organization name (e.g., "Gemeente-XX") OR full name if org has opted public
  - List of Applicaties (name only, no owner details)
  - For each Applicatie: standards compliance summary ("12/15 standards implemented", % applied per domein)
  - Explicit note: "Internal owners and evidence details are confidential — contact [email] for audit requests"
  - HTTP 200 with JSON response

#### Scenario: Organization hides specific applications
- GIVEN CISO has "Publieke API"-instellingen open
- WHEN a checkbox is visible per Applicatie: "Verberg in publieke API"
- AND the CISO checks the box for "Interne HR-tool"
- THEN the system MUST:
  - Exclude that Applicatie from public API responses
  - Include a metadata field in the response: `"applicationsHidden": 1`
  - Do not reveal which apps are hidden (no list of hidden names)

#### Scenario: Auditor accesses full data via authorized API
- GIVEN an auditor (e.g., from Algemene Rekenkamer) has an OAuth2 token with `role: "auditor"` issued by Logius or similar
- WHEN the auditor calls `GET /api/public/transparantie/toepassingen?auditor=true`
- THEN the system MUST:
  - Verify the OAuth2 token (issuer, signature, role claim)
  - Return full dataset including:
    - Organization name
    - All Applicaties (including public-api-hidden ones)
    - For each Toepassing: `status`, `afwijkingReden`, `afwijkingsToelichting`, `verantwoordelijke` name, `vastgesteldOp`
    - Evidence metadata (type, name, hash, validated-on, expiry date) — NOT the file contents unless auditor has separate file-access token
  - Log the access in AuditTrail for transparency
  - Return HTTP 200 with complete JSON dataset

#### Scenario: Evidence file access requires additional authorization
- GIVEN an auditor wants to download an Evidence file
- WHEN the auditor calls `GET /api/public/transparantie/evidence/{evidenceId}/file?token={fileToken}`
- THEN the system MUST:
  - Verify the `fileToken` is valid (separate from API token, issued per-file or per-audit-engagement)
  - Download the file from FileService with metadata preserved (hash, uploaded-by, uploaded-on)
  - Return HTTP 200 with Content-Type matching the Evidence mimeType
  - If token is invalid or expired, return HTTP 403 Forbidden

---

## Requirements Summary Matrix

| REQ | Title | Acceptance | Priority |
|-----|-------|-----------|----------|
| REQ-FOR-001 | Forum API sync | Weekly sync + initial import | CRITICAL |
| REQ-FOR-002 | Per-app standard registration | UI + CRUD for Toepassingen | CRITICAL |
| REQ-FOR-003 | Status indicators | Badges in list/detail views | HIGH |
| REQ-FOR-004 | Evidence validation | Configurable per-domein | HIGH |
| REQ-FOR-005 | Rapportage generation | Forum-format PDF + JSON | CRITICAL |
| REQ-FOR-006 | Revision reminders | Daily cron + notifications | HIGH |
| REQ-FOR-007 | Procurement support | Standard suggestions in aanbesteding | MEDIUM |
| REQ-FOR-008 | Cross-org benchmarking | Opt-in aggregation + dashboard | MEDIUM |
| REQ-FOR-009 | New standard detection | Auto-flagging + suggestions | HIGH |
| REQ-FOR-010 | Public API | Anonymized + auditor-authorized | HIGH |
