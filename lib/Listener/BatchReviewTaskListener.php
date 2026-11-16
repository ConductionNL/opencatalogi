<?php

/**
 * OpenCatalogi Batch Review Task Listener.
 *
 * A Woo batch is reviewed through OpenRegister's approval chain `batchReview`.
 * When the reviewer rejects that chain's task in OpenRegister, the batch goes
 * back to its author with the task's comment as the reason. An approval needs
 * nothing here: the chain itself advances the batch to `approved`.
 *
 * @category Listener
 * @package  OCA\OpenCatalogi\Listener
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
 *
 * @spec openspec/changes/woo-review-surface/specs/woo-transparency/spec.md#requirement-a-rejected-batch-or-document-goes-back-to-its-author-with-the-reason-req-wrv-002
 */

declare(strict_types=1);

namespace OCA\OpenCatalogi\Listener;

use OCA\OpenCatalogi\Contract\BatchReviewInterface;
use OCA\OpenRegister\Event\TaskTerminalEvent;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Turns a rejected `batchReview` task into the batch's `reject` transition.
 *
 * @spec openspec/changes/woo-review-surface/specs/woo-transparency/spec.md#requirement-a-rejected-batch-or-document-goes-back-to-its-author-with-the-reason-req-wrv-002
 *
 * @template-implements IEventListener<Event>
 */
class BatchReviewTaskListener implements IEventListener {

	/**
	 * The task outcomes that reject, as OpenRegister's TaskState names them.
	 *
	 * @var array<int, string>
	 */
	public const REJECTING_OUTCOMES = ['rejected', 'returned', 'declined', 'denied'];

	/**
	 * Constructor.
	 *
	 * @param BatchReviewInterface $review The batch review.
	 * @param LoggerInterface         $logger The log.
	 */
	public function __construct(
		private readonly BatchReviewInterface $review,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Reject the batch of a rejected review task, with the task's comment as the reason.
	 *
	 * A rejection without a comment leaves the batch in review: `reject`
	 * requires a reason, and the batch is not approved either way.
	 *
	 * @param Event $event The event.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/woo-review-surface/specs/woo-transparency/spec.md#requirement-a-rejected-batch-or-document-goes-back-to-its-author-with-the-reason-req-wrv-002
	 */
	public function handle(Event $event): void {
		if (($event instanceof TaskTerminalEvent) === false || $event->isCommitted() === false) {
			return;
		}

		$task = $event->getTask();
		if (in_array((string)$event->getOutcome(), self::REJECTING_OUTCOMES, true) === false) {
			return;
		}

		$batchId = (string)$task->getObjectUuid();
		if ($batchId === '' || $this->review->isBatchReviewSequence(sequenceUuid: (string)$task->getSequenceUuid()) === false) {
			return;
		}

		$reason = trim((string)$task->getComment());
		if ($reason === '') {
			$reason = trim((string)$task->getResultText());
		}

		if ($reason === '') {
			$this->logger->warning(
				'[BatchReviewTaskListener] review task of batch ' . $batchId . ' was rejected without a comment; the batch stays in review'
			);
			return;
		}

		try {
			$this->review->rejectBatch(batchId: $batchId, reason: $reason);
		} catch (Throwable $e) {
			$this->logger->error('[BatchReviewTaskListener] rejecting batch ' . $batchId . ' failed: ' . $e->getMessage());
		}
	}//end handle()
}//end class
