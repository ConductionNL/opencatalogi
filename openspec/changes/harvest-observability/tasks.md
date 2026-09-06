# Tasks: harvest-observability

- [ ] 0.1 Author the delta spec: validation gate semantics, dashboard
      contents, log shape and retention behaviour
- [ ] 1.1 SHACL validation step in the harvest handler (bundled DCAT-AP-NL
      shape + per-feed shape URL); violations → `rejected` with reason
- [ ] 2.1 Per-feed dashboard page: run status, buckets, error trend,
      "Harvest Now" triggering the feed's OR flow
- [ ] 3.1 Structured per-run logs + paginated viewer + 30-day retention pass
- [ ] 4.1 Unit tests: validation pass/fail, retention idempotency, dashboard
      aggregation
- [ ] 4.2 e2e: a failing-shape fixture shows as rejected with its reason on
      the dashboard
- [ ] 5.1 i18n (nl/en); quality gates as usual
