# Tasks: Forum Standaardisatie "Pas toe of leg uit"-Registratie

## 1. Backend — Schema & Data Layer

- [ ] 1.1 Define Standaard schema in `lib/Settings/forum-standaardisatie_register.json` with fields: id, naam, volledigeNaam, forumId, categorie, status, domein, eigenaar, versie, webURL, specificatieURL, verplichteToepassingsgebied, functioneleToepassing, werkingsgebied, datumOpname, datumHerbevestiging, vervangenDoor, relatieMetAndereStandaarden, lastSyncedAt
- [ ] 1.2 Define Toepassing schema with fields: id, standaard (ref), object (polymorphic), objectType, objectId, status, toelichting, afwijkingReden, afwijkingsToelichting, eindstandDatum, verantwoordelijke, vastgesteldOp, vastgesteldDoor, geldigVan, geldigTot, revisieDatum
- [ ] 1.3 Define Evidence schema with fields: id, toepassing (ref), type, naam, bestand, mimeType, sha256, geldigVan, geldigTot, uitgevoerdDoor, referentie, uploadedAt, uploadedBy
- [ ] 1.4 Define Rapportage schema with fields: id, jaar, organisatie, periode, gegenereerdOp, gegenereerdDoor, status, vastgesteldDoor, vastgesteldOp, pdfBestand, jsonBestand, metrics (JSON), ingediendBij, terugkoppeling
- [ ] 1.5 Extend Applicatie schema: add `toepassingen` array field (refs to Toepassing objects)
- [ ] 1.6 Extend Component schema: add `toepassingen` array field (refs to Toepassing objects)
- [ ] 1.7 Add seed data to register: 3–5 realistic Standaard objects (TLS 1.3, WCAG 2.2, NEN 7510, Digikoppeling) with correct Forum IDs
- [ ] 1.8 Add seed data to register: 2–3 example Toepassing objects linking seed standards to seed applications

## 2. Backend — Forum API Integration

- [ ] 2.1 Create `src/api/forumStandardisatieApi.js` — HTTP client for `https://www.forumstandaardisatie.nl/api/standaarden` with error handling and retry logic
- [ ] 2.2 Implement initial import: `forumSync --initial` command that fetches all 115 standards and creates Standaard objects via ObjectService
- [ ] 2.3 Implement idempotency check: before creating Standaard, search for existing record by `forumId` using `ObjectService.searchObjects(_rbac: false, _multitenancy: false)`
- [ ] 2.4 Implement weekly sync cron (runs every 7 days) that fetches latest standard list and compares to local records
- [ ] 2.5 Delta detection: identify new Standaarden (forumId not in local DB), updated status/metadata, removed standards
- [ ] 2.6 On new standard detected: create Standaard object + send CISO notification with standard details and suggestion to review applications
- [ ] 2.7 On status change detected (e.g., in-procedure → opname): update Standaard + send CISO notification with old/new status
- [ ] 2.8 Implement retry logic: exponential backoff (5 min, 1 hour, 4 hours) on API failure + CISO alert after 3 failed retries
- [ ] 2.9 Update `lastSyncedAt` timestamp on all processed Standaard objects

## 3. Backend — Toepassing Registration & Evidence

- [ ] 3.1 Implement Toepassing creation via POST `/api/toepassingen` endpoint with full schema validation
- [ ] 3.2 Validate: if `status: "niet-toegepast"` or `"gedeeltelijk"`, then `afwijkingReden` and `afwijkingsToelichting` are required
- [ ] 3.3 Validate: if `revisieDatum` is in past, reject with error
- [ ] 3.4 On creation, set `vastgesteldOp: now()`, `vastgesteldDoor: currentUser`, `geldigVan: today()`
- [ ] 3.5 Link Toepassing to parent Applicatie/Component via polymorphic reference + update `toepassingen` array field
- [ ] 3.6 Implement evidence upload handler: POST `/api/toepassingen/{id}/evidence` with file multipart
- [ ] 3.7 Evidence handler: compute SHA256 hash of uploaded file, store via FileService with `retentionYears: 7`, create Evidence object
- [ ] 3.8 Implement evidence-requirement validation: if `evidenceRequired: true` for standard's domein and Evidence is missing, reject save
- [ ] 3.9 Implement evidence expiry check: daily cron queries Evidence with `geldigTot < today()`, flags parent Toepassing as ⚠️ warning
- [ ] 3.10 Send notification to `verantwoordelijke` when evidence expires

