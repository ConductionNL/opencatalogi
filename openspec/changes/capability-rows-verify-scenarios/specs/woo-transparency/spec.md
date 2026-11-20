# woo-transparency

## ADDED Requirements

### Requirement: Opening a batch gathers the documents of the Woo request (REQ-CVS-004)

An officer MUST be able to open a disclosure batch from the Woo batches page for
one Woo request, named by its case reference. Opening it MUST gather the
documents delivered for that case into the batch, each as one assessment with
status to assess, and the batch MUST show how many documents it holds. A
batch MUST NOT be created for a case reference that already has an open batch.

#### Scenario: A batch starts with the case's documents
- **GIVEN** Woo case WOO-2026-031 with 28 delivered documents
- **WHEN** the officer chooses Add on the Woo batches page and enters WOO-2026-031
- **THEN** a batch for WOO-2026-031 exists with 28 documents to assess
- **AND** the batches list shows 28 documents and 0% assessed for it

#### Scenario: A second batch for the same request
- **GIVEN** an open batch for WOO-2026-031
- **WHEN** the officer adds another batch for WOO-2026-031
- **THEN** the request is refused and names the open batch
