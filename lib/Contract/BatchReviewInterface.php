<?php

/**
 * Contract of the Woo batch review, for callers in other components.
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

/**
 * The review of a Woo publication batch: what the reviewer sees, what they
 * opened, and the approve, reject and send-back moves.
 *
 * Callers outside Service/ depend on this interface instead of on the class.
 *
 * @spec openspec/changes/woo-review-surface/specs/woo-transparency/spec.md
 */
interface BatchReviewInterface {
	/**
	 * Whether an OpenRegister task sequence is the review chain of a Woo batch.
	 *
	 * @param string $sequenceUuid The sequence uuid a task carries.
	 *
	 * @return boolean False when the sequence cannot be read.
	 *
	 * @spec openspec/changes/woo-review-surface/specs/woo-transparency/spec.md#requirement-a-rejected-batch-or-document-goes-back-to-its-author-with-the-reason-req-wrv-002
	 */
	public function isBatchReviewSequence(string $sequenceUuid): bool;

	/**
	 * Run the batch's `reject` transition with the reviewer's reason.
	 *
	 * @param string $batchId The batch uuid.
	 * @param string $reason  Why the batch goes back.
	 *
	 * @return array<string, mixed> The batch after the rejection.
	 *
	 * @throws \RuntimeException When the reason is empty or the engine refuses.
	 *
	 * @spec openspec/changes/woo-review-surface/specs/woo-transparency/spec.md#requirement-a-rejected-batch-or-document-goes-back-to-its-author-with-the-reason-req-wrv-002
	 */
	public function rejectBatch(string $batchId, string $reason): array;

	/**
	 * Run the batch's `approve` transition for the signed-in reviewer.
	 *
	 * @param string $batchId The batch uuid.
	 *
	 * @return array<string, mixed> The batch after the transition.
	 *
	 * @throws \RuntimeException Naming the documents whose public view was not opened, or what the engine refused.
	 *
	 * @spec openspec/changes/woo-review-surface/specs/woo-transparency/spec.md#requirement-the-reviewer-sees-each-document-as-the-public-will-req-wrv-001
	 */
	public function approveBatch(string $batchId): array;

	/**
	 * Each document of a batch as the public will see it.
	 *
	 * @param string $batchId The batch uuid.
	 *
	 * @return array<string, mixed> The batch id and status, the documents and what the reviewer has not opened yet.
	 *
	 * @spec openspec/changes/woo-review-surface/specs/woo-transparency/spec.md#requirement-the-reviewer-sees-each-document-as-the-public-will-req-wrv-001
	 */
	public function publicPreview(string $batchId): array;

	/**
	 * Record that the signed-in reviewer opened the public view of one document.
	 *
	 * @param string $batchId    The batch uuid.
	 * @param string $documentId The assessment uuid of the document.
	 *
	 * @return array<string, mixed> The documents this reviewer opened and the ones still unseen.
	 *
	 * @spec openspec/changes/woo-review-surface/specs/woo-transparency/spec.md#requirement-the-reviewer-sees-each-document-as-the-public-will-req-wrv-001
	 */
	public function markPreviewSeen(string $batchId, string $documentId): array;

	/**
	 * The published documents of a batch whose public view a user has not opened.
	 *
	 * @param array<string, mixed> $batch  The batch.
	 * @param string               $userId The reviewer.
	 *
	 * @return array<int, array{documentId: string, title: string}> Empty when every one was opened.
	 *
	 * @spec openspec/changes/woo-review-surface/specs/woo-transparency/spec.md#requirement-the-reviewer-sees-each-document-as-the-public-will-req-wrv-001
	 */
	public function unseenPreviews(array $batch, string $userId): array;

	/**
	 * Send one document of a batch in review back to assessment, with the reason.
	 *
	 * @param string $batchId      The batch uuid.
	 * @param string $assessmentId The assessment uuid.
	 * @param string $reason       Why it goes back.
	 *
	 * @return array<string, mixed> The assessment after the send-back.
	 *
	 * @throws \RuntimeException When the reason is empty, the document is not the batch's, or the batch is not in review.
	 *
	 * @spec openspec/changes/woo-review-surface/specs/woo-transparency/spec.md#requirement-a-rejected-batch-or-document-goes-back-to-its-author-with-the-reason-req-wrv-002
	 */
	public function sendBack(string $batchId, string $assessmentId, string $reason): array;
}//end interface
