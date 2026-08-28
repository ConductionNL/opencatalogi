# Design: woo-transparency

## Architecture Overview

This change follows **hydra ADR-022** (apps consume OpenRegister abstractions
over local duplication). The WOO feature is decomposed into three layers:

1. **Shared mechanics → consumed from OpenRegister leaves** (queue/board, workflow
   state, approval gates). Built by OpenRegister, consumed here. Not reimplemented.
2. **WOO-specific domain logic → built in OpenCatalogi** (weigeringsgronden,
   redaction metadata + audit, inventarislijst). Genuinely WOO; no leaf exists.
3. **Public reading-room rendering → built in OpenCatalogi** on the existing
   Catalog/Publication/Listing CMS surface. A public website, not a leaf.

See `specs/woo-transparency/spec.md` for the detailed requirements and scenarios;
each requirement now states whether it is *consumed* or *in-app*.

## Layer 1 — Consumed OpenRegister abstractions

### Document queue / board → the deck leaf
The WOO document processing queue is **not** a bespoke table + kanban UI. It
consumes the OpenRegister **deck leaf** documented in the
`nextcloud-entity-relations` spec (`DeckCardService`, `openregister_deck_links`,
`nl.openregister.object.deck.*` events):

- A **disclosure batch** is represented as a **Deck board** (stacks =
  assessment stages: *Te beoordelen → Openbaar / Deels openbaar / Niet openbaar*).
- Each **document** is a **Deck card** linked to its OpenRegister
  `wooDocumentAssessment` object via
  `POST /api/objects/{register}/{schema}/{id}/deck`.
- Moving a card between stacks reflects/drives the assessment status on the linked
  object (the deck spec already supports "moving a card between stacks can trigger
  status changes on the object").
- The queue UI is the existing OpenRegister deck **widget on the assessment /
  batch object detail page** (ADR-019 integration registry + ADR-024 app
  manifest), not a hand-built OpenCatalogi table.
- Bulk assessment, progress summary, sort/filter/search are board/widget concerns
  delivered by the leaf; WOO only contributes the status vocabulary and the
  per-card assessment metadata stored on the linked object.

Graceful degradation when the Deck app is absent is the leaf's responsibility
(it returns `501 APP_NOT_AVAILABLE`).

### Disclosure workflow / state engine → approval-workflow + workflow-integration
The disclosure workflow is **not** a bespoke state machine. It is split across two
OpenRegister abstractions:

- **Human review / sign-off gate → OpenRegister `approval-workflow`.** Batch
  transitions that require a person (e.g. `ready_for_review → published`) are
  modelled as a role-gated approval chain (`/api/approval-chains`,
  `ApprovalStep` records). The reviewer role maps to a Nextcloud group; each
  decision is persisted to the workflow execution history (giving the legally
  required decision trail for free).
- **Automation rules → `workflow-integration` (flow / n8n).** Status-driven side
  effects — notifications when a batch is ready for review, deadline reminders
  (WOO 4+2-week besluit term), publish triggers — are configured as
  workflow-integration triggers on OpenRegister register events / schema hooks,
  not coded as bespoke listeners in OpenCatalogi.
- The batch-status field still lives on the WOO batch object, but the
  *transitions and their gating* are owned by the consumed abstractions.

### Object detail surfacing (ADR-024 / ADR-019)
The deck board widget and the approval-chain state are surfaced via the app
manifest as a widget/tab on the WOO batch (and document assessment) object detail
page, rather than a custom-built admin screen. OpenCatalogi contributes the
WOO-specific tabs only (weigeringsgronden, redaction review, inventarislijst).

## Layer 2 — WOO-specific domain logic (built in OpenCatalogi)

These have no OpenRegister leaf and remain in-app:

- **Weigeringsgronden** (WOO Art. 5.1/5.2 refusal grounds) data model, selection
  UI, and entity→ground redaction mapping.
- **Redaction metadata + immutable audit trail.** (Document processing —
  detection, anonymization, PDF — is delegated to Docudesk `anonymization` via
  API; OpenCatalogi stores the WOO redaction instructions, ground attribution,
  and the audit record.) The audit trail itself MAY reuse OpenRegister's
  immutable audit-trail abstraction for the assessment objects (ADR-022 audit
  abstraction) rather than a private events table.
- **Inventarislijst generation** (PDF/A + CSV) in the standard municipal format.
- WOO batch / document-assessment **schemas** (stored in OpenRegister registers).

## Layer 3 — Public reading room (built in OpenCatalogi)

The reading room is a **public CMS rendering surface**, not an integration leaf.
It reuses OpenCatalogi's existing Catalog / Publication / Listing infrastructure
and the public catalog website, SitemapService, and SearchService. WOO publication
metadata and the `woo_reading_room` catalog type are WOO-specific additions to
that existing infrastructure.

## What changed from the original intent (ADR-022 interception)

| Original plan | Re-pointed to |
|---|---|
| Bespoke WOO document queue/board + kanban UI | **deck leaf** (board=batch, card=document) |
| Bespoke batch state machine + review gates | **approval-workflow** (role-gated sign-off chain) |
| Bespoke notification/deadline listeners | **workflow-integration** (flow / n8n triggers) |
| Bespoke per-action audit events table | OpenRegister **audit-trail** abstraction (ADR-022) |
| Weigeringsgronden, redaction metadata, inventarislijst | **kept in-app** (WOO-specific, no leaf) |
| Public reading room | **kept in-app** (public CMS, not a leaf) |

## Open questions (unchanged, still WOO-domain)
1. Manual redaction region representation (Docudesk implementation detail).
2. Municipal inventarislijst template standard.
3. PLOOI national-platform publishing contract.
4. OpenCatalogi ↔ Docudesk redaction API contract.
