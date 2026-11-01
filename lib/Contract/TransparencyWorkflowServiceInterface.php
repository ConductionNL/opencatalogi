<?php

/**
 * Contract of WooService, for callers in other components.
 *
 * @category Contract
 * @package  OCA\OpenCatalogi\Contract
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenCatalogi.nl
 */

declare(strict_types=1);

namespace OCA\OpenCatalogi\Contract;

use RuntimeException;

/**
 * Contract of WooService, for callers in other components.
 *
 * Callers outside Service/ depend on this interface instead of on the class.
 */
interface TransparencyWorkflowServiceInterface {
	/**
	 * The WOO Art. 5.1/5.2 weigeringsgronden (refusal grounds) catalogue. Each
	 * entry is the article reference (stored value) mapped to a short Dutch
	 * description. Absolute grounds (5.1) and relative grounds (5.2).
	 *
	 * @var array<string, string>
	 */
	public const WEIGERINGSGRONDEN = [
		'5.1.1.a' => 'Eenheid van de Kroon',
		'5.1.1.b' => 'Veiligheid van de Staat',
		'5.1.1.c' => 'Vertrouwelijk verstrekte bedrijfs- en fabricagegegevens',
		'5.1.1.d' => 'Bijzondere persoonsgegevens en strafrechtelijke gegevens',
		'5.1.2.a' => 'Betrekkingen van Nederland met andere staten en internationale organisaties',
		'5.1.2.b' => 'Economische of financiele belangen van de Staat',
		'5.1.2.c' => 'Opsporing en vervolging van strafbare feiten',
		'5.1.2.d' => 'Inspectie, controle en toezicht door bestuursorganen',
		'5.1.2.e' => 'Eerbiediging van de persoonlijke levenssfeer',
		'5.1.2.f' => 'Bescherming van het milieu',
		'5.1.2.g' => 'Beveiliging van personen en bedrijven en het voorkomen van sabotage',
		'5.1.2.h' => 'Het goed functioneren van de Staat en andere publiekrechtelijke lichamen',
		'5.1.2.i' => 'Het belang van de geadresseerde om als eerste kennis te kunnen nemen',
		'5.2.a' => 'Persoonlijke beleidsopvattingen in documenten voor intern beraad',
		'5.2.e' => 'Persoonlijke beleidsopvattingen',
	];

	/**
	 * Resolve the OpenRegister ObjectService from the container.
	 *
	 * @return object|null The ObjectService, or null when OpenRegister is unavailable.
	 *
	 * @spec exclude pure framework plumbing — resolves the consumed OR ObjectService.
	 */
	public function getObjectService(): ?object;

	/**
	 * Resolve the OpenRegister DeckCardService (the deck leaf) from the container.
	 *
	 * @return object|null The DeckCardService, or null when the leaf is unavailable.
	 *
	 * @spec exclude pure framework plumbing — resolves the consumed OR deck leaf.
	 */
	public function getDeckCardService(): ?object;

	/**
	 * Resolve the OpenRegister TaskSequenceMapper: the read surface for
	 * approval-chain outcomes. OpenCatalogi only reads the recorded outcome
	 * (ADR-022); provisioning, decisions and the audit trail are OpenRegister's.
	 *
	 * @return object|null The TaskSequenceMapper, or null when unavailable.
	 *
	 * @spec exclude pure framework plumbing — resolves the consumed OR approval-outcome store.
	 */
	public function getTaskSequenceMapper(): ?object;

	/**
	 * The configured WOO register id/uuid (shared by batch + assessment objects).
	 *
	 * @return string|null The register id/uuid, or null when unconfigured.
	 *
	 * @spec exclude pure config plumbing — reads the configured WOO register id.
	 */
	public function getRegister(): ?string;

	/**
	 * The configured WOO batch schema id/uuid.
	 *
	 * @return string|null The schema id/uuid, or null when unconfigured.
	 *
	 * @spec exclude pure config plumbing — reads the configured WOO batch schema id.
	 */
	public function getBatchSchema(): ?string;

