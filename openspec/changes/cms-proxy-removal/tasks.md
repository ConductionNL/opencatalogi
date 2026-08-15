# Tasks: cms-proxy-removal

> Removes the deprecated CMS proxies (ADR-032 `kind: code`). BLOCKED until the
> preconditions in the proposal hold. Checkbox budget: 2 tasks × 2 = 4
> unindented `- [ ]` lines (cap 20).

## Implementation Tasks

### Task 1: Evidence that nothing still calls the endpoints
- **spec_ref**: `openspec/changes/cms-proxy-removal/specs/cms-handover/spec.md#requirement-removal-must-be-evidenced-not-assumed`
- **files**: `docs/cms-proxy-retirement.md`
- **acceptance_criteria**:
  - The proxy endpoints are instrumented BEFORE the observation window opens; an unlogged endpoint is unknown, not unused, and the two look identical
  - Access logs over a full release cycle show no callers, recorded per deployment rather than generalised from one
  - `tilburg-woo-ui` is confirmed to have stopped calling them
  - The record states what was checked and over what period, so a later reader can judge the evidence rather than trust the conclusion
- [ ] Implement
- [ ] Test

### Task 2: Remove the controllers, routes, tests and specs together
- **spec_ref**: `openspec/changes/cms-proxy-removal/specs/cms-handover/spec.md#requirement-the-proxied-endpoints-must-be-gone-not-silently-broken`
- **files**: `lib/Controller/MenusController.php`, `lib/Controller/PagesController.php`, `lib/Controller/GlossaryController.php`, `appinfo/routes.php`, `tests/Unit/Controller/`, `openspec/specs/content-management/spec.md`
- **acceptance_criteria**:
  - Controllers, route entries, tests and spec coverage are removed in one change; a route surviving its controller returns 500, which is worse than the 404 this is meant to produce
  - A request to any former endpoint returns 404
  - A repo search for the controller names finds no route, test, spec or manifest reference
  - Catalogues, publications, themes and directory federation are exercised and unchanged
- [ ] Implement
- [ ] Test
