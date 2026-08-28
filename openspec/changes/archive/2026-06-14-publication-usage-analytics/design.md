# Design: publication-usage-analytics

## Architecture Overview

```
public read path (PUB-002 detail / PUB-007 + download-service file)
        │ fire-and-forget increment (never blocks/breaks the read)
        ▼
UsageCounterService.increment(publicationId, kind)
        │ upsert daily counter object
        ▼
OR: usageCounter schema  { publication, date, kind, count }
        │ OR object search / facets (sums, ranges, top-N)
        ▼
authenticated stats API ──► detail-page stats panel
                        ──► dashboard "most viewed" widget
                        ──► CSV export (WOO annual report)
                        ──► /api/metrics totals (prometheus-metrics, extended)
```

## Key decisions

### 1. Aggregate-only by construction (privacy)
The counter object is `(publication, date, kind, count)` — nothing else.
No IP, no user agent, no session id, no referrer is ever **stored**; the
known-crawler user-agent check happens in memory at count time and the value
is discarded. Consequences:
- GDPR/AVG: no personal data processing beyond the transient request data
  the web server already handles; no consent banner, no retention duty on
  the counters themselves.
- Honest limitation, stated in the spec: counts are *requests*, not unique
  visitors. 100 views may be 1 person. That is the same semantic CKAN and
  data.overheid.nl publish, and it is sufficient for reach reporting.

### 2. Counters are OR objects, not a bespoke table (ADR-022)
One object per (publication, day, kind) in a `usageCounter` schema on the
OpenCatalogi register:
- storage/RBAC/query/facets come from OR for free; stats queries are object
  searches with sums over `count` — no SQL in OpenCatalogi;
- the schema is plumbing, not publishable content: marked internal,
  excluded from catalogs/sitemaps/DCAT/federation, never publicly visible
  (`@self.published` never set), and RBAC-restricted to the app/officer
  roles;
- daily granularity bounds cardinality: a 10k-publication instance writes at
  most ~20k objects/day worst case, realistically far fewer (only touched
  publications get a row); a yearly horizon stays in ordinary OR territory.

Increment is read-modify-write on today's counter with a small lost-update
tolerance (two concurrent increments may drop one count). For reach
statistics this is acceptable by design; if OR grows an atomic-increment
primitive later, the service swaps to it without schema change.

### 3. Count in the controller seam, fire-and-forget
The increment is invoked from the public read paths *after* the response is
assembled (or via a deferred/`register_shutdown`-style hook), wrapped so any
counting failure is logged and swallowed — a broken counter must never break
or slow a citizen's download (mirrors APB-014's "never break the originating
operation" posture). HEAD requests, error responses (4xx/5xx), and
known-crawler user agents are not counted; conditional `304` responses are
not counted (the client already had it).

### 4. Reuse every surface that exists
- **Stats API**: authenticated (officer-facing, not public)
  `GET /api/publications/{id}/stats?from&to&granularity` +
  `GET /api/catalogs/{slug}/stats?period&top=N`. Public exposure is a
  deliberate non-goal for v1 (municipalities differ on whether reach
  numbers are themselves public; a later change can lift specific roll-ups
  into the public API once policy is decided).
- **UI**: stats panel on the publication detail page (existing detail/widget
  surface) + "most viewed publications" dashboard widget (existing
  dashboard-widget infrastructure).
- **Export**: CSV (UTF-8 BOM) per catalog + period — same export pattern as
  inventarislijst/retention report.
- **Ops**: two new metric families in the existing `/api/metrics`
  (`opencatalogi_publication_views_total`,
  `opencatalogi_file_downloads_total`, labelled by catalog) — extends
  prometheus-metrics' catalog metrics, keeps Grafana in one place, and
  deliberately does NOT carry per-publication labels (cardinality).

### 5. What we explicitly do not count
Search queries (prometheus-metrics counts volume already), list-endpoint
hits (PUB-001 — a listing render would inflate every publication on page 1),
authenticated back-office views (officers reading their own drafts are not
"reach"). Only the two unambiguous reach signals: public detail fetch and
public file download.

## What is explicitly NOT built (ADR-022)
- No bespoke storage/SQL — OR objects + object search.
- No analytics platform features (uniques, sessions, referrers, funnels) —
  rejected on privacy grounds, not deferred.
- No second metrics endpoint — prometheus-metrics is extended.
- No federated counter aggregation — future change; remote requests that hit
  this instance are simply counted here.

## Open questions
1. Counter write path: synchronous-deferred in-request vs. queued via a
   background flush (proposed: in-request deferred first; revisit if p95 on
   downloads shows pressure).
2. Backfill: none possible (no historical data) — start-of-counting date is
   shown in the UI so officers don't misread early small numbers.
3. Should the stats panel be visible to all authenticated users or only
   catalog editors (proposed: catalog editors + admins via OR RBAC on the
   counter schema).
