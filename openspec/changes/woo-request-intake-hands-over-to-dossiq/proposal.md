---
kind: code
depends_on: [dossiq/woo-request-takes-over-from-opencatalogi]
---

# Proposal: woo-request-intake-hands-over-to-dossiq

## Summary

OpenCatalogi forwards every new Woo request to dossiq, migrates the stored ones with no request left without an armed term, then removes its own intake.

- Rows: supports 7.1 to 7.6 and 10.8 (statutory term, Woo art. 4.4 lid 1 and 2, art. 4.5); they stay yes in dossiq.
- Wave: 3.
- Depends on: `dossiq/woo-request-takes-over-from-opencatalogi` (https://github.com/ConductionNL/dossiq/issues/3289): `OCA\Dossiq\Woo\WooRequestIntake::receive(array $answers, string $receivedAt = '', string $origin = 'portal-form'): array` and `OCA\Dossiq\Woo\OpenCatalogiWooImport::run(bool $dryRun = false): array`, which stamps `migratedTo` and `migratedAt` on `wooRequest`. Runs beside `portaliq/woo-intake-delivers-to-dossiq` (https://github.com/ConductionNL/portaliq/issues/1223).
- Decision: D1 (dossiq owns the request and its term); D12 (no fallback: without dossiq the request routes answer 404).
- Build rules: openspec/woo-build-rules.md

## Why

Decision D1 (Ruben, 2026-10-05) gives the Woo request, its intake and its statutory term to dossiq. Decision D12 adds that Woo requests require dossiq, with no fallback. OpenCatalogi built a statutory-green intake (PR #1736): it mints a request, arms the term of Woo art. 4.4 lid 1 (four weeks from receipt), extends it once by two weeks (art. 4.4 lid 2) and pauses it while clarification is awaited (art. 4.5). Moving the request must not leave a single request without an armed term in between, and must not regress any of that.

This change closes no row itself. It supports rows 7.1 to 7.6 and 10.8, all yes today in OpenCatalogi, so they stay yes once dossiq owns them. From `opencatalogi/_round1/compare/M1-rows.md` with our column (`baseline/openwoo.tsv`): 7.1 "A citizen submits a request through a form" (yes, PR #1736), 7.2 "The request gets a reference the citizen can quote back" (yes), 7.3 statutory "The statutory term is computed and shown" (yes, `StatutoryTerm::arm`), 7.4 statutory "The term extends once, and the statutory maximum is enforced" (yes, `EXTENSION_MAX = 1`), 7.5 statutory "The term pauses while the organisation waits for clarification" (yes), 7.6 statutory "Working days and Dutch public holidays decide the term" (yes), 10.8 "The clock start is told to the requester at intake" (yes).

Read on development at 35999c296: `WooRequestController` serves seven routes under `/api/woo/requests`; `OCA\OpenCatalogi\Service\Woo\WooRequestIntake::receive(array $answers, string $receivedAt = '')` mints and arms; `PortalContributionProvider::receiveWooRequest(array $answers, string $receivedAt = ''): array` answers `{outcome, requestId, reference, dueAt, message}` for portaliq; `StatutoryTerm` arms the term on OpenRegister's FlowTimer. The `wooRequest` schema is in `lib/Settings/register.d/woo-request-intake-and-comment-periods.json`. There is no Woo request page in `src/manifest.json`.

The other side is written (dossiq PR #3285, change `woo-request-takes-over-from-opencatalogi`, issue #3289). It offers `OCA\Dossiq\Woo\WooRequestIntake::receive(array $answers, string $receivedAt = '', string $origin = 'portal-form'): array`, answering `{outcome: 'armed'|'not-armed'|'refused'|'unavailable', requestId, reference, dueAt, message, caseUrl}` (REQ-WTO-001), and `OCA\Dossiq\Woo\OpenCatalogiWooImport::run(bool $dryRun = false): array`, answering `{imported, alreadyImported, failed: list<{requestId, reference, reason}>, unmigrated, migrated: list<{requestId, reference, caseId, termTimer}>}` (REQ-WTO-004). The import stamps each source `wooRequest` with `migratedTo` (the case uuid) and `migratedAt`, and counts a stamp OpenCatalogi's schema refuses as failed. It never stops OpenCatalogi's own term timer; it lists them in `migrated` for OpenCatalogi to stop.

## What changes

1. The `wooRequest` schema declares `migratedTo` and `migratedAt`. This ships in the same release as the forward, because without it OpenRegister drops the undeclared stamp and dossiq's import counts every request as failed.
2. While dossiq is installed, a new request is forwarded: `PortalContributionProvider::receiveWooRequest()` calls dossiq's `receive()` with origin `portal-form`, and `POST /api/woo/requests` with origin `opencatalogi`. OpenCatalogi answers with dossiq's reference and due date and mints nothing itself. A failed forward answers `unavailable` with a message the caller can retry on, and mints nothing.
3. A repair step runs dossiq's import, stops OpenCatalogi's own term timer for every request in `migrated`, and stores and reports the count still unmigrated.
4. When that count is zero, the `wooRequest` schema becomes read-only: a pre-save listener refuses every create and every change except the import stamp.
5. Without dossiq, OpenCatalogi offers no Woo request intake: the seven request routes answer 404, `receiveWooRequest()` answers `unavailable` with the message that Woo requests are handled by dossiq, and the Woo settings section says so with a link to install dossiq. OpenCatalogi keeps publishing decisions and their documents either way.
6. The release after the read-only release removes OpenCatalogi's intake, its term arming and its request routes (a second PR under this change).

## Fail closed

- No request is ever without an armed term. A forward that fails mints nothing in either app and tells the caller to retry. A migrated request's OpenCatalogi timer is stopped only after it is listed in `migrated`, which dossiq writes only after its own timer is armed.
- A stamp is the only write the read-only schema accepts, and only for the two stamp keys.
- The removal PR is not opened while any instance reports an unmigrated count above zero on the release before; the PR body states the count read from the readiness check.
- Without dossiq there is no fallback intake (D12). The request routes answer 404, never a silently stored request nobody arms.

## Out of scope

- Drafting the Woo decision. dossiq drafts it through filinq's contract (`DocumentGenerationRequestedEvent` with `templateSlug` `woo-besluit` or `woo-inventarislijst` and `data.wooDecision`, filinq PR #1341, dossiq REQ-WTO-005). OpenCatalogi adds no drafting of its own.
- portaliq's switch to dossiq (`portaliq/woo-intake-delivers-to-dossiq`). During the cut-over OpenCatalogi's forward keeps a portal request armed either way.
- Publishing decisions and documents, which stays in OpenCatalogi.

## Dependencies

- `dossiq/woo-request-takes-over-from-opencatalogi` (dossiq, planned in this programme, wave 2; PR #3285, issue #3289): `WooRequestIntake::receive()` and `OpenCatalogiWooImport::run()` as named above. That change starts after `dossiq/woo-requester-notices-really-go-out` and `dossiq/woo-term-is-computed-and-reported-right`.
- `portaliq/woo-intake-delivers-to-dossiq` (portaliq, wave 3) runs beside this.

## Wave

Wave 3, after the dossiq takeover (plan section "The Woo request moves to dossiq", step 2).

## Decisions

- D1: dossiq owns the Woo request, its intake and its term. Implemented: OpenCatalogi forwards, migrates and then removes its intake.
- D12: Woo requests require dossiq; no fallback. Implemented: without dossiq the request routes answer 404 and the settings say why. The refusal grounds' read-only fallback for redaction is not touched here (`woo-value-lists-on-the-concept-register`).
- Consequence Ruben chose, for the release notes: an installation that runs OpenCatalogi without dossiq and takes Woo requests today loses request intake when the removal ships.

This change supersedes, at removal, the main spec `woo-request-intake` requirements REQ-WRI-001 to REQ-WRI-006 and the portal delivery REQ-WRI-008 for OpenCatalogi; their behaviour continues in dossiq (REQ-WTO-001 to REQ-WTO-004). REQ-WRI-007 (a batch works without a request) stays.

## Rows

| row | text | rating today | what makes it yes |
|---|---|---|---|
| 7.1 to 7.6, 10.8 | (supported; closed in dossiq) | yes | they stay yes through the move: REQ-WHD-002 (forward with dossiq's reference and due date), REQ-WHD-003 (every stored request migrated with its term), scenario "A portal request is armed in dossiq" |

## Release notes

- Woo requests are now handled by dossiq. With dossiq installed, nothing changes for the requester; existing requests move with their remaining time and their old reference still finds them.
- Without dossiq, OpenCatalogi no longer takes Woo requests once the removal ships. Install dossiq to keep taking them.
