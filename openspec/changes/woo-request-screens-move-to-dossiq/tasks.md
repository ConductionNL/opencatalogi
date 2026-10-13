# Tasks: woo-request-screens-move-to-dossiq

Read `openspec/woo-build-rules.md` first. One PR, `--base development`.

## 1. No officer screen (this PR)

- [x] 1.1 Add `tests/Unit/AppInfo/NoWooRequestScreenTest.php` with `testTheManifestHasNoWooRequestPage` and `testTheRequestRowsPointAtDossiq` (REQ-WRS-001). Verify: the test passes on the manifest as shipped, and fails when a page with `"schema": "wooRequest"` is added.
- [x] 1.2 Set `screen` on the parity rows `wr-request-record` and `wr-search-sources` to `{board: null, reason: ...}` naming `DqWooVerzoeken` and `DqZaak` (REQ-WRS-001). Verify: `testTheRequestRowsPointAtDossiq`.

## 2. Batch and publication contract

- [x] 2.1 Confirm the batch form offers no `wooRequest` picker (REQ-WRS-002). Verify: `git grep -n wooRequest -- src` finds nothing.
- [x] 2.2 Confirm the `publication` schema declares `caseReference`, `publicationKind`, `wooCategory` and `period` (REQ-WRS-003). Verify: `WooJourneyRegisterTest` passes.

## 3. Boards (design-system)

- [ ] 3.1 Retire `OcWooVerzoek` and `OcWooVerzoeken`, and drop "Gekoppeld Woo-verzoek" from `OcWooBatchAanmaken` (design-system PR). Verify: the gallery lists neither board, and `OcWooBatchAanmaken` has no request picker.

## 4. Later, with the removal

- [ ] 4.1 When `woo-request-intake-hands-over-to-dossiq` group 6 ships, move the parity row `wr-request-record` to `built.owner` `ConductionNL/dossiq`. Verify: the row in `openspec/parity/capabilities.json`.
