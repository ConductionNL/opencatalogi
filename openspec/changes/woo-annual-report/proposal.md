---
kind: code
depends_on: [openregister/rapportage-bi-export]
---

# Proposal: woo-annual-report

## Why

Organisations account for their Woo work in their annual report: how much they published, in which categories, how fast, compared with last year. Today an officer counts that by hand.

Row, from `opencatalogi/_round1/compare/M1-rows.md`, with our column from `baseline/openwoo.tsv`:

- **16.5** "A report an organisation can put in its annual report". Ours: partial, production. Evidence: "/api/catalogs/{slug}/stats and /stats/export exist but answer 401 anonymously, which is right; nothing assembles a report an organisation can paste into an annual report".

Read on development at 35999c296. `StatsController::export()` gives a per-catalogue usage CSV (views and downloads), not a Woo account. `WooCategoryRegistry::all()` lists the 18 members of the waardelijst (the 17 categories of Woo art. 3.3 and the art. 3.1 member) plus local categories, and `schemasFor(string $code)` the schemas that feed each. The OpenRegister change `rapportage-bi-export` (0 of 15 tasks, outside this plan) specifies report templates stored as objects of a `report-templates` schema and rendered by `POST /api/reports/generate`; its own "WOO annual report generation" scenario still says "11 WOO categories", which is stale: the Woo has 17 categories under art. 3.3.

## What changes

- OpenCatalogi ships one report template, `woo-jaarverslag`, as a seed object for OpenRegister's `report-templates` schema. It declares, per line of the report, which OpenCatalogi schema feeds it and how: publications per information category (every category `WooCategoryRegistry` returns, a category with none shown as 0), publications per month, publication timeliness (days from `creationDate` to `publicationDate`, median and share within 14 days), withdrawals with their count per reason, and the same figures for the previous year.
- The template is generated per calendar year and catalogue as PDF, ODS and CSV through OpenRegister's report generation.
- Woo requests are not counted here (decision D1: dossiq owns them). When dossiq is installed the template carries a line pointing at dossiq's Woo request report; when it is not, the report says that Woo requests are handled in dossiq, which is not installed.
- The publications report page links Generate annual report.

## Fail closed

- A category the registry knows but the data lacks is shown as 0, never left out, so the report cannot suggest a category was not applicable.
- A publication without `creationDate` is excluded from the timeliness figure and counted in a line "no creation date", never timed from `@self.created`.
- Without OpenRegister's report generation the action is absent and the page says the report needs a newer OpenRegister. No partial report is produced that could be pasted as complete.
- The template counts through the caller's rights, as OpenRegister's reporting enforces; an officer who cannot read a catalogue does not see it counted.

## Out of scope

- The report engine itself (`openregister/rapportage-bi-export`).
- Request and term figures (dossiq).

## Dependencies

- `openregister/rapportage-bi-export` (OpenRegister, open change outside this plan, 0 of 15 tasks). This change needs its `report-templates` schema, its aggregation API and `POST /api/reports/generate`, as specified there. Its scenario's "11 WOO categories" should follow the registry; that is for the OpenRegister lane to correct.
- `diwoo-metadata-on-the-publication` (wave 1): `creationDate` for the timeliness figure.
- dossiq (optional): its Woo request report, referenced by URL only.

## Wave

Wave 2, as the plan places it. It cannot render before the OpenRegister engine ships.

## Decisions

- D1: requests and terms are dossiq's; this report covers publications only and says so.

## Rows

| row | text | rating today | what makes it yes |
|---|---|---|---|
| 16.5 | A report an organisation can put in its annual report | partial | REQ-WAR-001 and REQ-WAR-002, scenario "An officer generates the 2026 Woo annual report" (yes once OpenRegister's report generation ships) |
