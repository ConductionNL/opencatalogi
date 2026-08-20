## ADDED Requirements

### Requirement: Cross-surface feature vocabulary MUST agree (BETA-001)
The app metadata (`appinfo/info.xml`), the product page (`conduction.nl/apps/opencatalogi`, EN + NL), and the docs site (`opencatalogi.conduction.nl`) MUST describe the same, code-verified feature set. No surface may claim a capability (integration, standard, or compliance) that has no corresponding implementation in `lib/` or `src/`.

#### Scenario: info.xml summary is localized
- GIVEN a Nextcloud administrator viewing the App Store listing in Dutch
- WHEN the listing is rendered
- THEN `appinfo/info.xml` MUST provide a `lang="nl"` `<summary>` and `<description>` distinct from a machine/English copy

#### Scenario: marketing claims map to shipped code
- GIVEN a claim on the product page (e.g. an integration, a standard, a compliance regime)
- WHEN the claim is checked against `lib/Controller/*`, `lib/Mcp/*`, `lib/Cron/*`, and `src/manifest.json`
- THEN there MUST exist a corresponding controller, cron job, MCP tool provider, or manifest entry; otherwise the claim MUST be removed or corrected

#### Scenario: quantitative claims are verified against the spec/code
- GIVEN a product page states a count for a compliance regime (e.g. "N Woo information categories")
- WHEN the count is checked against the relevant `openspec/specs/*/spec.md` requirement
- THEN the stated count MUST match the spec's authoritative count (17 mandatory Woo information categories, per WOO-003)

### Requirement: Marketing version/status MUST derive from info.xml (BETA-002)
The product page's displayed `version` and `status` MUST be derived from `appinfo/info.xml`'s `<version>`, not from `src/manifest.json`'s internal schema version (which tracks an unrelated manifest-format contract).

#### Scenario: version label tracks info.xml
- GIVEN `appinfo/info.xml` declares `<version>0.7.41</version>`
- WHEN the product page hero renders a version label
- THEN the label MUST be derived from `0.7.41` (e.g. `v0.7`), not from `src/manifest.json`'s `version` field