## 4. Backend — Revision Cycle & Reminders

- [ ] 4.1 Implement daily cron task that checks all Toepassingen for upcoming/overdue `revisieDatum`
- [ ] 4.2 30-day-before reminder: GIVEN `revisieDatum` in range [today+29, today+31], send notification to `verantwoordelijke` with deep link to edit dialog
- [ ] 4.3 Overdue escalation: GIVEN `revisieDatum < today()`, update `status` to `"revisie-vereist"`, send escalation notification to CISO
- [ ] 4.4 Implement revisie completion: PATCH `/api/toepassingen/{id}/revisie` with `confirmed: true` → set `vastgesteldOp: now()`, `revisieDatum: today() + 12 months`, reset status to original
- [ ] 4.5 On revisie confirmation, send notification: "Revisie bevestigd — volgende revisie op [date]"

## 5. Backend — Rapportage Generation

- [ ] 5.1 Implement rapportage generation: POST `/api/rapportages/genereer` with `{jaar: 2026}`
- [ ] 5.2 Aggregation logic: filter all Toepassingen where `organisatie == currentOrganization` (via RBAC)
- [ ] 5.3 Count per status: loop through Toepassingen, count toegepast, gedeeltelijk, niet, nvt
- [ ] 5.4 Group by domein: for each domein, compute percentage-applied
- [ ] 5.5 Create Rapportage object with aggregated metrics in JSON format
- [ ] 5.6 Implement rapportage vaststelling: PATCH `/api/rapportages/{id}/vaststellen` with `{vastgesteldDoor: "naam"}`
- [ ] 5.7 On vaststelling, lock the Rapportage record (set immutable flag), prevent future edits
- [ ] 5.8 Integrate with docudesk: request PDF generation with organization huisstijl + signature
- [ ] 5.9 Store signed PDF path in `pdfBestand` field
- [ ] 5.10 Implement JSON export endpoint: `GET /api/rapportages/{id}/json` returns Forum-format JSON (jaar, organisatie, domainBreakdown, standaardenStatus array)

## 6. Backend — Public API & Auditor Authorization

- [ ] 6.1 Create public endpoint: `GET /api/public/transparantie/toepassingen` (no auth required)
- [ ] 6.2 Public endpoint logic: return anonymized organization name, list of Applicaties (names only), compliance summary per app (% applied)
- [ ] 6.3 Exclude Applicaties with `public_api_hidden: true`
- [ ] 6.4 Include metadata: `applicationsHidden: count` (number of hidden apps)
- [ ] 6.5 Create auditor-authorized endpoint: `GET /api/public/transparantie/toepassingen?role=auditor` with OAuth2 validation
- [ ] 6.6 Auditor endpoint: verify JWT token issuer (e.g., Logius), role claim == "auditor"
- [ ] 6.7 Auditor endpoint: return full dataset (organization name, all apps, all Toepassingen with afwijking details, evidence metadata)
- [ ] 6.8 Auditor endpoint: log access to AuditTrail
- [ ] 6.9 Create file-access endpoint: `GET /api/public/transparantie/evidence/{evidenceId}/file?token={fileToken}` — validate token, return file from FileService
- [ ] 6.10 File-token generation: issue per-file token for auditors (24-hour TTL or audit-engagement duration)

## 7. Backend — Settings & Configuration

