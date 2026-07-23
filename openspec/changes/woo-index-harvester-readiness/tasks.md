# Tasks: woo-index-harvester-readiness

- [ ] Freeze delta spec `specs/woo-compliance/spec.md` (ADDED WOO-HR-001..004); `openspec validate woo-index-harvester-readiness` green
  - Acceptance: validator reports valid; existing WOO-001..010 untouched
- [ ] `lib/Service/WooReadinessService.php`: outside-in checks (robots.txt, sitemapindex per WOO catalog, category sitemaps, DIWOO XSD validation on fetched bytes, sampled publication URL), SSRF guard on every fetch, per-check pass/fail/skipped with machine-readable reasons, bounded to ≤25 requests / 10s timeout each
  - Spec ref: WOO-HR-001
  - Acceptance: PHPUnit with mocked HTTP client covers pass, 404→fail+skipped-dependents, invalid-XML fail, XSD fail, SSRF-guard rejection
- [ ] Persist report atomically in appconfig `woo_readiness_report`; expose `GET /api/woo/readiness` (last report, no side effects) and `POST /api/woo/readiness/run`
  - Spec ref: WOO-HR-002
  - Acceptance: PHPUnit proves GET performs zero outbound requests and returns the persisted report
- [ ] `woo_index_registration` config object (status/registeredUrl/registeredAt) editable via settings; include in report; `url-mismatch` check when status=registered
  - Spec ref: WOO-HR-003
  - Acceptance: PHPUnit mismatch scenario green
- [ ] Gate both endpoints with `#[AuthorizedAdminSetting]`; fail closed HTTP 409 `not-configured` when no WOO-enabled catalog; register routes in `appinfo/routes.php`
  - Spec ref: WOO-HR-004
  - Acceptance: PHPUnit: unconfigured→409+zero outbound; route-auth + semantic-auth gates green
- [ ] Settings UI: Woo panel section rendering per-check status with remediation hints + registration status editor (NcSelect with inputLabel; modal-free inline panel)
  - Spec ref: WOO-HR-002/003
  - Acceptance: vitest component test; nc-input-labels gate green
- [ ] `@spec` tags on all new/changed methods; hydra gates (spdx, route-auth, spec-coverage, security-change-has-tests — new outbound-fetch code touches tests/) green locally
