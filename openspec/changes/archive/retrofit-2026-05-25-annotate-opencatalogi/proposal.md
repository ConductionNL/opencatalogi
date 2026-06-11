# Retrofit — annotate opencatalogi against existing specs

Retroactive annotation of 183 methods across 37 files against 136 REQs in 12 capabilities. No code logic changes. No spec deltas (all REQs already exist in openspec/specs/).

Source: openspec/coverage-report.md generated 2026-05-24 (Bucket 1 only).

Post-merge drift handled (this run targets development after #665/#666/#667):
- Skipped `HealthController::checkSearchBackend` — removed by #665 (dead ElasticSearchService reference). The method no longer exists in the tree.
- org-archimate-export REQs are out of scope — that spec relocated to softwarecatalog via #666; no org-archimate methods appear in this report's Bucket 1.
- REQ headings were normalized to ADR-037 Form A by #667. `@spec` tags point at ghost-change tasks, not REQ headings, so annotation mechanics are unaffected.

See [retrofit playbook](../../../.github/docs/claude/retrofit.md).