	/**
	 * The configured WOO document-assessment schema id/uuid.
	 *
	 * @return string|null The schema id/uuid, or null when unconfigured.
	 *
	 * @spec exclude pure config plumbing — reads the configured WOO assessment schema id.
	 */
	public function getAssessmentSchema(): ?string;

	/**
	 * Return the WOO weigeringsgronden catalogue, optionally filtered by a search
	 * term (matched against article reference and description, case-insensitive).
	 *
	 * @param string|null $search Optional filter term.
	 *
	 * @return array<int, array{article: string, description: string}> The grounds.
	 *
	 * @spec openspec/specs/woo-transparency/spec.md#requirement-weigeringsgronden-refusal-grounds
	 */
	public function getWeigeringsgronden(?string $search = null): array;

	/**
	 * Create a WOO disclosure batch and provision its Deck board + cards.
	 *
	 * The batch object is stored through OpenRegister (ADR-022 audit for free); a
	 * Deck board is provisioned via the deck leaf and one card is created+linked
	 * per document. Each document also becomes a document-assessment object in the
	 * "te_beoordelen" status. NO bespoke queue table is created.
	 *
	 * @param string $caseReference The Procest WOO case reference.
	 * @param array<int, array<string,mixed>> $documents The collected documents.
	 * @param int|null $boardId Existing Deck board id, when re-using one.
	 *
	 * @return array<string, mixed> The created batch (incl. deck board reference + assessments).
	 *
	 * @throws RuntimeException When OpenRegister or required configuration is unavailable.
	 *
	 * @spec openspec/specs/woo-transparency/spec.md#requirement-woo-document-queue-consumes-the-openregister-deck-leaf
	 */
	public function createBatch(string $caseReference, array $documents, ?int $boardId = null): array;

	/**
	 * Whether the consumed deck leaf reports the Deck app available at runtime.
	 *
	 * When false, the queue/board cannot be provided (ADR-022 — no bespoke
	 * fallback); callers surface a clear "Deck integration required" message.
	 *
	 * @return bool True when the Deck app is enabled.
	 *
	 * @spec openspec/specs/woo-transparency/spec.md#requirement-woo-document-queue-consumes-the-openregister-deck-leaf
	 */
	public function isDeckAvailable(): bool;

	/**
	 * Update a document's assessment and move its linked Deck card to the matching
	 * stack via the deck leaf. NO parallel queue-state store is maintained — the
	 * status lives on the assessment object and the leaf's deck links.
	 *
	 * @param string $assessmentId The document-assessment object uuid.
	 * @param string $assessment The new assessment enum value.
	 * @param array<int,string> $weigeringsgronden Selected grounds (required for niet_openbaar).
	 * @param string|null $batchId The batch the document belongs to (whose creator owns relative references).
	 *
	 * @return array<string, mixed> The updated assessment object.
	 *
	 * @throws RuntimeException When inputs are invalid or OpenRegister is unavailable.
	 *
	 * @spec openspec/specs/woo-transparency/spec.md#requirement-woo-document-queue-consumes-the-openregister-deck-leaf
	 * @spec openspec/changes/woo-redaction-pipeline/specs/woo-transparency/spec.md#requirement-a-partly-public-document-is-published-only-as-a-verified-redacted-version-req-wrp-001
	 */
	public function updateAssessment(string $assessmentId, string $assessment, array $weigeringsgronden = [], ?string $batchId = null): array;

	/**
	 * Return a batch with a derived per-status document summary (progress).
	 *
	 * @param string $batchId The batch object uuid.
	 *
	 * @return array<string, mixed> The batch with a documentSummary block.
	 *
	 * @throws RuntimeException When OpenRegister is unavailable or the batch is missing.
	 *
	 * @spec openspec/specs/woo-transparency/spec.md#requirement-woo-api-endpoints
	 * @spec openspec/changes/woo-redaction-pipeline/specs/woo-transparency/spec.md#requirement-the-officer-sees-why-a-partly-public-document-cannot-be-published-req-wrp-002
	 */
	public function getBatch(string $batchId): array;

