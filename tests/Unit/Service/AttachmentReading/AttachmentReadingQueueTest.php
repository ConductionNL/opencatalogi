<?php

/**
 * Tests for the attachment reading queue.
 *
 * @category Test
 * @package  OCA\OpenCatalogi\Tests
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * @link https://www.OpenCatalogi.nl
 * @spec openspec/changes/scanned-attachments-become-searchable/specs/search/spec.md#requirement-a-published-scan-is-read-by-ocr-so-search-finds-its-words-req-socr-001
 */

declare(strict_types=1);

namespace Unit\Service\AttachmentReading;

use OCA\OpenCatalogi\BackgroundJob\AttachmentReadingJob;
use OCA\OpenCatalogi\Service\AttachmentReading\AttachmentReadingQueue;
use OCP\BackgroundJob\IJobList;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Tests for AttachmentReadingQueue.
 *
 * @covers \OCA\OpenCatalogi\Service\AttachmentReading\AttachmentReadingQueue
 */
class AttachmentReadingQueueTest extends TestCase {

	public function testAPublishedFileQueuesTheReadingJob(): void {
		$jobs = $this->createMock(IJobList::class);
		$jobs->expects($this->once())->method('add')->with(
			AttachmentReadingJob::class,
			['fileId' => 42, 'publication' => 'pub-1', 'force' => false]
		);

		$this->assertTrue((new AttachmentReadingQueue($jobs, $this->createMock(LoggerInterface::class)))->queue(fileId: 42, publicationId: 'pub-1'));
	}//end testAPublishedFileQueuesTheReadingJob()

	public function testReadAgainQueuesWithForce(): void {
		$jobs = $this->createMock(IJobList::class);
		$jobs->expects($this->once())->method('add')->with(
			AttachmentReadingJob::class,
			['fileId' => 42, 'publication' => 'pub-1', 'force' => true]
		);

		(new AttachmentReadingQueue($jobs, $this->createMock(LoggerInterface::class)))->queueAgain(fileId: 42, publicationId: 'pub-1');
	}//end testReadAgainQueuesWithForce()

	public function testNothingIsQueuedWithoutAFileOrAPublication(): void {
		$jobs = $this->createMock(IJobList::class);
		$jobs->expects($this->never())->method('add');
		$queue = new AttachmentReadingQueue($jobs, $this->createMock(LoggerInterface::class));

		$this->assertFalse($queue->queue(fileId: 0, publicationId: 'pub-1'));
		$this->assertFalse($queue->queue(fileId: 5, publicationId: ''));
	}//end testNothingIsQueuedWithoutAFileOrAPublication()

	public function testAJobListFailureIsLoggedAndNeverReachesThePublish(): void {
		$jobs = $this->createMock(IJobList::class);
		$jobs->method('add')->willThrowException(new RuntimeException('database gone'));
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('warning')->with(
			'[AttachmentReadingQueue] the reading job could not be queued',
			['fileId' => 42, 'exception' => 'database gone']
		);

		$this->assertFalse((new AttachmentReadingQueue($jobs, $logger))->queue(fileId: 42, publicationId: 'pub-1'));
	}//end testAJobListFailureIsLoggedAndNeverReachesThePublish()
}//end class
