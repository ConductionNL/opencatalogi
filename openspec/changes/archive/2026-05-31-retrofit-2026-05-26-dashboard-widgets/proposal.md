# retrofit-2026-05-26-dashboard-widgets

## Why

OpenCatalogi's dashboard aggregates publication metrics (counts by state, by category, activity chart, KPIs) and offers quick actions, while several Nextcloud dashboard widgets surface catalogs and unpublished publications/attachments. The dashboard side bar fetches catalogs and publication types. These are real capabilities lacking specs; this change reverse-specs them.

## What Changes

- Document the dashboard view (load data, KPIs, publications-by-state/category, activity chart, quick create/open, layout change, widget definitions).
- Document the dashboard side bar (fetch catalogs/publication types, add publication/attachment, view publication).
- Document the dashboard widgets (fetch data, item mapping, on-show refresh).
- No code changes — annotation-only retrofit.

## Impact

- **Affected specs**: new capability `retrofit-2026-05-26-dashboard-widgets`
- **Affected code**: `src/views/dashboard/Dashboard.vue`, `src/sidebars/dashboard/DashboardSideBar.vue`, `src/views/widgets/*.vue` (docblock `@spec` annotations only)
- **Risk**: none — comment-only; production app, no admin merge.
