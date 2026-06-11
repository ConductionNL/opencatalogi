# Proposal: publication-usage-analytics

## Summary
Give publication officers **privacy-safe, per-publication reach numbers**:
how often each publication was viewed and each attachment downloaded, per
day, aggregated — no IP addresses, no cookies, no user agents, no personal
data. Today the only usage signal is `prometheus-metrics` (operational totals
for ops dashboards: publication counts, search counts, federation health);
nothing tells a publisher *which* publications are actually reached, which is
exactly the number every WOO annual report, open-data evaluation, and content
strategy discussion asks for. CKAN and data.overheid.nl ship per-dataset
view/download statistics as a baseline feature; a best-in-class publication
catalog cannot answer "did anyone read our besluiten?" with a shrug.

Per **hydra ADR-022**, this change builds NO analytics platform:

- **Counting** is a tiny increment hook on the existing public read paths
  (publication detail PUB-002, file download PUB-007/download-service) —
  the only genuinely in-app part, because only OpenCatalogi knows what a
  "public publication view" is.
- **Storage** is OR objects: one daily-counter object per
  (publication, day, kind) in a `usageCounter` schema on the OpenCatalogi
  register — no bespoke tables, RBAC and querying come from OR.
- **Aggregation/queries** (totals, ranges, top-N) delegate to OR object
  search/facets over those counter objects.
- **Dashboards** reuse the existing dashboard-widget surface; exports reuse
  the established CSV pattern; ops totals are additionally exposed through
  the *existing* `/api/metrics` endpoint (extending prometheus-metrics, not
  duplicating it).

Privacy is a design constraint, not a feature: counters only ever hold
`(publication, date, kind, count)`. There is nothing to anonymize because
nothing personal is ever written, which keeps the surface GDPR/AVG-trivial
and acceptable for government deployment without a cookie banner or DPIA
escalation.

## Motivation
WOO Article 6.2 pushes bestuursorganen to evaluate and improve their active
disclosure; annual WOO reports and open-data programs need reach figures per
publication and per category. Publishers currently have zero feedback: they
cannot see whether a 200-document reading room was ever opened, which
categories citizens actually use, or whether a portal redesign changed
anything. External web-analytics (Matomo etc.) sees the *frontend*, not the
API-served documents, double-misses federated/api consumers, and is not
per-publication out of the box. Counting at the API — where every frontend,
federated peer, and direct download converges — is the one honest place.

## Scope
- View/download counting on public read endpoints (publication detail,
  attachment download), fire-and-forget (never slows or breaks the read).
- Daily aggregate counter objects in OR (`usageCounter` schema); no raw
  events, no personal data, ever.
- Best-effort bot filtering (known-crawler user-agent check at count time —
  evaluated, then discarded, never stored).
- Authenticated stats API: per-publication timeseries + totals, catalog
  roll-ups, top-N publications per period.
- Publisher surfaces: stats panel on the publication detail page, dashboard
  "most viewed" widget, CSV export per catalog/period for WOO reporting.
- Ops bridge: catalog-level view/download totals added to the existing
  Prometheus `/api/metrics` output.

## Out of scope (consumed, not built)
- Storage, RBAC, querying, facets — OpenRegister.
- Operational metrics endpoint — exists (`prometheus-metrics`); only extended
  with two metric families.
- Session analytics, visitor uniqueness, referrers, funnels, A/B — explicitly
  rejected (would require personal data; use a frontend analytics tool if a
  municipality wants that, outside this app).
- Federated usage aggregation (asking peers for their counters) — future;
  this change counts what *this* instance serves, including requests that
  arrive via federation.
- Search-query analytics — separate concern, prometheus-metrics already
  counts search volume.

## References
- hydra ADR-022 — apps consume OpenRegister abstractions.
- WOO Art. 6.2 (evaluation/improvement of active disclosure); GDPR/AVG
  (data-minimization by construction).
- CKAN `ckanext-stats` / data.overheid.nl per-dataset statistics (category
  baseline).
- Existing specs: `publications` (PUB-002/007 — the counted paths),
  `download-service`, `prometheus-metrics` (extended), `dashboard`,
  `cross-origin-api-access`.
