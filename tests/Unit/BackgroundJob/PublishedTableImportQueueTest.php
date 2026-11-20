<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace Unit\BackgroundJob;

use OCA\OpenCatalogi\BackgroundJob\PublishedTableImportJob;
use OCA\OpenCatalogi\BackgroundJob\PublishedTableImportQueue;
use OCP\BackgroundJob\IJobList;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use RuntimeException;

/**
 * The queue adds one PublishedTableImportJob per table and never throws (REQ-DFA-002).
 *
 * @covers \OCA\OpenCatalogi\BackgroundJob\PublishedTableImportQueue
 *
 * @spec openspec/changes/data-file-import-failure-alert/tasks.md#1.3
 */
class PublishedTableImportQueueTest extends TestCase {

	/**
	 * A table is queued with who started it.
	 *
	 * @return void
	 */
	public function testATableIsQueuedWithItsStarter(): void {
		$jobs = $this->createMock(originalClassName: IJobList::class);
		$jobs->expects($this->once())->method('add')->with(PublishedTableImportJob::class, ['table' => 't1', 'importedBy' => 'anna']);

		$this->assertTrue(condition: (new PublishedTableImportQueue(jobList: $jobs, logger: new NullLogger()))->queue(tableId: 't1', importedBy: 'anna'));
	}//end testATableIsQueuedWithItsStarter()

	/**
	 * No table id queues nothing; a job list that throws answers false.
	 *
	 * @return void
	 */
	public function testNothingOrAFailingJobListAnswersFalse(): void {
		$jobs = $this->createMock(originalClassName: IJobList::class);
		$jobs->expects($this->once())->method('add')->willThrowException(new RuntimeException('database gone'));
		$queue = new PublishedTableImportQueue(jobList: $jobs, logger: new NullLogger());

		$this->assertFalse(condition: $queue->queue(tableId: '', importedBy: 'anna'));
		$this->assertFalse(condition: $queue->queue(tableId: 't1', importedBy: 'anna'));
	}//end testNothingOrAFailingJobListAnswersFalse()
}//end class
