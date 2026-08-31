# Tasks: woo-transparency

This change consumes OpenRegister leaves for the queue/board and workflow parts
(hydra ADR-022). Tasks are split into "consume a leaf" wiring vs WOO-specific
in-app build.

## Task 1: Implementation planning
- **Spec ref**: specs/woo-transparency/spec.md
- **Status**: done
- **Acceptance criteria**: Requirements from spec are decomposed into implementable tasks, respecting the consume-vs-build split below.

## Task 2: Consume the deck leaf for the WOO document queue/board
- **Spec ref**: specs/woo-transparency/spec.md — "WOO document queue (consumes the OpenRegister deck leaf)", "WOO API endpoints", "WOO frontend components"
- **Status**: done
- **Acceptance criteria**:
  - [x] Creating a WOO batch provisions/uses a Deck board via the deck leaf (`WooService::createBatch` → `DeckCardService::linkOrCreateCard`); each document becomes a linked Deck card in the "Te beoordelen" stack.
  - [x] Assessment changes delegate the card move to the leaf (`WooService::updateAssessment` → `moveCardToStack`); status lives on the assessment object + deck links (no parallel store).
  - [x] The queue UI is the deck-board widget surfaced on the `WooBatchDetail` object detail page via the app manifest (ADR-019 / ADR-024) — NO bespoke queue table.
  - [x] Graceful "Deck integration required for the WOO queue" handling when the Deck app is absent (`isDeckAvailable()` + `deckWarning`; surfaced as an `NcNoteCard` warning in the UI).

## Task 3: Consume approval-workflow for the publication review/sign-off gate
- **Spec ref**: specs/woo-transparency/spec.md — "Batch status transitions (gated by approval-workflow)"
- **Status**: done
- **Acceptance criteria**:
  - [x] No bespoke state machine: `WooService::markReadyForReview` only opens the gate (in_progress → ready_for_review, requires all documents assessed); `publishBatch` requires `ready_for_review` and refuses otherwise, leaving the `ready_for_review → published` authorization to the configured OpenRegister approval-workflow chain (`getPublishApprovalChain` / `woo_publish_approval_chain` config). The approval decision + trail live in the workflow execution history.
  - [~] Wiring the actual chain id into a running approval-workflow execution is a deploy-time configuration step (chain definition lives in OpenRegister); OpenCatalogi references it by id only. Code path + config key are in place.

## Task 4: Consume workflow-integration (flow / n8n) for notifications & deadlines
- **Spec ref**: specs/woo-transparency/spec.md — "Notification and communication (consumes workflow-integration / flow)"
- **Status**: deferred
- **Acceptance criteria**:
  - [~] Ready-for-review / published / deadline-approaching notifications are declared as workflow-integration triggers on the WOO schema's register events (`x-openregister-notifications` / flow hooks) at schema-provisioning time — NOT coded as bespoke in-app listeners or cron in OpenCatalogi. Per ADR-022 this is configuration on the consumed leaf, not app code; no bespoke listener/cron was added (verified: no new `IEventListener` / `BackgroundJob` for WOO). The concrete trigger JSON is authored alongside the WOO schema export (deploy artifact), so it is intentionally NOT a code deliverable in this change.

## Task 5: Build WOO-specific domain logic (in-app)
- **Spec ref**: specs/woo-transparency/spec.md — weigeringsgronden, redaction, audit trail, inventarislijst, schemas
- **Status**: done
- **Acceptance criteria**:
  - [x] Weigeringsgronden (WOO Art. 5.1/5.2) catalogue (`WooService::WEIGERINGSGRONDEN` + `getWeigeringsgronden` search) + selection UI (`WooRedactionView` per-redaction `NcSelect`) + entity→ground mapping (`buildRedactionInstructions`).
  - [x] Redaction coordination with Docudesk anonymization: selective entity selection + per-entity ground attribution + preview request, instructions sent to Docudesk (the redaction engine stays in Docudesk per ADR-022). Manual-region representation is a Docudesk implementation detail (design open question 1) — the instruction payload is extensible to carry it.
  - [x] Redaction/assessment audit immutability via OpenRegister's audit trail: every mutation goes through `saveObject` (no bespoke immutable table); the entity→ground→position payload is recorded on the assessment object.
  - [x] Inventarislijst generation: rows (`buildInventarislijst`, all categories), CSV (UTF-8 BOM, municipal column names) + archival HTML source for PDF/A (`renderInventarislijstHtml`; PDF/A conversion delegated to Docudesk).
  - [x] WOO batch + document-assessment data model implemented as OpenRegister objects on the configured WOO register/schemas (`woo_register` / `woo_batch_schema` / `woo_assessment_schema`).

## Task 6: Build the public reading room (in-app CMS surface)
- **Spec ref**: specs/woo-transparency/spec.md — "Reading room publication", "WOO catalog type"
- **Status**: done
- **Acceptance criteria**:
  - [x] Publish path (`WooService::publishBatch`) builds the reading-room publication on the existing Catalog/Publication infrastructure (PublicationService/CatalogiService injected), excludes niet_openbaar documents, includes openbaar + deels_openbaar (anonymized) as listings + besluit + inventarislijst.
  - [x] `woo_reading_room` catalog type + WOO publication metadata (wooDecisionDate, wooRequestReference, wooCategory, documentCount, publishedCount) + a permanent public reading-room URL on the catalog website. Sitemap/SearchService inclusion uses the existing SitemapService/SearchService over the published catalog (DIWOO categories already supported by SitemapService).
