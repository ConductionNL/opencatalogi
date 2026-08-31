# Design — Adopt AppHost observability

## Adopted vs kept-bespoke

| Concern | Decision | Rationale |
|---|---|---|
| Health endpoint | **Adopted** — aliased to `GenericHealthController` | Two checks map 1:1 to the `database`/`filesystem` declarative check types. |
| Metrics endpoint | **Adopted** — aliased to `GenericMetricsController` | Engine owns the Prometheus format + admin-only posture (ADR-006). |
| `search_requests_total` | **Declarative** `tableCount` | Structurally identical to OpenRegister's own descriptor (own-table COUNT with a `like` filter). |
| Domain metric families | **Provider escape hatch** (`{kind:provider}`) | They group by JSON object fields, the usageCounter SUM splits one schema into two named families by `kind`, and the contract emits explicit zero-fallback lines — none of which the closed declarative source-kind set can express. The provider runs the SAME OR-backed queries, guaranteeing byte parity. |
| Implicit `info` / `up` | **Engine-owned** | `info` carries `version`/`php_version`/`nextcloud_version` (matches bespoke). `up` is engine-owned (always 1 once served) — the same intentional improvement OpenRegister made. |
| Boilerplate harness (Bootstrap/Routes, Settings/Preferences/Dashboard/AdminSettings/DeepLink) | **Kept bespoke** | Domain-entangled: `Application.php` wires 6 OR event listeners + MCP + 5 dashboard widgets; `routes.php` has ~80 ordered domain routes + a wildcard catch-all that must stay last; `SettingsController` is 243 LOC of domain logic. Mechanical win is small, regression risk is high. Left for a future, separate change. |

## Why the provider, not pure declarative

The bespoke metrics matched OR schemas by **title LIKE pattern** (`%ublicati%`,
`%atalog%`, `%isting%`, `%irectory%`, `%sageCounter%`) and grouped on JSON
`object` fields (`status`, `catalog`, `kind`). The engine's `objectCount`/`objectSum`
kinds take explicit schema **slugs** and drop zero-valued grouped samples, and a
single descriptor cannot emit two metric families (`publication_views_total` +
`file_downloads_total`) from one `usageCounter` SUM split by `kind`, nor reproduce
the historical `{catalog=""} 0` / `listings_total 0` fallback lines. Reproducing
those families through the provider — with the same `IDBConnection` queries — is the
only way to keep the `/api/metrics` body byte-identical. `provider` is one of the
six engine source kinds and the documented home for imperative metrics.

## Resolution wiring

NC resolves the route name `AppHost\Controller\GenericMetrics#index` to the class
`OCA\OpenCatalogi\AppHost\Controller\GenericMetricsController`, which does not exist
in this app. `Application.php` registers container service aliases mapping those
leaf-namespaced class names to OpenRegister's real `OCA\OpenRegister\AppHost\Controller\*`
generics, and registers the provider under
`OCA\OpenRegister\AppHost\IMetricsProvider::opencatalogi` (the ADR-035 alias the
engine's `ProviderMetricSource` enumerates). All references sit inside the
`registerServiceAlias` closures, so a disabled/absent OpenRegister never fatals NC
at boot — the endpoints degrade to a 5xx only when actually dispatched (per the
AppHost doc).
