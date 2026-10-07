# Tasks: diwoo-metadata-on-the-publication-filinq-handoff

Read `openspec/woo-build-rules.md` before the first command. Start once `diwoo-metadata-on-the-publication` is merged on `development`: this change tests against its `documentsoort` field, its `GET /api/woo/documentsoorten` route and its `DiwooCompletenessListener`. Read filinq's `OCA\FilinQ\Service\Publication\OpenCatalogiPublicationMap::toPublication()` on `ConductionNL/filinq` branch `development` before writing the payload, and the paired filinq change `diwoo-documentsoort-to-opencatalogi` (https://github.com/ConductionNL/filinq/issues/1344) for the shape it will write.

## 1. Cross-app contract with filinq (decision D8)

- [ ] 1.1 On this side, save the exact payload filinq's `OCA\FilinQ\Service\Publication\OpenCatalogiPublicationMap::toPublication()` will produce after its paired change (`title`, `publicationDate`, `documentsoort` as label or URI, no `summary`) (REQ-DWP-006). Verify: `DiwooCompletenessListenerTest::testTheFilinqHandoffPayloadIsAccepted` and `::testAFilinqHandoffWithAnUnknownTypeIsRefused`.
- [ ] 1.2 Open an issue on `ConductionNL/filinq` (or comment on the filinq lane's change) stating the contract of REQ-DWP-006 and that filinq needs its own test that `toPublication()` writes `documentsoort` and never `summary`. Link it in the PR body. With filinq absent nothing in this change depends on it. Verify: the issue link in the PR body.

## 2. Verification

- [ ] 2.1 `TMPDIR` set to a sibling directory outside the clone. PHPUnit judged by the `Tests:` line or run with `--no-coverage`; a green suite exits 1 without a coverage driver.
- [ ] 2.2 `run-hydra-gates.sh --base origin/development`, counting the gates that ran; NOT APPLICABLE is not a pass.
- [ ] 2.3 Once before push: `COMPOSER_PROCESS_TIMEOUT=0 composer check:strict`, then `npm run lint`, `format`, `check:l10n`, `check:l10n-js`, `check:manifest`, `check:schema-l10n`. The coverage guard needs a test for every added statement.
- [ ] 2.4 One PR with `--base development`; merge development in, never rebase; no `Co-Authored-By` on any commit.

Done when merged on `development` with CI green, and filinq's own test of the same contract exists on filinq `development`. Row 2.25 for filinq handoffs reads `production` only once a store release ships both sides.
