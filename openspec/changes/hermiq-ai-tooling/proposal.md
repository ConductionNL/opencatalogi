# Proposal: hermiq-ai-tooling

## Summary

Extend OpenCatalogi's MCP surface from "read-only + two curated reads" to **full action coverage under Hermiq governance**. `opencatalogi-mcp-adoption` (which this change builds on and does not duplicate) derives 10 read tools from 5 schemas, preserves `searchCatalogPublications` and `listPublicationFiles` as curated `#[McpTool]` methods, and refuses every write verb because in this app **a write verb is a publish verb**: `publicationDate` is an ordinary writable property *inside* the RBAC rule that grants the `public` group read access, so an agent that can write it can publish a government record to the public internet by writing one date. This change adds writes anyway — but as curated, property-allow-listed, approval-gated `#[McpTool]` methods that make that exact attack structurally impossible: `opencatalogi.createDraftPublication` (cannot set any date/status field), `opencatalogi.publishPublication` / `opencatalogi.depublishPublication` (two-phase, mandatory human approval, classified reach `external`), and `opencatalogi.archivePublication` (approval-gated; `status` enum is `published|archived`, verified). Governance is Hermiq's existing model: scope (`read`/`create`/`update`/`delete`) × reach (`self`/`user`/`instance`/`external` per `ToolReachResolver`), default-deny write grants per agent, human approval gates, full agent-principal audit. The PO's canonical chat scenario becomes real: *"publish the draft dataset descriptions"* → the agent stages a publish batch, a human editor approves it, the records go live — with every step attributed.

## Motivation

- **PO framing (fleet-wide):** every app provides MCP tooling for all its actions so any action can in principle be automated; users grant rights per agent, granularly; even without automation, chat is a command surface for the app.
- **The read-only refusal named its own exit.** `opencatalogi-mcp-adoption` Risk 1 refuses dialect writes because the dialect cannot constrain which properties a write touches, and records exactly that as the open question ("Can the dialect constrain *which properties* a write verb may touch?"). Curated service methods answer it: the method's argument schema and server-side allow-list ARE the property constraint. The fleet reference for gated write tools is `decidesk/lib/Mcp/` (`McpMeetingGate`, `McpArgumentValidator`, scope resolver).
- **Publishing is the one act that must never be a plain write.** Once `publicationDate <= now`, the DCAT harvester, the sitemap, and federated listings distribute the record; depublication does not un-distribute it. So `publishPublication` is modelled as an explicitly approval-gated action with reach `external` — not as an `update` on a schema — making the blast radius visible in Hermiq's grant UI.
- **The drafting half is safe and valuable now.** Turning "here are twelve dataset descriptions in this email/document" into twelve well-formed drafts is exactly what an agent is good at, and a draft (no `publicationDate`) is invisible to the public by construction.

## Affected Projects

- [ ] Project: opencatalogi — new `#[McpTool]` write methods on `lib/Service/PublicationService.php` (or a dedicated `PublicationAgentTools` service listed by the existing `OpenCatalogiScannableServices`), staged-approval handling, tests
- [ ] Project: openregister — **no code change**; `AttributeToolScanner` discovers the methods
- [ ] Project: hermiq — **no code change**; consumes declared scope/reach hints as it already does

## Capabilities

- `mcp-tool-surface` — extended: governed write-action tools join the capability created by `opencatalogi-mcp-adoption` (REQ-001…REQ-006 remain; this change adds REQ-007…REQ-011)

## Scope

### In Scope

- Four curated write tools, discovered via the existing `IMcpScannableServices::opencatalogi` registration:
  - `opencatalogi.createDraftPublication` — scope `create`, reach `instance`; property allow-list: `title`, `summary`, `description`, `organization`, `themes` (all verified `publication` properties); MUST NOT accept `publicationDate`, `depublicationDate`, `status`, or any retention field
  - `opencatalogi.publishPublication` — scope `update`, reach `external`, **two-phase with mandatory human approval**; phase 2 sets `publicationDate` (and nothing else)
  - `opencatalogi.depublishPublication` — scope `update`, reach `external`, **two-phase with mandatory human approval**; sets `depublicationDate`; its description MUST state that depublication does not un-distribute already-harvested copies
  - `opencatalogi.archivePublication` — scope `update`, reach `instance`, **approval-gated**; sets `status: "archived"` on an already-depublished record, per the retention flow
