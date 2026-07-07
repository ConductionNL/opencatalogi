# Tasks: product-metadata-and-readme-honesty

This change is `kind: config` (ADR-032): metadata + documentation corrections
only. No behavioural code.

- [ ] Freeze the delta spec under
  `openspec/changes/product-metadata-and-readme-honesty/specs/app-packaging/spec.md`
  (ADDED PKG-001…004); confirm
  `openspec validate product-metadata-and-readme-honesty --strict` is green
  - Spec ref: specs/app-packaging/spec.md (this change)
  - Acceptance: validator reports valid
- [ ] `appinfo/info.xml`: change `<licence>agpl</licence>` to the EUPL-1.2 value
  the app store accepts (consistent with `LICENSE`/`composer.json`/`publiccode.yml`)
  - Spec ref: PKG-001
  - Acceptance: all four licence declarations agree on EUPL-1.2; bump `<version>`
    per NC immutable-cache-bust convention
- [ ] `appinfo/info.xml`: change `<nextcloud min-version="28" …>` to
  `min-version="31"`, leaving `max-version` at the supported ceiling
  - Spec ref: PKG-002
  - Acceptance: `min-version` is `31`; app still installs on the target NC
- [ ] README: replace ElasticSearch claims (Features + Tech Stack) with
  OpenRegister SOLR as the optional backend; mark document-content full-text
  search as planned, linking `add-public-fulltext-search`
  - Spec ref: PKG-003
  - Acceptance: no "ElasticSearch" claim remains; document-content search framed
    as planned
- [ ] `README_AGGREGATED_PUBLICATIONS.md`: correct the endpoint to
  `GET /api/federation/publications`, name `PublicationService::getAggregatedPublications`
  as the entry point, and cross-reference the `federation` capability (FED-001)
  - Spec ref: PKG-004
  - Acceptance: `/api/publications/aggregated` no longer presented as the
    endpoint; documented path matches `appinfo/routes.php`
- [ ] Re-verify consistency: grep `LICENSE`/`composer.json`/`publiccode.yml`/`info.xml`
  agree on EUPL-1.2; grep README for `ElasticSearch`; grep routes for the
  documented aggregated path
  - Spec ref: PKG-001…004
  - Acceptance: all consistency checks pass
