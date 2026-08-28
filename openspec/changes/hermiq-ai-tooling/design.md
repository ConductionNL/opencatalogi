# Design: hermiq-ai-tooling

## Context

After `opencatalogi-mcp-adoption`, the MCP surface is: 10 derived read tools (`{publication,catalog,document,organization,theme}.{search,get}`) + 2 curated reads (`searchCatalogPublications`, `listPublicationFiles`) on `PublicationService`, discovered via `OpenCatalogiScannableServices` (`IMcpScannableServices::opencatalogi`). Writes were refused with a mechanism-specific rationale: the dialect cannot constrain which properties a write touches, and `publicationDate` (verified: `publication.authorization.read = [{"group": "public", "match": {"publicationDate": {"$lte": "$now"}}}, "authenticated"]`) is the publication gate itself. The parent change's Open Questions explicitly hold the door open for a write path that CAN constrain properties.

Fleet context: decidesk `lib/Mcp/` (gate + argument validator + scope resolver) is the reference for governed write tools; hermiq governs via scope × reach (`ToolReachResolver`: `self`/`user`/`instance`/`external`), default-deny grants, `ApprovalService`, guardrail policies, audit.

Verified schema facts this design leans on: `publication` properties include `title`, `summary`, `description`, `organization`, `themes`, `publicationDate`, `depublicationDate`, `status` (enum `published|archived`), `retentionCategory` and friends; `document` and `catalog` carry their own date/`published` gates; there is no draft value in `status` — "draft" IS the absence of `publicationDate`.

## Goals / Non-Goals

**Goals**
- Draft-creation, publish, depublish, and archive callable by a governed agent, with the publish act structurally unable to happen without a human.
- The canonical chat scenario: *"publish the draft dataset descriptions"* end-to-end with one reviewed batch approval.
- Honest blast-radius labelling: publish/depublish are reach `external` because harvest/sitemap/federation distribution is irreversible.

**Non-Goals**
- Any second publish path (no writes on `catalog`/`document`), no `delete` scope, no dialect write verbs, no authorization changes.

## Decisions

### Decision 1: Four tools, one schema, allow-listed properties

| Tool id | Scope | Reach | Gate | Writes (exhaustive) |
|---|---|---|---|---|
| `opencatalogi.createDraftPublication` | create | instance | none (single-phase) | `title`, `summary`, `description`, `organization`, `themes` |
| `opencatalogi.publishPublication` | update | **external** | **two-phase human approval** | `publicationDate` only |
| `opencatalogi.depublishPublication` | update | **external** | **two-phase human approval** | `depublicationDate` only |
| `opencatalogi.archivePublication` | update | instance | **approval-gated** | `status: "archived"` only |

The allow-lists are enforced server-side (argument schema + a hard filter before the object write), not by prompt. `createDraftPublication` rejecting the date/status/retention fields is what makes it safe enough to be single-phase: the worst a rogue agent can do is create invisible drafts.

### Decision 2: Publish is an action, not an update

Modelling publish as `publication.update` (dialect or curated) would hide the act inside a generic verb. Modelling it as its own tool: (a) gives Hermiq a distinct grantable unit — an admin can grant drafting without granting publishing; (b) lets the description carry the irreversibility warning ("harvested copies are not recalled by depublication" — the parent change's Risk 1 fact, restated to the agent and the approver); (c) makes the audit trail read as intent ("publishPublication approved by X") rather than a property diff.

### Decision 3: Server-side two-phase approval (decidesk-gate pattern)

Phase 1 validates, resolves the target publication(s), and stages a batch proposal (publication ids + intended date + requesting agent + granting user); no property is written. Phase 2 executes only with an approval token Scholiq-style verified server-side: minted for a human approver, distinct from the acting agent, unexpired, bound to that batch. Hermiq's `ApprovalService` is the intended UX; a non-Hermiq MCP client without a token simply cannot publish. The batch is the approval unit (Risk 3): the approver sees the full item list; one approval, one reviewed batch.

### Decision 4: Every write goes through the existing RBAC'd service path as the granting user

Phase-2 writes (and draft creation) delegate to the same `PublicationService`/ObjectService path with `_rbac: true` that the UI uses. Consequences: an agent can never publish what its granting user could not; catalog-scope checks keep running; gate parity is testable (REQ-008 scenario: a write the UI would refuse fails identically through the tool).

### Decision 5: Attribution — agent principal in the audit trail

Every tool write records agent identity, granting user, tool id, batch/proposal reference, and approval token id (where gated), on top of OpenRegister's normal audit fields. "Who published this?" must answer "agent A proposed, editor B approved, on behalf of user C" from the trail alone.

### Decision 6: Chat scenarios as verification fixtures

1. **Batch publish** — "Publish the draft dataset descriptions." → derived `publication.search` (authenticated read; drafts visible to the agent's principal) → `publishPublication` phase 1 stages the batch → editor approves in Hermiq → phase 2 sets `publicationDate` per item → records appear on the public surface/sitemap/harvest.
2. **Email-to-draft-to-publish** (with the `leaf-integrations` change): desk turns request emails into drafts via the Mail sidebar; the agent tidies titles/summaries via `createDraftPublication`-adjacent editing? — no: editing existing drafts is an `update` this change does not grant; the agent instead creates *new* well-formed drafts from pasted source text and flags the originals for the editor. Scoping honesty stated in the tool descriptions.
3. **Depublish sweep** — "The court ruling means these three publications must come down." → `depublishPublication` staged with the reason recorded in the proposal → approver confirms → `depublicationDate` set; the tool's response restates that harvested copies are not recalled and names the federation contacts step from the runbook.

## Risks / Trade-offs

- Staged batch proposals are a small new internal state (no register schema exposure); accepted for gate integrity.
- Scenario 2 reveals a gap (no `updateDraftPublication`); deliberately deferred — an update tool needs its own allow-list argument and is easy to add once drafting proves out.

## Migration Plan

Additive; ships after `opencatalogi-mcp-adoption`. Rollback = revert; approved-and-published records stay published (a human approved them).

## Open Questions

Carried in proposal.md (fail-early quality checks; batch cap; archive reach classification).
