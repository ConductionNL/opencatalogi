---
kind: code
depends_on: []
---

# Proposal: woo-index-harvester-readiness

## Summary
Add a Woo-index **harvester-readiness self-check** and registration-status
tracking to OpenCatalogi. A new admin endpoint validates, from the outside in,
everything the KOOP Woo-harvester needs to actually ingest this instance:
public reachability of `robots.txt`, the DIWOO sitemapindex and per-category
sitemaps, XML validity against the DIWOO XSD, and stable public publication
URLs. The result is a persisted, per-check readiness report surfaced in the
admin settings UI, together with a tracked Woo-index registration status
(registered URL, date, state) so an organisation can *prove* harvestability
instead of assuming it.

## Motivation
Market research (2026-07-23 deep-dive, logged in the spectr register) shows
708 of 971 Woo-plichtige organisations are registered in the Woo-index but
**only 3 are actually connected to the daily Woo-harvester** (Rijkswaterstaat,
provincies Zeeland and Gelderland — od-online, "Woo-index na 1 jaar").
Demonstrable harvestability is therefore a concrete procurement differentiator:
competitor CARE markets exactly this ("working Woo-index harvesting
integration"), and OpenGDC advertises automatic national-portal registration.
OpenCatalogi already ships the hard part — DIWOO sitemaps per catalog per
informatiecategorie (WOO-001..010), TOOI binding, robots.txt — but nothing
verifies the *deployed* instance is externally reachable, valid, and
registered. Sitemaps that render locally but 404 publicly, an overriding
root `robots.txt`, or an expired XSD conformance are silent failures the
harvester never reports back. The self-check closes the loop the way the
delayed Generieke Woo-voorziening (≥9 months, Kamerbrief 2026-02-16) cannot:
municipalities need to self-serve proof now.

## Scope
- New `WooReadinessService` performing the outside-in checks against the
  instance's own public base URL (reusing the existing SSRF-hardened outbound
  URL guard for any fetch it performs).
- New admin endpoints under `/api/woo/readiness` (run check, get last report)
  gated with `#[AuthorizedAdminSetting]`, fail-closed when the WOO
  configuration (woo-enabled catalogs) is absent.
- Persisted readiness report (appconfig JSON) with per-check status +
  timestamps; re-run on demand from the settings UI.
- Woo-index registration status tracking: `wooIndexRegistration` config object
  (status: not_registered | requested | registered; registered URL; date),
  editable in admin settings, included in the readiness report.
- Settings UI panel (Woo section) rendering the report with per-check
  pass/fail and remediation hints, plus the registration status editor.

## Out of scope
- Automatic registration with the Woo-index itself (no public API exists;
  registration runs through the Register van Overheidsorganisaties process).
- Any change to sitemap/robots generation (WOO-001..010 unchanged).
- Notifications on readiness regression (future; would ride ADR-031 dialect).

## Impact
- New: `lib/Service/WooReadinessService.php`, `lib/Controller/WooReadinessController.php`
  (or extension of `WooController`), settings UI panel component.
- Touched: `appinfo/routes.php` (2 admin routes), `lib/Settings` config keys,
  `src/views/settings` Woo panel.
- Specs: delta on `woo-compliance` (ADDED WOO-HR-001..004).
