# Tasks: hermiq-ai-tooling

> Depends on `opencatalogi-mcp-adoption` being merged first (continues its REQ numbering; reuses its `OpenCatalogiScannableServices` registration).

## Implementation Tasks

### Task 1: Four `#[McpTool]` write methods with property allow-lists (must / MVP)
- **spec_ref**: `openspec/specs/mcp-tool-surface/spec.md#requirement-write-actions-are-curated-tools-with-allow-listed-properties-req-007`
- **files**: `lib/Mcp/PublicationAgentTools.php` (new), `lib/Mcp/McpArgumentValidator.php` (new, decidesk pattern), `lib/Mcp/OpenCatalogiScannableServices.php` (add the new service to the returned list)
- **acceptance_criteria**:
  - GIVEN the tool catalogue WHEN enumerated THEN it contains exactly 16 opencatalogi tools (12 from the parent change + the 4 writes), all curated ids 2-segment
  - GIVEN each write tool WHEN its metadata is read THEN scope/reach match REQ-007 (`publishPublication`/`depublishPublication` = reach `external`) and both external-reach descriptions state distribution irreversibility
  - GIVEN `createDraftPublication` WHEN called with `publicationDate`, `depublicationDate`, `status`, or a retention property THEN it rejects with a structured invalid-arguments error and creates nothing
  - GIVEN the new PHP WHEN `composer check:strict` runs THEN it is clean
- [ ] Implement
- [ ] Test
  - 25 Nov 2026: `createDraftPublication` built in `lib/Mcp/PublicationAgentTools.php` with its allow-list in
    `lib/Mcp/McpArgumentValidator.php` and registered in `OpenCatalogiScannableServices`; tests in
    `tests/Unit/Mcp/PublicationAgentToolsTest.php`. The tool takes one `publication` object, because
    OpenRegister drops undeclared arguments before the call: a forbidden field can only be refused when the
    tool can see it. The three publish-side tools wait for the approval decision under Task 3.

### Task 2: Delegation through the RBAC'd service path (must / MVP) — BLOCKS Task 3
- **spec_ref**: `openspec/specs/mcp-tool-surface/spec.md#requirement-every-write-runs-the-existing-rbacd-path-as-the-granting-user-req-008`
- **files**: `lib/Mcp/PublicationAgentTools.php`, `tests/Unit/Mcp/`
- **acceptance_criteria**:
  - GIVEN any write tool WHEN its implementation is reviewed THEN every object write goes through the existing `PublicationService`/ObjectService path with `_rbac: true` as the granting user; no direct un-RBAC'd write exists
  - GIVEN a fixture user without write access to a publication WHEN the tool path and the UI path both attempt the write THEN both fail with the same domain error (gate-parity test)
  - GIVEN the phase-2 publish write WHEN executed THEN it writes `publicationDate` and no other property (assert the object diff key set)
- [ ] Implement
- [ ] Test
  - 25 Nov 2026: the draft write goes through OpenRegister's `saveObject` with `_rbac: true, _multitenancy: true`
    as the session user, and a refused save answers a fixed `not_saved` error (tested). The publish-side writes
    follow with Task 3.

### Task 3: Two-phase batch approval, server-enforced (must / MVP)
- **spec_ref**: `openspec/specs/mcp-tool-surface/spec.md#requirement-publish-depublish-and-archive-execute-only-after-a-server-verified-human-approval-req-009`
- **files**: `lib/Mcp/PublicationAgentTools.php`, `lib/Service/` (staged-batch handling), `tests/Unit/Mcp/`
- **acceptance_criteria**:
  - GIVEN phase 1 of publish/depublish/archive WHEN it completes THEN a staged batch exists with the full item list, and no property was written
  - GIVEN phase 2 WHEN invoked without a token, with an expired token, with a token minted for the acting agent, or with a token bound to a different batch THEN it is refused and nothing is written
  - GIVEN phase 2 WHEN invoked with a valid human-approver token bound to the batch THEN each item's allow-listed property is written via Task 2's path
  - GIVEN `createDraftPublication` WHEN invoked THEN it is single-phase (no token concept)
