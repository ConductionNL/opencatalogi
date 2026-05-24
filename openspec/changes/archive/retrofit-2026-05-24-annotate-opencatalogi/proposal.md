# Retrofit — annotate opencatalogi against existing specs

Retroactive annotation of 184 methods across 37 files against 153 REQs in 12 capabilities. No code logic changes. No spec deltas (all REQs already exist in openspec/specs/).

Source: openspec/coverage-report.md generated 2026-05-24 (Bucket 1 only).

Capabilities covered:

- admin-settings (12 REQs)
- auto-publishing (15 REQs)
- catalogs (11 REQs)
- cms-tool (12 REQs)
- content-management (17 REQs)
- dashboard (24 REQs)
- download-service (7 REQs)
- federation (12 REQs)
- file-management (15 REQs)
- prometheus-metrics (2 endpoint groups)
- publications (15 REQs)
- woo-compliance (11 REQs)

Skipped:

- 11 Bucket 1 entries flagged `needs_review: true` (deferred to a follow-up pass)
- org-archimate-export entries (relocated to softwarecatalog in a separate PR — #666)

See [retrofit playbook](../../../.github/docs/claude/retrofit.md).
