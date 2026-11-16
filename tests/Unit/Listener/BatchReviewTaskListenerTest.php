<?php

/**
 * BatchReviewTaskListenerTest
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Test
 * @package  OCA\OpenCatalogi\Tests\Unit\Listener
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenCatalogi.nl
 *
 * @spec openspec/changes/woo-review-surface/tasks.md#task-1.3
 */

declare(strict_types=1);

namespace OCA\OpenCatalogi\Tests\Unit\Listener;

use OCA\OpenCatalogi\Contract\WooBatchReviewInterface;
use OCA\OpenCatalogi\Listener\BatchReviewTaskListener;
use OCA\OpenRegister\Db\Task;
use OCA\OpenRegister\Event\TaskTerminalEvent;
use OCP\EventDispatcher\Event;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * A rejected `batchReview` task in OpenRegister rejects its batch with the task's comment.
 *
 * Built on the real TaskTerminalEvent and Task (OpenRegister's, or the stub that mirrors them).
 *
 * @covers \OCA\OpenCatalogi\Listener\BatchReviewTaskListener
 */
final class BatchReviewTaskListenerTest extends TestCase {

	private WooBatchReviewInterface&MockObject $review;
	private LoggerInterface&MockObject $logger;
	private BatchReviewTaskListener $listener;

	protected function setUp(): void {
		$this->review = $this->createMock(WooBatchReviewInterface::class);
		$this->logger = $this->createMock(LoggerInterface::class);
		$this->listener = new BatchReviewTaskListener($this->review, $this->logger);

	}//end setUp()

	/**
	 * A terminal task as OpenRegister persists it.
	 *
	 * @param string      $outcome The outcome.
	 * @param string|null $comment The decider's comment.
	 *
	 * @return Task
	 */
	private function task(string $outcome, ?string $comment): Task {
		$task = new Task();
		$task->setUuid('task-1');
		$task->setState('completed');
		$task->setOutcome($outcome);
		$task->setObjectUuid('batch-1');
		$task->setSequenceUuid('seq-1');
		$task->setComment($comment);

		return $task;

	}//end task()

	/**
	 * The scenario: the reviewer rejects the task with a comment; the batch runs `reject` with it.
	 *
	 * @return void
	 */
	public function testARejectedReviewTaskRejectsTheBatchWithItsComment(): void {
		$this->review->expects($this->once())->method('isBatchReviewSequence')->with('seq-1')->willReturn(true);
		$this->review->expects($this->once())
			->method('rejectBatch')
			->with('batch-1', 'Bijlage 3 bevat nog een BSN')
			->willReturn(['status' => 'in_progress']);

		$this->listener->handle(new TaskTerminalEvent($this->task('rejected', '  Bijlage 3 bevat nog een BSN ')));

	}//end testARejectedReviewTaskRejectsTheBatchWithItsComment()

	/**
	 * An approved task moves nothing here: the chain's own advance runs `approve`,
	 * behind the guard on the previews.
	 *
	 * @return void
	 */
	public function testAnApprovedTaskLeavesTheApprovalToTheChain(): void {
		$this->review->expects($this->never())->method('rejectBatch');
		$this->review->expects($this->never())->method('approveBatch');

		$this->listener->handle(new TaskTerminalEvent($this->task('approved', 'In orde')));

	}//end testAnApprovedTaskLeavesTheApprovalToTheChain()

	/**
	 * A rejected task of another chain is not a batch review.
	 *
	 * @return void
	 */
	public function testATaskOfAnotherChainIsIgnored(): void {
		$this->review->method('isBatchReviewSequence')->willReturn(false);
		$this->review->expects($this->never())->method('rejectBatch');

		$this->listener->handle(new TaskTerminalEvent($this->task('rejected', 'Nee')));

	}//end testATaskOfAnotherChainIsIgnored()

	/**
	 * Without a comment the reject transition has no reason: the batch stays in review, and the log says so.
	 *
	 * @return void
	 */
	public function testARejectionWithoutACommentLeavesTheBatchInReview(): void {
		$this->review->method('isBatchReviewSequence')->willReturn(true);
		$this->review->expects($this->never())->method('rejectBatch');
		$this->logger->expects($this->once())->method('warning')->with($this->stringContains('batch-1'));

		$this->listener->handle(new TaskTerminalEvent($this->task('rejected', '   ')));

	}//end testARejectionWithoutACommentLeavesTheBatchInReview()

	/**
	 * An event dispatched before its transaction committed is not acted on.
	 *
	 * @return void
	 */
	public function testAnUncommittedTaskIsIgnored(): void {
		$this->review->expects($this->never())->method('isBatchReviewSequence');
		$this->review->expects($this->never())->method('rejectBatch');

		$this->listener->handle(new TaskTerminalEvent($this->task('rejected', 'Nee'), false));
		$this->listener->handle(new Event());

	}//end testAnUncommittedTaskIsIgnored()

	/**
	 * A refused transition is logged, not thrown into OpenRegister's task flow.
	 *
	 * @return void
	 */
	public function testARefusedRejectionIsLogged(): void {
		$this->review->method('isBatchReviewSequence')->willReturn(true);
		$this->review->method('rejectBatch')->willThrowException(new RuntimeException('not in review'));
		$this->logger->expects($this->once())->method('error')->with($this->stringContains('not in review'));

		$this->listener->handle(new TaskTerminalEvent($this->task('declined', 'Nee')));

	}//end testARefusedRejectionIsLogged()
}//end class