	/**
	 * Generate inventarislijst rows for a batch (all documents, every category).
	 *
	 * @param string $batchId The batch object uuid.
	 *
	 * @return array<int, array<string, string>> The inventory rows.
	 *
	 * @throws RuntimeException When the batch cannot be loaded.
	 *
	 * @spec openspec/specs/woo-transparency/spec.md#requirement-inventarislijst-generation
	 */
	public function buildInventarislijst(string $batchId): array;

	/**
	 * Render the inventarislijst as CSV (UTF-8 with BOM; municipal column names).
	 *
	 * @param array<int, array<string, string>> $rows The inventory rows.
	 *
	 * @return string The CSV payload.
	 *
	 * @spec openspec/specs/woo-transparency/spec.md#requirement-inventarislijst-generation
	 */
	public function renderInventarislijstCsv(array $rows): string;

	/**
	 * Render the inventarislijst as a minimal, archival-oriented HTML document
	 * (the print/PDF source). Conversion to PDF/A is delegated to Docudesk;
	 * OpenCatalogi owns only the WOO-standard layout + header/footer.
	 *
	 * @param string $batchId The batch uuid (for the header).
	 * @param array<int, array<string, string>> $rows The inventory rows.
	 *
	 * @return string The HTML payload.
	 *
	 * @spec openspec/specs/woo-transparency/spec.md#requirement-inventarislijst-generation
	 */
	public function renderInventarislijstHtml(string $batchId, array $rows): string;

	/**
	 * Whether a batch is eligible to move to "ready_for_review": every document
	 * must be assessed (none left in "te_beoordelen").
	 *
	 * @param string $batchId The batch uuid.
	 *
	 * @return bool True when all documents are assessed.
	 *
	 * @spec openspec/specs/woo-transparency/spec.md#requirement-woo-batch-data-model
	 */
	public function canMarkReadyForReview(string $batchId): bool;

	/**
	 * Move a batch to "ready_for_review". The "ready_for_review -> published"
	 * transition itself is NOT performed here: it is gated by the configured
	 * OpenRegister approval-workflow chain (ADR-022). This method only opens the
	 * gate by flipping the batch into the reviewable state once assessments are
	 * complete.
	 *
	 * @param string $batchId The batch uuid.
	 *
	 * @return array<string, mixed> The updated batch.
	 *
	 * @throws RuntimeException When not all documents are assessed.
	 *
	 * @spec openspec/specs/woo-transparency/spec.md#requirement-woo-batch-data-model
	 */
	public function markReadyForReview(string $batchId): array;

	/**
	 * The approval-chain id that gates the publication transition. The chain is an
	 * OpenRegister approval-workflow construct; OpenCatalogi only references it.
	 * Evaluated by {@see assertPublishApproved()} on every publish.
	 *
	 * @return string|null The configured chain id, or null when unconfigured.
	 *
	 * @spec openspec/specs/woo-transparency/spec.md#requirement-woo-batch-data-model
	 */
	public function getPublishApprovalChain(): ?string;

	/**
	 * Publish a completed WOO batch to a public reading room.
	 *
	 * The reading room is built on the existing Catalog/Publication infrastructure
	 * (ADR-022 — not a bespoke CMS). Only openbaar + deels_openbaar (anonymized)
	 * documents are published; niet_openbaar documents are excluded. The
	 * ready_for_review -> published transition is gated by the configured
	 * approval-workflow chain: the batch MUST be in "ready_for_review" AND the
	 * chain MUST have recorded a completed approval for this batch (evaluated
	 * fail-closed by {@see assertPublishApproved()}).
	 *
	 * @param string $batchId The batch uuid.
	 *
	 * @return array<string, mixed> The publication + the public reading-room URL.
	 *
	 * @throws RuntimeException When the batch is not ready or OpenRegister is unavailable.
	 *
	 * @spec openspec/specs/woo-transparency/spec.md#requirement-reading-room-publication
	 * @spec openspec/changes/woo-batch-creates-publications/specs/woo-transparency/spec.md#requirement-publishing-a-woo-batch-creates-a-public-publication-with-its-documents-attached-req-wbp-001
	 */
	public function publishBatch(string $batchId): array;
}//end interface