- [ ] 7.1 Create app settings page/form for:
  - `forum_sync_enabled: boolean` (default: true)
  - `forum_sync_interval_days: int` (default: 7)
  - `evidence_required_by_domein: object` — map of domein → boolean (e.g., {"beveiliging": true, "documenten": false})
  - `public_api_enabled: boolean` (default: true)
  - `benchmarking_opt_in: boolean` (default: false)
- [ ] 7.2 Validate and persist settings via ConfigurationService

## 8. Frontend — Stores & Composables

- [ ] 8.1 Create `src/stores/forum.js` (Pinia store) with actions: fetchStandaarden, createToepassing, updateToepassing, deleteToepassing, fetchToepassingen, fetchRapportages, generateRapportage, vaststelRapportage
- [ ] 8.2 Create `src/composables/useForumSync.js` — logic for triggering initial import and weekly sync (called from app bootstrap or admin interface)
- [ ] 8.3 Create `src/composables/useRapportageGeneration.js` — aggregation logic, metrics calculation, PDF generation workflow
- [ ] 8.4 Create `src/composables/useRevisionReminder.js` — local cron hook for revision-date checks (calls backend)
- [ ] 8.5 Create `src/composables/usePublicApi.js` — client-side helper for calling public transparantie API

## 9. Frontend — Views & Components

- [ ] 9.1 Create `src/views/StandardsListView.vue` — searchable/filterable list of all 115 Standaarden with: naam, domein, eigenaar, status badge, datumOpname, actions (view detail, edit, delete)
- [ ] 9.2 Create `src/views/StandardDetailView.vue` — standard detail page showing: full metadata, related standards, list of linked Toepassingen with status, "Suggesteer voor applicatie" button
- [ ] 9.3 Create `src/views/ApplicationStandardsTabView.vue` — tab view within Applicatie detail showing all standards with status badges, toelichting, evidence, actions (edit, delete, view evidence)
- [ ] 9.4 Create `src/views/ComponentStandardsTabView.vue` — same for Component detail
- [ ] 9.5 Create `src/views/ToepassingFormDialog.vue` — form for creating/editing Toepassing with fields: standard selector, status dropdown, toelichting, afwijking-reason/explanation (conditional), evidence upload, verantwoordelijke, revisieDatum, buttons: save/cancel
- [ ] 9.6 Create `src/views/EvidenceViewDialog.vue` — modal showing list of Evidence for a Toepassing, with upload button, download links, expiry warnings, actions (delete, validate)
- [ ] 9.7 Create `src/views/RapportageGeneratorView.vue` — page for generating/managing rapportages with: year selector, "Genereer rapport" button, list of existing rapportages, preview/download/vaststelling workflow
- [ ] 9.8 Create `src/views/ComplianceKpiDashboard.vue` — dashboard page with KPI cards: % applied per domein (bar chart), overdue revisions count, evidence expiry timeline, trend over time
- [ ] 9.9 Create `src/components/StandardComplianceIndicator.vue` — reusable badge showing "X/Y standards" with color-coding (green ≥90%, orange 70-90%, red <70%), tooltip on hover
- [ ] 9.10 Create `src/components/RevisionOverdueAlert.vue` — inline alert component for overdue revisions with escalation level (30-day, overdue, escalated)

## 10. Frontend — Integration with Existing Views

- [ ] 10.1 Modify `src/views/ApplicationListView.vue` — add StandardComplianceIndicator column to application rows
- [ ] 10.2 Modify `src/views/ComponentListView.vue` — add StandardComplianceIndicator column to component rows
- [ ] 10.3 Modify `src/views/ApplicationDetailView.vue` — add "Standaarden" tab navigation item in detail header
- [ ] 10.4 Modify `src/views/ComponentDetailView.vue` — add "Standaarden" tab navigation item
- [ ] 10.5 Modify `src/composables/useListView.js` — add optional facet filter for compliance-status range (0-30%, 30-70%, 70-90%, 90-100%)

## 11. Frontend — Settings UI

