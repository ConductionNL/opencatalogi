## ADDED Requirements

### Requirement: Harvester-readiness self-check validates the deployed public WOO surface (WOO-HR-001)

The system MUST provide an admin-triggered harvester-readiness self-check that
validates, using outbound HTTP requests against the instance's own public base
URL, every precondition for KOOP Woo-harvester ingestion:

1. `robots.txt` is publicly reachable (HTTP 200) and references the DIWOO
   sitemapindex location(s) rendered by `RobotsController`;
2. every WOO-enabled catalog's sitemapindex is publicly reachable and is
   well-formed XML;
3. each per-informatiecategorie sitemap referenced by a sitemapindex is
   publicly reachable, well-formed, and carries the `diwoo:` metadata
   extension elements;
4. the DIWOO metadata in a sampled sitemap validates against the bundled
   DIWOO XSD (same schema version used by the existing admin DIWOO
   validation endpoint);
5. a sampled publication URL from a sitemap resolves publicly with HTTP 200.

Each check MUST produce an individual `pass` / `fail` result with a
machine-readable reason on failure. A check that cannot run because its
prerequisite failed MUST report `skipped`, not `pass`. Outbound requests MUST
go through the existing SSRF-hardened outbound URL guard.

#### Scenario: fully harvestable instance reports all checks passing

- GIVEN an instance with at least one WOO-enabled catalog whose robots.txt,
  sitemapindex, category sitemaps and publication URLs are publicly reachable
  and DIWOO-valid,
- WHEN an admin runs the readiness self-check,
- THEN the report MUST list every check with status `pass`,
- AND the overall verdict MUST be `ready`.

> @e2e exclude Backend outbound-validation contract against the instance's own public surface; requires a publicly-resolvable deployment topology not present in the e2e harness; covered by PHPUnit tests with a mocked HTTP client.

#### Scenario: publicly unreachable sitemapindex fails the check with a reason

- GIVEN a WOO-enabled catalog whose sitemapindex URL returns HTTP 404 from
  the public base URL,
- WHEN an admin runs the readiness self-check,
- THEN the sitemapindex check MUST report `fail` with reason `http-404`,
- AND dependent per-category sitemap checks MUST report `skipped`,
- AND the overall verdict MUST be `not-ready`.

> @e2e exclude Same backend contract as above; PHPUnit with mocked HTTP client.

### Requirement: Readiness report is persisted and retrievable (WOO-HR-002)

The system MUST persist the most recent readiness report (per-check results,
overall verdict, run timestamp, checked base URL) and expose it via an
admin-gated endpoint so the settings UI can render the last-known state
without re-running the checks. Running a new self-check MUST replace the
persisted report atomically.

#### Scenario: last report is returned without re-running checks

- GIVEN a readiness self-check completed at time T,
- WHEN an admin requests the readiness report,
- THEN the persisted report from time T MUST be returned,
- AND no outbound validation requests may be made by that read.

> @e2e exclude Backend persistence contract; covered by PHPUnit.

### Requirement: Woo-index registration status is tracked in configuration (WOO-HR-003)

The system MUST track the organisation's Woo-index registration state as an
admin-editable configuration object with fields `status`
(`not_registered` | `requested` | `registered`), `registeredUrl` (the public
base URL registered in the Woo-index / Register van Overheidsorganisaties)
and `registeredAt` (date). The readiness report MUST include this
registration object, and when `status=registered` the self-check MUST verify
that `registeredUrl` matches the public base URL the checks ran against,
reporting a `url-mismatch` failure otherwise.

#### Scenario: registered URL mismatch is surfaced

- GIVEN registration status `registered` with `registeredUrl`
  `https://old.example.org`,
- AND the instance's configured public base URL is `https://new.example.org`,
- WHEN an admin runs the readiness self-check,
- THEN the registration check MUST report `fail` with reason `url-mismatch`.

> @e2e exclude Backend config contract; covered by PHPUnit.

### Requirement: Readiness endpoints are admin-gated and fail closed (WOO-HR-004)

The readiness run and report endpoints MUST be gated with
`#[AuthorizedAdminSetting]`. When the WOO configuration is absent or no
WOO-enabled catalog exists, the run endpoint MUST fail closed with an
explicit `not-configured` error (HTTP 409) rather than reporting `ready`,
and MUST NOT perform any outbound request.

#### Scenario: unconfigured instance refuses the check instead of passing

- GIVEN an instance with no WOO-enabled catalog,
- WHEN an admin runs the readiness self-check,
- THEN the endpoint MUST respond HTTP 409 with error `not-configured`,
- AND no outbound HTTP request may be made.

> @e2e exclude Backend auth/fail-mode contract; covered by PHPUnit.
