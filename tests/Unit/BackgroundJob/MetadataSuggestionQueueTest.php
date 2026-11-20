<?php

/**
 * Unit tests for MetadataSuggestionQueue.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/woo-metadata-suggestions/specs/woo-metadata-suggestions/spec.md#requirement-missing-woo-metadata-is-proposed-from-data-the-instance-holds-req-wms-001
 */

declare(strict_types=1);

namespace Unit\BackgroundJob;

use OCA\OpenCatalogi\BackgroundJob\MetadataSuggestionQueue;
use OCA\OpenCatalogi\BackgroundJob\SuggestPublicationMetadata;
use OCA\OpenCatalogi\Contract\MetadataSuggestionQueueInterface;
use OCP\BackgroundJob\IJobList;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * The queue adds one SuggestPublicationMetadata job with the publication's register, schema and uuid.
 */
#[CoversClass(MetadataSuggestionQueue::class)]
final class MetadataSuggestionQueueTest extends TestCase {

	public function testAPublicationIsQueuedAsOneSuggestionJob(): void {
		$jobList = $this->createMock(IJobList::class);
		$jobList->expects($this->once())->method('add')->with(
			SuggestPublicationMetadata::class,
			['register' => '7', 'schema' => '12', 'id' => 'pub-1']
		);

		$queue = new MetadataSuggestionQueue($jobList);
		$this->assertInstanceOf(MetadataSuggestionQueueInterface::class, $queue);

		$queue->queue(register: '7', schema: '12', id: 'pub-1');
	}//end testAPublicationIsQueuedAsOneSuggestionJob()
}//end class
