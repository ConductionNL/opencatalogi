# Design: Forum Standaardisatie "Pas toe of leg uit"-Registratie

## Context

The Dutch central government mandates that all government organizations apply 115 specified open standards to their IT systems, or publicly explain why they cannot. This "pas toe of leg uit" (apply-or-explain) regime is embedded in law (Wet digitale overheid, articles 3–5) and enforced through annual monitoring by Forum Standaardisatie. Currently, compliance is tracked manually and reported ad-hoc, leaving organizations unable to demonstrate adherence and regulators without real-time insight.

This spec adds standards registration to opencatalogi, linking standards to applications/components/registers/integrations with compliance status, evidence, and automated reporting.

## Goals / Non-Goals

**Goals:**
- Import all 115 Forum standards via API on install; sync weekly for deltas
- Per-application/component/register/source: assign standards with status (Toegepast / Gedeeltelijk / Niet / N.v.t.) + reasoning + evidence
- Aggregate compliance across the organization and generate jaarlijkse rapportage in Forum-expected PDF/JSON format
- Revision cycle: 30-day reminder before revisie-datum; escalation to CISO if overdue
- Optional evidence validation (configurable per domein/categorie) — some standards require audit artifacts, others don't
- Public API for transparency (anonymized by default; full data for authorized auditors/oversight bodies)
- Procurement assistance: suggest applicable standards by type; generate standards-blok for aanbestedingstekst

**Non-Goals:**
- Write-back to Forum API — Forum owns the list; feedback via official process only
- Real-time sync of individual Standaard records (weekly cadence sufficient)
- Custom workflow engine for approval chains (direct-approve pattern sufficient for v1)
- Multilingual standard names in database (future iteration if audit-scope broadens)

## Decisions

1. **Polymorphic Toepassing-object reference.** One Standaard can map to Applicatie, Component, Register, Source, or future types. Using `objectType` + `objectId` strings (polymorphic ref) avoids 5+ join tables and keeps Rapportage aggregation clean. Matches OpenRegister relation pattern.

2. **Evidence is optional-by-default, configurable-per-domein.** HTTPS/TLS/DNSSEC evidence is non-negotiable; governance standards (NEN 2082 recordsmanagement) accept policy documents. Configureerbare per-domein evidence rules in app settings.

3. **Jaarlijkse rapportage is immutable-when-vastgesteld.** Once a CIO/bestuurder approves and signs a rapportage (status: vastgesteld), the PDF is cryptographically signed and the record locked. Supports audit trails and compliance proof.

4. **Forum API is read-only master, no write-back.** Organizations cannot directly edit the standard list via this app; all changes go through official Forum process.

5. **Public API defaults to transparency (opt-out per app).** Matching the regime's intent: government IT should be visible by default. Administrators can hide specific applications (e.g., internal tools).

6. **Revision cycle is property-based, not workflow-based.** Every Toepassing has `revisieDatum` and `vastgesteldOp`. Daily cron checks and notifies; overdues escalate. No BPMN.

7. **Evidence storage uses object-storage with 7-year retention.** Aligns with Archiefwet 1995 minimum retention; SHA256 hashing and immutable storage prevent tampering.

8. **Cross-org benchmarking is opt-in and anonymized.** Organizations that consent to benchmarking see their percentile vs. peers; data published excludes organization/app names.

## Reuse Analysis

- **OpenRegister ObjectService** — primary persistence layer for Standaard, Toepassing, Evidence, Rapportage objects
- **FileService** — Evidence file upload, versioning, retention
- **SearchTrailService** — track which standards/apps are most-reviewed
- **AuditTrailService** — automatic before/after snapshots on all Toepassing changes; enables auditor queries
- **NotificationService** — revision reminders, sync alerts, evidence expiry warnings
- **AuthorizationService** — RBAC on Toepassingen per rol (CISO, CIO, IBD); auditor role for public API
- **CnDetailPage** — application/component detail view with "Standaarden" tab
- **CnFormDialog** — Toepassing/Evidence create/edit forms, auto-generated from schema
- **CnDataTable** — standards/toepassingen list with sorting/filtering
- **CnChartWidget** — dashboard widgets for compliance KPIs (% applied per domein, evidence expiry timeline)
- **ExportService** — PDF export of rapportage with docudesk integration for signature
- **ImportService** — Forum API response parsing and seed data loading

No duplication detected. All proposed capability either leverages existing OpenRegister/nextcloud-vue patterns or is new domain-specific logic.

## File Changes

