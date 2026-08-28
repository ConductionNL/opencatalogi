# Design: woo-index-harvester-readiness

## Approach
Outside-in validation: the service fetches its *own* public URLs (base URL from
`overwrite.cli.url` / configured public base) through the existing
SSRF-hardened outbound guard (`DirectoryService::assertSafeOutboundUrl()`
extracted or reused), so the report reflects what the KOOP harvester would
actually see — not what the router can render internally.

## Decisions

### D1 — Reuse the existing DIWOO validation, don't duplicate it
`SitemapService` already ships admin DIWOO validation against the bundled XSD.
`WooReadinessService` calls that validation on the *fetched* sitemap bytes
(fetched over HTTP from the public URL), not on internally rendered output.
No second XSD path.

### D2 — Report persistence in appconfig JSON
One JSON blob under app config key `woo_readiness_report` (per-check results,
verdict, timestamp, baseUrl). Atomic replace on each run. No new register
schema: this is instance-operational state, not domain data (consistent with
how setup wizard state is stored, ADR-042).

### D3 — Registration status is config, not a register object
`woo_index_registration` config object (status/registeredUrl/registeredAt),
edited via the existing settings save endpoint. It describes *this instance's*
relationship to a national registry — operational config, not a publication.

### D4 — Fail-closed posture (gate: security-config-fail-mode)
Empty/missing WOO configuration → HTTP 409 `not-configured`, zero outbound
requests. A readiness endpoint that silently "passes" on an unconfigured
instance would be exactly the silent-defense-off pattern gate 51 exists for.

### D5 — Checks are sampled, bounded, and time-limited
Per catalog: 1 sitemapindex + up to 3 category sitemaps + 1 publication URL
sample; every request with a 10s timeout and a hard cap of 25 outbound
requests per run (bounded-work, ADR-058 spirit). The report notes sampling.

## Alternatives considered
- **Cron-driven continuous monitoring + notifications** — deferred; would ride
  the ADR-031 notification dialect and needs a decision on alert fatigue.
- **Automatic Woo-index registration** — no public API exists; out of scope.