- Server-side approval enforcement in the tool path (not delegated to the MCP client), decidesk-gate style
- Agent-principal attribution on every write in the OpenRegister audit trail
- 2–3 documented chat scenarios as verification fixtures

### Out of Scope

- Any `x-openregister-mcp` write verb on any schema — REQ-002 of `opencatalogi-mcp-adoption` stands unmodified
- Write tools for `catalog`, `document`, `organization`, `theme`, `page`, `menu`, or any other schema (publication is the workflow; a writable `catalog.published` would be a second publish path and is refused here for the same reason the dialect write was)
- `delete` scope anywhere (WOO records are retention-governed, not deletable by agents)
- Changing `publication.authorization` (the embargoed-read question from `opencatalogi-mcp-adoption` Risk 3 stays a product decision)
- Hermiq-side UI or approval-flow changes

## Approach

1. Depend on `opencatalogi-mcp-adoption` landing first (this change continues its REQ numbering and reuses its scannable-services registration).
2. Add the four `#[McpTool]` methods with honest `scope`/`reach`/hint metadata and argument validation; every method delegates to the existing guarded object-write path (`_rbac: true`) so OpenRegister RBAC and the catalog-scope checks keep running.
3. Implement the two-phase approval (stage → human token → execute) server-side, mirroring the decidesk gate pattern.
4. Verify Hermiq classifies publish/depublish as external-reach writes and default-denies them until granted.

## New Dependencies

None.

## Impact

- Tool count grows from 12 to 16.
- A new (governed) write path to `publicationDate`/`depublicationDate`/`status` exists; it is narrower than the existing UI/REST write paths (allow-listed properties, approval token), never wider.
- No schema, register, or manifest change; no migration.

## Cross-Project Dependencies

- **opencatalogi-mcp-adoption** MUST be merged first.
- **openregister** ≥ the commit carrying `AttributeToolScanner` + `IMcpScannableServices` (present at `origin/development`).
- **hermiq** ≥ the commit honouring declared hints on 2-segment curated tools (hermiq #57, merged).

## Risks

### Risk 1: The publish tool is exactly the attack `opencatalogi-mcp-adoption` refused

- **Severity**: High
- **Detail**: Risk 1 of the parent change: an agent that can write `publicationDate` can publish a government record to the public internet, and distribution is irreversible.
- **Mitigation**: Three independent layers, each sufficient alone: (1) Hermiq default-denies the tool until a human grants it to a specific agent; (2) the tool is two-phase and Scholiq-style server-side gated — no valid human approval token, no property write, regardless of client; (3) the phase-2 write goes through the existing RBAC'd path as the granting user, so an agent can never publish what its human may not. A prompt-injected agent must defeat all three.

### Risk 2: Draft-spam from over-eager agents

- **Severity**: Low
- **Detail**: `createDraftPublication` is single-phase; a misbehaving agent could create many drafts.
- **Mitigation**: Drafts are invisible to the public by construction, attributed per REQ-010, and bulk-deletable by editors; Hermiq budgets/rate limits apply. Accepted.

### Risk 3: Approval fatigue on batch publishes

- **Severity**: Medium
- **Detail**: "Publish these 30 dataset descriptions" as 30 approvals trains rubber-stamping.
- **Mitigation**: The staged proposal is batch-shaped: one approval covers one reviewed batch whose full item list the approver sees; per-item approval remains available.

### Risk 4: Two publish paths drift (UI vs tool)

- **Severity**: Low
- **Mitigation**: The tool writes through the same service path as the UI; REQ-008 pins gate parity with a test.

## Rollback Strategy

Revert the commit. The tool methods and staged proposals disappear; the derived read surface and the two curated reads from the parent change are untouched. Publications already published via approved batches remain published — correctly, since a human approved them.

## Open Questions

- Should `publishPublication` also require the publication to pass `QualityService`/WOO-readiness checks before staging (fail-early), or is the human approver the quality gate? Leaning fail-early; needs PO confirmation.
- Batch size cap for a single approval (30? 100?) — a number an approver can actually review.
- Should `archivePublication` be reach `external` too (archival changes what federated consumers see)? Current classification: `instance`, because archive does not add public exposure; revisit with the federation team.
