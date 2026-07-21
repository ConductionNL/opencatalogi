# Tasks: woo-transparency

This change consumes OpenRegister leaves for the queue/board and workflow parts
(hydra ADR-022). Tasks are split into "consume a leaf" wiring vs WOO-specific
in-app build.

## Task 1: Implementation planning
- **Spec ref**: specs/woo-transparency/spec.md
- **Status**: todo
- **Acceptance criteria**: Requirements from spec are decomposed into implementable tasks, respecting the consume-vs-build split below.

## Task 2: Consume the deck leaf for the WOO document queue/board
- **Spec ref**: specs/woo-transparency/spec.md — "WOO document queue (consumes the OpenRegister deck leaf)", "WOO API endpoints", "WOO frontend components"
- **Status**: todo
- **Acceptance criteria**:
  - Creating a WOO batch provisions a Deck board (stacks = assessment statuses) via the deck leaf; each document becomes a linked Deck card (`POST /api/objects/{register}/{schema}/{id}/deck`).
  - Assessment changes move the linked Deck card between stacks; status stays in sync with the linked assessment object.
  - The queue UI is the deck leaf widget surfaced on the batch object detail page via the app manifest (ADR-019 / ADR-024) — NO bespoke queue table.
  - Graceful "Deck integration required" handling when the Deck app is absent.

## Task 3: Consume approval-workflow for the publication review/sign-off gate
- **Spec ref**: specs/woo-transparency/spec.md — "Batch status transitions (gated by approval-workflow)"
- **Status**: todo
- **Acceptance criteria**:
  - The `ready_for_review → published` transition is gated by an OpenRegister approval-workflow role-gated chain; decisions persist to the workflow execution history. NO bespoke state machine.

## Task 4: Consume workflow-integration (flow / n8n) for notifications & deadlines
- **Spec ref**: specs/woo-transparency/spec.md — "Notification and communication (consumes workflow-integration / flow)"
- **Status**: todo
- **Acceptance criteria**:
  - Ready-for-review, published, and deadline-approaching notifications are configured as workflow-integration triggers on register events/schema hooks. NO bespoke in-app listeners or cron.

## Task 5: Build WOO-specific domain logic (in-app)
- **Spec ref**: specs/woo-transparency/spec.md — weigeringsgronden, redaction, audit trail, inventarislijst, schemas
- **Status**: todo
- **Acceptance criteria**:
  - Weigeringsgronden (WOO Art. 5.1/5.2) data model + selection UI + entity→ground mapping.
  - Redaction coordination with Docudesk anonymization (selective entities, manual regions, preview) and redaction metadata.
  - Redaction audit immutability via the OpenRegister audit-trail abstraction (ADR-022), not a bespoke immutable table.
  - Inventarislijst generation (PDF/A + CSV).
  - WOO batch + document-assessment schemas in OpenRegister registers.

## Task 6: Build the public reading room (in-app CMS surface)
- **Spec ref**: specs/woo-transparency/spec.md — "Reading room publication", "WOO catalog type"
- **Status**: todo
- **Acceptance criteria**:
  - Reading room built on existing Catalog/Publication/Listing infrastructure + SitemapService + SearchService.
  - `woo_reading_room` catalog type, WOO publication metadata, public sharable URL. (Public CMS surface — not a leaf.)