- [ ] 11.1 Create settings form in admin interface: Forum Standaardisatie section
- [ ] 11.2 Add toggle: "Forum sync ingeschakeld" (enables weekly auto-sync)
- [ ] 11.3 Add toggle: "Evidence vereist voor beveiligingsstandaarden" (and per-domein checkboxes)
- [ ] 11.4 Add toggle: "Publieke API ingeschakeld" (shows anonymized status to public)
- [ ] 11.5 Add toggle: "Deelnemen aan benchmarking" (opt-in cross-org comparison)
- [ ] 11.6 Add button: "Handmatig Forum-sync uitvoeren nu" (force initial import or sync)

## 12. API Contracts & Integrations

- [ ] 12.1 Design API contract for Forum Standaardisatie API wrapper: GET `/api/external/forum-standaardisatie/standaarden` returns paginated list
- [ ] 12.2 Design contract for docudesk PDF generation: request structure for generating rapportage PDF with huisstijl
- [ ] 12.3 Design contract for docudesk cryptographic signature: request structure for signing PDF with organization certificate
- [ ] 12.4 Design contract for public transparantie API: response schema for anonymized and auditor-full endpoints
- [ ] 12.5 Coordinate with gemma-gegevenscatalogus team on NEN 2660 mapping validation (future integration point)
- [ ] 12.6 Coordinate with openklant team on leverancier-contact management for evidence-uitvraag (future integration)

## 13. Testing — Unit Tests

- [ ] 13.1 Test `src/stores/forum.js`: fetchStandaarden action successfully loads and filters standards
- [ ] 13.2 Test Toepassing creation: validates required fields, sets default values (vastgesteldOp, verantwoordelijke)
- [ ] 13.3 Test Toepassing status validation: "niet-toegepast" requires afwijkingReden + explanation
- [ ] 13.4 Test evidence requirement: if domein requires evidence and none provided, save fails
- [ ] 13.5 Test revision reminder logic: given revisieDatum in future/past, correct notification is triggered
- [ ] 13.6 Test rapportage aggregation: counts Toepassingen per status, groups by domein, computes percentages correctly
- [ ] 13.7 Test public API anonymization: returns no organization name or owner details (unless auditor)
- [ ] 13.8 Test auditor OAuth2 validation: rejects invalid tokens, accepts valid tokens with auditor role

## 14. Testing — Integration Tests

- [ ] 14.1 Forum API mock test: simulate initial import with 115 standards, verify ObjectService creates records
- [ ] 14.2 Forum API mock test: simulate weekly sync with new standard + status change, verify notifications sent
- [ ] 14.3 Forum API mock test: simulate API failure, verify retry logic and CISO alert
- [ ] 14.4 Toepassing creation: create full Toepassing with evidence, verify all fields persisted in OpenRegister
- [ ] 14.5 Toepassing update: modify status, verify vastgesteldOp and verantwoordelijke updated correctly
- [ ] 14.6 Rapportage generation: create Rapportage for test organization with 10 Toepassingen, verify metrics calculated correctly
- [ ] 14.7 Revision reminder: create Toepassing with revisieDatum = today+30, trigger cron, verify notification sent
- [ ] 14.8 Evidence expiry: create Evidence with geldigTot = today-1, trigger cron, verify Toepassing flagged as warning
- [ ] 14.9 Public API anonymized: call without auth, verify organization names hidden, compliance summary shown
- [ ] 14.10 Public API auditor: call with auditor OAuth2 token, verify full data returned

## 15. Testing — E2E / Manual Testing

- [ ] 15.1 Browser test: admin opens ApplicationDetailView, clicks "Standaarden" tab, searches for "TLS", clicks "Toepassen", fills form, saves — verify Toepassing appears in list
- [ ] 15.2 Browser test: admin opens RapportageGeneratorView, selects year 2026, clicks "Genereer rapport", sees preview, clicks "Vaststellen", sees signed PDF — verify Rapportage status is "vastgesteld"
- [ ] 15.3 Browser test: public user calls public transparantie API (curl/Postman), verifies anonymized data returned
- [ ] 15.4 Browser test: auditor calls auditor-authorized API with OAuth2 token, verifies full data + afwijkingsredenen visible
- [ ] 15.5 Manual test: Forum API is temporarily down, weekly sync runs, verifies retry after 5 min, then 1 hour, then alert
- [ ] 15.6 Manual test: new standard added to Forum list, weekly sync detects it, CISO receives notification, CISO reviews suggested applications

