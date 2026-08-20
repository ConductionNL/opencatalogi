# Forum Standaardisatie "Pas toe of leg uit"-Registratie

## Problem

The Forum Standaardisatie maintains a list of 115 Dutch open standards that fall under the "pas toe of leg uit" (apply-or-explain) regime, enforced by the Instruction for Government Services. Currently, compliance monitoring is performed manually through annual survey reports, resulting in:

- **Unquantifiable compliance** — organizations cannot demonstrate adherence to the regime
- **Monitoring gap** — no real-time insight into which standards are applied to which applications/components
- **Blind procurement** — when purchasing software, procurement teams do not have automated recommendations for required standards
- **Transparency deficit** — citizens and oversight bodies lack visibility into standard adoption in public IT systems

## Proposed Solution

Add a comprehensive standards registration and compliance system to opencatalogi that:

1. **Imports and syncs the Forum list** — automatically retrieves and maintains the 115-standard catalog from Forum Standaardisatie API
2. **Maps standards to IT assets** — allows organizations to register which standards apply to each application, component, register, and external integration, with documented compliance status and evidence
3. **Automates annual reporting** — generates jaarlijkse rapportages in the format expected by Forum Standaardisatie, including PDF (with cryptographic signature) and structured JSON
4. **Supports procurement** — recommends applicable standards for new IT projects based on type/domain matching
5. **Enables transparency** — exposes standards status via public API for burgers, journalists, and auditors; opt-out per application
6. **Drives compliance** — revision cycles with automated reminders keep evidence current; evidence validation enforces audit requirements

## Scope

**Introducing 4 new schemas:**
- `Standaard` — Forum Standaardisatie standards catalog entry
- `Toepassing` — links a standard to an application/component/register/source, with compliance status and reasoning
- `Evidence` — audit artifacts (test reports, certificates, configuration exports, policy documents) backing a Toepassing
- `Rapportage` — annual compliance report instance, aggregating all Toepassingen

**Extending 2 existing schemas:**
- `Applicatie` (softwarecatalog) — adds `toepassingen` field linking to Toepassing records
- `Component` (softwarecatalog) — adds `toepassingen` field linking to Toepassing records

**Integrations:**
- Forum Standaardisatie API for automatic standard list sync
- OpenRegister for object storage and RBAC
- @conduction/nextcloud-vue for UI components
- softwarecatalog, openregister, openconnector for cross-app linking
- gemma-gegevenscatalogus for GEMMA mapping validation
- docudesk for PDF generation and cryptographic signature
- mydash for KPI dashboards
- decidesk for evidence linking to formal decisions

## Success Criteria

- All 115 Forum standards successfully imported on first install
- Weekly sync with Forum API detects new standards and status changes within 24 hours
- Organization can register Toepassingen for all applicatie/component/register types with mandatory evidence for security standards
- Jaarlijkse rapportage generated in Forum-expected format (PDF + JSON) within 1 minute
- Revision cycle drives re-verification: 30-day reminder before date, escalation to CISO if overdue
- Public API exposes anonymized standards status; authorized auditors see full data including evidence metadata
- Procurement workflow suggests standards by type and shows impact analysis
- No manual SQL edits or file modifications needed — all CRUD via UI or API

## Standards & Sources

- **Forum Standaardisatie** — forumstandaardisatie.nl/open-standaarden; API at https://www.forumstandaardisatie.nl/api/standaarden
- **Pas-toe-of-leg-uit regime** — Instructie Rijksdienst (2008, revised 2024); Wet digitale overheid (articles 3–5)
- **Monitor pas-toe-of-leg-uit** — annual report template from Forum Standaardisatie (2025 edition)
- **NEN 2660-1:2022** — information modeling standard (basis for Standaard/Toepassing schema design)
- **Archiefwet 1995** — mandates 7-year retention of evidence records
- **SAML 2.0 / OpenID Connect** — for auditor-role authorization in public API

## Out-of-Scope (v1)

- AI-powered suggestions for deviation explanations (data privacy unclear)
- Direct integration with automated scanners (SSL Labs, Mozilla Observatory) — design only, implementation future
- Workflow engine for administrative approval of deviations (too heavy for v1; direct-approve sufficient)
- Advanced cross-organization analytics (requires sufficient benchmark participation)
- Multilingual standard names (relevant for G4 municipalities with international audit, not urgent)