- [ ] Implement
- [ ] Test
  - 25 Nov 2026 (decision 25 Nov 13:50, Ruben): the approval is the publication lifecycle's own review, not a
    token; REQ-009 is amended to say so. Built: `publishPublication` (`lib/Mcp/PublicationAgentTools.php` over
    `lib/Service/Publication/AgentPublishing.php`) publishes only `approved` publications, through the publish
    button's checks and `PublishTransition` as the session user, each refusal named (`not_approved`, `forbidden`,
    `embargo_holds`, `no_documents`, `not_found`, `not_published`); an embargo is judged with nobody allowed to lift
    it, so an agent never releases one early. Tests:
    `tests/Unit/Service/Publication/AgentPublishingTest.php`, `tests/Unit/Mcp/PublicationAgentToolsTest.php`.
    25 Nov 2026 (b-opencatalogi, the staging half): `depublishPublication` and `archivePublication`
    (`lib/Mcp/PublicationAgentTools.php` over `lib/Service/Publication/AgentWithdrawal.php`) write nothing about
    the publication. Each item is read with RBAC as the session user and refused by name when it cannot take the
    action (`not_found`, `forbidden`, `not_public` for a withdrawal, `already_archived`, `not_staged`); every other
    one gets an OpenRegister task through `TaskManagerInterface::create()` as that user (`OpenRegisterTasks`):
    kind `approval`, on the publication (`objectUuid`), offered to `opencatalogi-publishers`, the reason as its
    description, and `metadata` {action, publication, reason, requestedBy, tool}. A reason is required (at most
    2000 characters). Tests: `tests/Unit/Service/Publication/AgentWithdrawalTest.php` (including OpenRegister's own
    `TaskBuilder` accepting the payload as an `available` task for the group), `tests/Unit/Mcp/PublicationAgentToolsTest.php`.
    Left: the completion that withdraws or archives (a `TaskTerminalEvent` listener that, for a committed task with
    this metadata and an approving outcome, takes the action as the completing publisher through the withdraw
    button's path; that path lives in `PublicationStateController::withdrawWithReason()` and is extracted first).

### Task 4: Agent-principal attribution (must / MVP)
- **spec_ref**: `openspec/specs/mcp-tool-surface/spec.md#requirement-every-agent-write-is-attributed-to-the-agent-principal-in-the-audit-trail-req-010`
- **files**: `lib/Mcp/PublicationAgentTools.php`, `tests/Unit/Mcp/`
- **acceptance_criteria**:
  - GIVEN any tool-path write WHEN the object's audit trail is read THEN it carries agent identity, granting user, tool id, batch reference, and (for gated writes) approval token id
  - GIVEN an expired unapproved batch WHEN the trail is read THEN the proposal is visible as proposed-but-not-approved
  - GIVEN the same write performed through the UI WHEN audited THEN it carries no agent fields (control)
- [ ] Implement
- [ ] Test

### Task 5: Hermiq classification check + chat-scenario e2e + docs (must / MVP)
- **spec_ref**: `openspec/specs/mcp-tool-surface/spec.md#requirement-hermiq-governance-is-honoured-but-never-relied-upon-req-011`
- **files**: `tests/e2e/spec-coverage/hermiq-ai-tooling.spec.ts` (new), `docs/`, `CHANGELOG.md`
- **acceptance_criteria**:
  - GIVEN Hermiq enumerates the opencatalogi tools WHEN the four writes are classified THEN all are default-denied writes and publish/depublish show reach `external` (verified once against a live Hermiq, recorded in the change)
  - GIVEN the e2e suite WHEN it runs THEN the batch-publish flow passes: drafts staged → approval → publicly readable + present in sitemap; and a rejected batch publishes nothing
  - GIVEN `docs/` WHEN read THEN it records the tool table (scope × reach × gate), the batch-approval model, the irreversibility warning, and the refusal of write tools on other schemas
  - GIVEN `CHANGELOG.md` WHEN read THEN it records the governed write surface
- [ ] Implement
- [ ] Test

## Verification
- [ ] All tasks checked off
- [ ] `openspec validate hermiq-ai-tooling --type change --strict` passes
- [ ] Manual testing against acceptance criteria (denied grant, rejected batch, approved batch, token replay)
- [ ] Code review against spec requirements

## Tests (company-wide ADR-009)
- [ ] PHPUnit: metadata, allow-lists, gate parity, two-phase state machine, token binding, attribution (Tasks 1–4); zero new failures vs a self-measured baseline
- [ ] Browser tests (Playwright MCP): `tests/e2e/spec-coverage/hermiq-ai-tooling.spec.ts` (Task 5)
- [ ] All tests pass (CI-way, in the container)
- Newman/Postman: N/A — no HTTP endpoint is added; the MCP surface is served by OpenRegister's `/api/mcp`.

## Documentation (company-wide ADR-010)
- [ ] `docs/` records the governed write surface and the approval model (Task 5)
- Screenshots: N/A for OpenCatalogi UI — the approval flow lives in Hermiq; published results use existing UI.

## i18n (company-wide ADR-005)
- N/A — tool descriptions and staged-batch fields are agent-facing/backend prose, not UI copy. Approval-flow copy is Hermiq's.