## 16. Documentation

- [ ] 16.1 Create `docs/features/forum-standaardisatie-compliance.md` — end-user guide: how to register standards, upload evidence, perform revisions, view rapportages
- [ ] 16.2 Create `docs/admin/forum-standaardisatie-settings.md` — admin guide: how to configure Forum sync, evidence requirements, public API, benchmarking
- [ ] 16.3 Create `docs/api/forum-standaardisatie-api.md` — API reference: all endpoints (internal + public), request/response schemas, error codes
- [ ] 16.4 Create `docs/architecture/forum-standaardisatie-design.md` — technical design: schema definitions, polymorphic references, seed data, integration points
- [ ] 16.5 Add inline code comments for complex business logic (Forum API retry loop, rapportage aggregation, revision-reminder cron)

## 17. Internationalization (ADR-007)

- [ ] 17.1 Wrap all user-visible strings in `t('opencatalogi', '...')` calls
- [ ] 17.2 Add keys to `l10n/en.js` for all UI text: button labels, field labels, validation messages, notification subjects
- [ ] 17.3 Add Dutch translations to `l10n/nl.js` for all keys
- [ ] 17.4 Frontend string examples:
  - "Standaarden" (tab title)
  - "Standaard toepassen" (button)
  - "Status is verplicht" (validation message)
  - "Afwijkingredenen zijn vereist voor niet-toegepaste standaarden" (error)
  - "Revisie bevestigd voor {{standardName}} op {{appName}}" (notification)

## 18. Quality Gate Compliance (hydra-gates)

- [ ] 18.1 Run `npm run check:l10n` — verify all UI strings are in `en.js` and `nl.js` with matching keys
- [ ] 18.2 Run `npm run find:unwrapped` — find any prose-shaped literals in Vue templates not wrapped in `t()`
- [ ] 18.3 Run `npm run clean:l10n` — remove unused translation keys
- [ ] 18.4 Run hydra-gates (once available): check for dead auth code, unsafe auth resolvers, inline modals, modal isolation, forbidden patterns, SPDX headers
- [ ] 18.5 Fix any identified issues before merge

## 19. Deployment & Post-Deployment

- [ ] 19.1 Create migration for seed Standaard records (if using database migrations)
- [ ] 19.2 Create repair-step task to run initial Forum API import on first install
- [ ] 19.3 Add cron job configuration for weekly sync + daily revision-reminder + daily evidence-expiry checks
- [ ] 19.4 Test: fresh install → Forum API syncs → 115 standards visible in UI ✓
- [ ] 19.5 Test: upgrade existing instance → seed data idempotency check ensures no duplicates ✓

---

## Deduplication Check

**Existing OpenRegister patterns leveraged:**
- ObjectService for full CRUD on Standaard/Toepassing/Evidence/Rapportage — no custom persistence layer needed
- FileService for Evidence file storage + retention + SHA256 hashing — no custom file storage needed
- SearchTrailService for tracking which standards/apps are most reviewed
- AuditTrailService for before/after snapshots of Toepassing changes
- NotificationService for CISO/verantwoordelijke alerts
- AuthorizationService for RBAC on Toepassingen per user role
- CnDetailPage, CnFormDialog, CnDataTable for UI components — no custom forms/tables needed
- ExportService for CSV/JSON export; docudesk integration for PDF + signature
- ImportService for Forum API response parsing

**No duplication detected.** All proposed capability either leverages existing OpenRegister/nextcloud-vue infrastructure or is new domain-specific business logic (Forum API sync, evidence validation rules, rapportage aggregation, revision-cycle automation).
