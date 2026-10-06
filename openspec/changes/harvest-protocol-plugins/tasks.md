# Tasks: harvest-protocol-plugins

- [ ] 0.1 Author the delta spec: plugin interface, per-protocol fetch/parse
      contracts, incremental semantics per protocol
- [ ] 1.1 Protocol plugin interface + registry; `dcat-jsonld` refactored onto
      it without behaviour change
- [ ] 1.2 DCAT Turtle/RDF-XML plugin (RDF library enters the composer tree
      here; pin and audit it)
- [ ] 1.3 OAI-PMH client plugin: resumption-token loop, `from` incremental,
      deleted-status handling (XML via `file_get_contents` +
      `simplexml_load_string` in tests)
- [ ] 1.4 CKAN plugin: package walk + mapping
- [ ] 1.5 schema.org plugin: sitemap walk + JSON-LD script extraction
- [ ] 2.1 Unit tests per plugin against local fixtures
- [ ] 2.2 Integration test: harvest this instance's own `oai-pmh-endpoint`
      round-trip
- [ ] 3.1 Quality gates as usual

## 4. Amendment 2026-10-05: website and CMS protocols (row 1.6)

Build after `harvest-feed-intake` has merged; its feed, item and run model and its outbound-URL guard are what these plugins plug into. Task 2.2 above can no longer harvest `oai-pmh-endpoint` (D10 struck it); use a recorded OAI-PMH fixture instead. Read `openspec/woo-build-rules.md` first; for OpenRegister doubles copy `environmentAwareDouble()` from `tests/Unit/Service/SitemapServiceTest.php`. Use recorded fixtures under `tests/fixtures/harvest/` (a WordPress REST page set, a sitemap with JSON-LD pages); no live site in unit tests.

- [ ] 4.1 Add the `website-sitemap` plugin (REQ-HPP-001, REQ-HPP-003). Verify: `tests/Unit/Service/Harvest/WebsiteSitemapPluginTest.php::testPagesWithJsonLdBecomeItemsAndPagesWithoutAreSkipped`, `::testAPrivateAddressIsRefused`, `::testOnlyAChangedPageUpdatesItsDraft`, `::testADisappearedPageIsTombstoned`.
- [ ] 4.2 Add the `wordpress-rest` plugin with paging and `modified_after` (REQ-HPP-001, REQ-HPP-003). Verify: `tests/Unit/Service/Harvest/WordpressRestPluginTest.php::testPostsBecomeDraftPublicationsWithTheirSource` (fails today), `::testPagingFollowsTheTotalPagesHeader`, `::testModifiedAfterComesFromTheLastSuccessfulRun`.
- [ ] 4.3 Enforce draft-only on the harvest save path, and park an update to a published record as a conflict (REQ-HPP-002). Verify: `tests/Unit/Service/Harvest/HarvestDraftOnlyTest.php::testAHarvestedPublicationHasNoPublicationDate`, `::testAnUpdateToAPublishedRecordIsParkedAsConflict`.
- [ ] 4.4 Register both protocols in the feed form's protocol choice, through the registry, so the flow handler reaches them (REQ-HPP-001). Verify: a registry test asserting both are resolvable by name from the handler `harvest-feed-intake` registers, and `tests/e2e/harvest-cms.spec.ts` creating a `wordpress-rest` feed in the settings, carrying `@e2e` REQ-HPP-001.
- [ ] 4.5 Live: point a feed at a public WordPress site on the dev instance, run it once, and paste the run's counts and one draft in the PR body. Verify: the pasted output.
- [ ] 4.6 Verification: `TMPDIR` a sibling directory outside the clone; PHPUnit by the `Tests:` line or `--no-coverage`; `run-hydra-gates.sh --base origin/development` counting the gates that ran; once before push `COMPOSER_PROCESS_TIMEOUT=0 composer check:strict` and `npm run lint`, `format`, `check:l10n`, `check:l10n-js`, `check:manifest`, `check:schema-l10n`; one PR with `--base development`, merge development in, never rebase, no `Co-Authored-By`.

Done when merged on `development` with CI green. Row 1.6 becomes `production` only once a store release ships it.
