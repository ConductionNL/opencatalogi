# Tasks: OpenCatalogi Legacy Quality Cleanup

## Phase 1 — Inventory + planning

- [x] 1. Capture baselines for all three gates: run `composer phpcs`
      (start: 8 exclude-patterns in `phpcs.xml`), `composer phpmd`
      (first-time unified-gate run — capture violation count +
      categories), and `composer phpstan` (first-time unified-gate run —
      capture error count + categories). Per-gate decision rule:
      fix-outright if <50 violations, otherwise capture a fresh baseline.
- [x] 2. Confirm CI runs `composer check:strict` on every PR before any
      burn-down work begins.

## Phase 2 — PHPCS burn-down (per excluded file)

Recipe: fix sniffs, remove the `phpcs.xml` `<exclude-pattern>`, verify
gate stays green.

- [x] 3. Excluded files 1–4 — fix sniffs + drop excludes.
- [x] 4. Excluded files 5–8 — fix sniffs + drop excludes.
- [x] 5. Once all excludes are gone, drop the legacy-debt block from
      `phpcs.xml` entirely.

## Phase 3 — PHPMD burn-down

Contingent on Phase 1 output. If a baseline was captured, work categories
in roughly volume-descending order.

- [x] 6. Flatten branching: `ElseExpression` → early-return;
      `CyclomaticComplexity` + `NPathComplexity` → extract named
      helpers; `ExcessiveMethodLength` → extract helpers.
- [x] 7. Style + DI fixes: `MissingImport` → add `use` statements (drop
      inline FQCNs); `StaticAccess` → replace with DI services;
      `LongVariable` / `ShortVariable` → rename to 4-20 chars;
      `UndefinedVariable` / `UnusedFormalParameter` → fix or annotate
      with `@SuppressWarnings`.
- [x] 8. Once the baseline reaches 0 lines, delete `phpmd.baseline.xml`
      and drop `--baseline-file` from composer.json's phpmd script.

## Phase 4 — PHPStan burn-down

Contingent on Phase 1 output. If a baseline was captured, work per
cluster.

- [x] 9. Inventory `phpstan-baseline.neon` by error type + file; fix
      the common-pattern clusters: missing return-type / param-type
      declarations, mixed types (specify generic / union),
      possibly-null dereferences (add null guards).
- [ ] 10. Once the baseline reaches 0 lines, delete
      `phpstan-baseline.neon`.

## Phase 5 — CI integration

- [x] 11. Verify `composer check:strict` runs in CI on every PR; once
      all baselines are empty, delete `phpmd.baseline.xml` +
      `phpstan-baseline.neon` (if they were created) and drop the
      legacy-debt section from `phpcs.xml`.
- [ ] 12. Add a smoke-test cron that runs `composer check:strict`
      weekly on `development`.

## Phase 6 — Documentation

- [x] 13. Update the README quality-gates section and note in
      `app-config.json` that legacy quality cleanup is done.
- [ ] 14. Close the burn-down tracking issue once the last baseline
      line is removed.
