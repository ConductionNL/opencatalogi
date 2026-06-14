# Proposal: woo-transparency

## Summary
Implement WOO (Wet open overheid) transparency features in OpenCatalogi: document
inventory lists, exemption-grounds management, reading-room publication, and the
public document-disclosure workflow.

Per **hydra ADR-022** ("integrate, don't build" — apps consume OpenRegister
abstractions over local duplication), this change does **not** build a bespoke
document queue/board or a bespoke workflow/state engine. The parts that overlap
with existing OpenRegister integration leaves are re-pointed to consume those
leaves:

- The **document queue / board** (track documents to disclose, move them through
  assessment stages) consumes the OpenRegister **deck leaf**
  (`nextcloud-entity-relations` `DeckCardService`, `openregister_deck_links`,
  `nl.openregister.object.deck.*` events). Each disclosure batch is a Deck board;
  each document is a Deck card linked to its assessment object. This replaces a
  hand-rolled queue table + kanban UI.
- The **disclosure workflow / state engine** (assessment transitions, "ready for
  review" / "published" gates, deadline rules) consumes OpenRegister's
  **approval-workflow** abstraction (role-gated approval chains) for the
  human review/sign-off gate, and the **workflow-integration** leaf (flow / n8n,
  via schema hooks on register events) for automation rules (notifications,
  deadline reminders, status-driven side effects). This replaces a bespoke
  in-app workflow engine.

Genuinely WOO-specific logic stays in OpenCatalogi: the legal disclosure
categories and weigeringsgronden (WOO Art. 5.1/5.2) data model, redaction
metadata and audit trail, inventarislijst generation, and the **public
reading-room rendering** (a public CMS surface built on the existing
Catalog/Publication/Listing infrastructure — not a leaf).

## Motivation
The WOO requires Dutch government organizations to proactively publish government
information. OpenCatalogi needs to support the complete WOO disclosure workflow
from document inventory through publication — reusing the shared kanban (deck) and
workflow (flow/approval) abstractions instead of duplicating them (ADR-022).

## Scope
- WOO document queue / board — **consumes the OpenRegister deck leaf** (board per
  disclosure batch, card per document); WOO-specific assessment metadata lives on
  the linked OpenRegister assessment object.
- Disclosure workflow & state transitions — **consumes OpenRegister
  approval-workflow** (human sign-off chain) + **workflow-integration** (flow/n8n
  automation rules); no bespoke state engine.
- Exemption grounds (weigeringsgronden) management — WOO-specific, in-app.
- Redaction metadata + immutable audit trail — WOO-specific, in-app (document
  processing delegated to Docudesk `anonymization`).
- Inventarislijst generation — WOO-specific, in-app.
- Public reading-room interface — WOO-specific public CMS surface on existing
  Catalog/Publication/Listing infrastructure (not a leaf).
- Integration with Procest for case-driven disclosure.

## Out of scope (consumed, not built)
- Kanban/queue/board mechanics — owned by the deck leaf.
- Workflow rule engine + approval-chain mechanics — owned by
  workflow-integration (flow) + approval-workflow.

## References
- hydra ADR-022 — Apps consume OpenRegister abstractions.
- hydra ADR-019 / ADR-024 — Integration registry + app manifest (widget/tab on
  the relevant object detail page).
- OpenRegister `nextcloud-entity-relations` (deck leaf), `approval-workflow`,
  `workflow-integration` specs.
