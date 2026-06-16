# Adopt the OpenRegister AppHost observability engine

## Why

OpenCatalogi shipped a bespoke `HealthController` (172 LOC) and `MetricsController`
(447 LOC) that hand-rolled the ADR-006 health + Prometheus metrics contract. The
same contract is now owned centrally by OpenRegister's **AppHost** observability
engine (ADR-040): a declarative `observability` block in `src/manifest.json` is
executed by generic, engine-owned controllers (`GenericHealthController` `#[PublicPage]`,
`GenericMetricsController` admin-only). OpenRegister itself adopted the engine and
is the canonical reference.

Adopting the engine deletes ~620 lines of duplicated observability PHP, fixes the
ADR-006 contract centrally (auth posture and exposition format can no longer drift
per-app), and keeps OpenCatalogi's `/api/health` + `/api/metrics` URLs and output
unchanged.

## What changes

- Add an `observability` block to `src/manifest.json` reproducing the bespoke
  output: two health checks (`database` critical, `filesystem` degraded) and the
  metric families (search requests + the OpenCatalogi domain families).
- Re-point the `/api/health` and `/api/metrics` routes at
  `AppHost\Controller\GenericHealth#index` / `GenericMetrics#index`; register
  container service aliases in `Application.php` so the leaf-namespaced route
  targets resolve to OpenRegister's shared generics.
- Reproduce the JSON-field-grouped domain metrics (publications/catalogs/listings
  by status+catalog, directory entries, usageCounter view/download sums by catalog)
  byte-for-byte via `OpenCatalogiMetricsProvider implements IMetricsProvider`, wired
  through the `{kind:provider}` escape hatch. The provider runs the SAME
  OpenRegister-backed queries the deleted controller ran.
- Delete `HealthController`, `MetricsController` and their unit tests; add a unit
  test for the provider.

Out of scope: the boilerplate harness (`Bootstrap::register` / `Routes::standard`,
generic Settings/Preferences/Dashboard/AdminSettings/DeepLink plumbing). OpenCatalogi
is a large domain app — its `Application.php` wires six OpenRegister event listeners,
an MCP provider and five dashboard widgets; its `routes.php` carries ~80 strictly
ordered domain routes with a wildcard catch-all that MUST stay last; its
`SettingsController` is 243 lines of domain logic (publishing options, version info,
manual import). Offloading that plumbing is domain-entangled and risky, with little
mechanical win, so it is deliberately left bespoke (documented in design.md).

## Impact

- Affected specs: `adopt-apphost` (new).
- Affected code: `src/manifest.json`, `appinfo/routes.php`, `lib/AppInfo/Application.php`,
  `lib/Observability/OpenCatalogiMetricsProvider.php` (new); deletes
  `lib/Controller/{Health,Metrics}Controller.php`.
- Contract: `/api/health` + `/api/metrics` URLs and output unchanged (parity verified),
  with the same documented intentional improvements OpenRegister's own adoption made
  (health JSON gains an `app` field; `opencatalogi_up` is engine-owned).
