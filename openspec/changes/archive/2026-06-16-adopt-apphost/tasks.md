# Tasks — Adopt AppHost observability

## 1. Manifest observability block
- [x] Add `observability.health` (database critical, filesystem degraded) to `src/manifest.json`
- [x] Add `observability.metrics` — declarative `search_requests_total` (tableCount) + `{kind:provider}` domain descriptor

## 2. Route re-pointing + DI
- [x] Re-point `/api/metrics` → `AppHost\Controller\GenericMetrics#index`
- [x] Re-point `/api/health` → `AppHost\Controller\GenericHealth#index`
- [x] Register service aliases for the leaf-namespaced Generic{Health,Metrics}Controller → OR generics
- [x] Register `IMetricsProvider::opencatalogi` alias for the domain provider

## 3. Domain metrics provider
- [x] Add `lib/Observability/OpenCatalogiMetricsProvider.php` reproducing the bespoke domain families (same OR-backed queries, same zero-fallbacks)
- [x] Add unit test asserting family names/types + zero-fallback samples

## 4. Delete bespoke + parity
- [x] Delete `lib/Controller/HealthController.php` + `MetricsController.php`
- [x] Delete their unit tests
- [x] Verify health JSON + metrics Prometheus parity vs the deleted controllers (contract diff in design.md / spec)

## 5. Verify
- [x] PHPUnit (provider test green; suite errors are pre-existing OR-classpath gaps, improved on baseline)
- [x] `npm ci` + `npm run build` (manifest changed)
- [x] Hydra gates diff-clean (24/24)