**New files:**
- `lib/Settings/forum-standaardisatie_register.json` — schema definitions + 3–5 seed Standaard/Toepassing records
- `src/stores/forum.js` — Pinia store for Standaard/Toepassing/Evidence/Rapportage lists, filters, selection
- `src/views/StandardsListView.vue` — list of all Forum standards with search/filter
- `src/views/StandardDetailView.vue` — standard detail: name, categorie, domein, eigenaar, status, related standards, list of Toepassingen
- `src/views/ApplicationStandardsTabView.vue` — "Standaarden" tab within Applicatie detail, showing applicable Toepassingen with status badges
- `src/views/ComponentStandardsTabView.vue` — same for Components
- `src/views/ToepassingFormDialog.vue` — create/edit Toepassing with status selection, evidence upload, reasoning
- `src/views/EvidenceViewDialog.vue` — view/upload/validate evidence artifacts
- `src/views/RapportageGeneratorView.vue` — UI for generating jaarlijkse rapportage, preview, vaststelling, PDF download
- `src/views/ComplianceKpiDashboard.vue` — KPI cards for % applied per domein, overdue revisions, evidence expiry count
- `src/components/StandardComplianceIndicator.vue` — reusable mini-badge showing "12/15 standards" with color-coding
- `src/components/RevisionOverdueAlert.vue` — inline alert when revision-datum is overdue
- `src/composables/useForumSync.js` — weekly cron trigger, delta-detection, notification logic
- `src/composables/useRapportageGeneration.js` — aggregation, filtering, PDF generation
- `src/composables/useRevisionReminder.js` — daily check for 30-day-before reminders
- `src/api/forumStandardisatieApi.js` — Forum API client (https://forumstandaardisatie.nl/api/standaarden)
- `src/api/publicStandardsApi.js` — public `/api/transparantie/toepassingen` endpoint (anonymized by default)
- `docs/features/forum-standaardisatie-compliance.md` — end-user and admin documentation
- `tests/unit/stores/forum.test.js` — Pinia store tests
- `tests/unit/composables/useForumSync.test.js` — sync logic tests
- `tests/unit/composables/useRapportageGeneration.test.js` — aggregation logic tests
- `tests/integration/api/forumStandardisatieApi.integration.test.js` — Forum API mock tests

**Modified files:**
- `lib/Settings/opencatalogi_register.json` — extend Applicatie and Component schemas with `toepassingen` (array of Toepassing refs)
- `src/views/ApplicationDetailView.vue` — add "Standaarden" tab navigation
- `src/views/ComponentDetailView.vue` — add "Standaarden" tab navigation
- `src/views/ApplicationListView.vue` — add StandardComplianceIndicator to each row
- `src/views/ComponentListView.vue` — add StandardComplianceIndicator to each row
- `src/stores/applications.js` — enhance with Toepassing deduplication/merge logic
- `src/stores/components.js` — enhance with Toepassing deduplication/merge logic
- `src/composables/useListView.js` — optional facet for "compliance-status-range" (0-30%, 30-70%, 70-90%, 90-100%)
- `.env.example` — add FORUM_STANDAARDISATIE_API_KEY, EVIDENCE_RETENTION_YEARS (default 7), EVIDENCE_REQUIRED_BY_DOMEIN (JSON config)

## Seed Data

**Standaarden** (3–5 examples, realistic Forum entries):

```json
{
  "@self": {
    "register": "Forum Standaardisatie",
    "schema": "Standaard",
    "slug": "tls-1-3"
  },
  "id": "uuid-1",
  "naam": "TLS 1.3",
  "volledigeNaam": "Transport Layer Security version 1.3",
  "forumId": "TLS 1.3",
  "categorie": "open-standaarden-met-status",
  "status": "opname",
  "domein": ["beveiliging", "koppelvlakken"],
  "eigenaar": "IETF",
  "versie": "RFC 8446",
  "webURL": "https://www.forumstandaardisatie.nl/standaarden/tls-1-3",
  "specificatieURL": "https://tools.ietf.org/html/rfc8446",
  "verplichteToepassingsgebied": "Alle internetverbindingen en HTTPS-koppelingen",
  "functioneleToepassing": "Veilige geëncrypteerde communicatie",
  "werkingsgebied": ["alle-overheden"],
  "datumOpname": "2022-06-01",
  "datumHerbevestiging": "2025-06-01",
  "relatieMetAndereStandaarden": [],
  "lastSyncedAt": "2026-05-22T10:30:00Z"
}
```

```json
{
  "@self": {
    "register": "Forum Standaardisatie",
    "schema": "Standaard",
    "slug": "wcag-2-2"
  },
  "id": "uuid-2",
  "naam": "WCAG 2.2",
  "volledigeNaam": "Web Content Accessibility Guidelines 2.2",
  "forumId": "WCAG 2.2",
  "categorie": "open-standaarden-met-status",
  "status": "herbevestigd",
  "domein": ["toegankelijkheid"],
  "eigenaar": "W3C",
  "versie": "2.2",
  "webURL": "https://www.forumstandaardisatie.nl/standaarden/wcag-2-2",
  "specificatieURL": "https://www.w3.org/WAI/WCAG22/",
  "verplichteToepassingsgebied": "Alle publieke webapplicaties en diensten",
  "functioneleToepassing": "Gelijke toegang voor alle burgers, inclusief mensen met beperkingen",
  "werkingsgebied": ["alle-overheden"],
  "datumOpname": "2023-01-15",
  "datumHerbevestiging": "2025-01-15",
  "relatieMetAndereStandaarden": [],
  "lastSyncedAt": "2026-05-22T10:30:00Z"
}
```

```json
{
  "@self": {
    "register": "Forum Standaardisatie",
    "schema": "Standaard",
    "slug": "nen-7510"
  },
  "id": "uuid-3",
  "naam": "NEN 7510:2024",
  "volledigeNaam": "Informatiebeveiliging in de zorg",
  "forumId": "NEN 7510",
  "categorie": "open-standaarden-met-status",
  "status": "opname",
  "domein": ["beveiliging"],
  "eigenaar": "NEN",
  "versie": "2024",
  "webURL": "https://www.forumstandaardisatie.nl/standaarden/nen-7510",
  "specificatieURL": "https://www.nen.nl/nen-7510",
  "verplichteToepassingsgebied": "Gezondheidszorginformatiesystemen in het publieke domein",
  "functioneleToepassing": "Bescherming van gevoelige gezondheidsgegevens",
  "werkingsgebied": ["alle-overheden"],
  "datumOpname": "2024-01-01",
  "datumHerbevestiging": null,
  "relatieMetAndereStandaarden": ["tls-1-3"],
  "lastSyncedAt": "2026-05-22T10:30:00Z"
}
```

**Toepassingen** (linked to seed Applicaties):

```json
{
  "@self": {
    "register": "Forum Standaardisatie",
    "schema": "Toepassing",
    "slug": "app-mijnoverheid-tls-1-3"
  },
  "id": "uuid-4",
  "standaard": {"register": "Forum Standaardisatie", "schema": "Standaard", "id": "uuid-1"},
  "object": {"register": "softwarecatalog", "schema": "Applicatie", "id": "app-mijnoverheid-uuid"},
  "objectType": "Applicatie",
  "objectId": "app-mijnoverheid-uuid",
  "status": "toegepast",
  "toelichting": "Webapplicatie draait op HTTPS met TLS 1.3, geverifieerd via SSL Labs rapport",
  "afwijkingReden": null,
  "afwijkingsToelichting": null,
  "eindstandDatum": null,
  "verantwoordelijke": {"register": "nextcloud", "schema": "User", "id": "ciso-uuid"},
  "vastgesteldOp": "2026-05-15T14:30:00Z",
  "vastgesteldDoor": {"register": "nextcloud", "schema": "User", "id": "ciso-uuid"},
  "geldigVan": "2026-05-15",
  "geldigTot": null,
  "revisieDatum": "2027-05-15"
}
```

```json
{
  "@self": {
    "register": "Forum Standaardisatie",
    "schema": "Toepassing",
    "slug": "app-openklant-wcag"
  },
  "id": "uuid-5",
  "standaard": {"register": "Forum Standaardisatie", "schema": "Standaard", "id": "uuid-2"},
  "object": {"register": "softwarecatalog", "schema": "Applicatie", "id": "app-openklant-uuid"},
  "objectType": "Applicatie",
  "objectId": "app-openklant-uuid",
  "status": "gedeeltelijk-toegepast",
  "toelichting": "Webinterface voldoet aan WCAG 2.2 AA; API nog niet volledig getest",
  "afwijkingReden": "in-realisatie",
  "afwijkingsToelichting": "API-testing is in progress, verwacht gereed begin Q3 2026",
  "eindstandDatum": "2026-07-31",
  "verantwoordelijke": {"register": "nextcloud", "schema": "User", "id": "iba-uuid"},
  "vastgesteldOp": "2026-04-20T10:00:00Z",
  "vastgesteldDoor": {"register": "nextcloud", "schema": "User", "id": "iba-uuid"},
  "geldigVan": "2026-04-20",
  "geldigTot": null,
  "revisieDatum": "2026-10-20"
}
```

