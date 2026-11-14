<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Tests for ObligationsRequestedEvent: a source nobody answered for stays
 * distinguishable from one that answered with nothing to publish, and an
 * answer is stored as a list.
 */

declare(strict_types=1);

namespace Unit\Event;

use OCA\OpenCatalogi\Event\ObligationsRequestedEvent;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for ObligationsRequestedEvent.
 *
 * @covers \OCA\OpenCatalogi\Event\ObligationsRequestedEvent
 */
class ObligationsRequestedEventTest extends TestCase {

	/**
	 * Until a listener answers, the event reports no answer at all.
	 *
	 * @return void
	 */
	public function testAnUnansweredSourceReportsNull(): void {
		$event = new ObligationsRequestedEvent('dossiq');

		$this->assertSame('dossiq', $event->getAppId());
		$this->assertNull($event->getObligations());

	}//end testAnUnansweredSourceReportsNull()

	/**
	 * An empty answer is an answer, not the unread state.
	 *
	 * @return void
	 */
	public function testAnEmptyAnswerIsNotUnread(): void {
		$event = new ObligationsRequestedEvent('decidiq');
		$event->setObligations([]);

		$this->assertSame([], $event->getObligations());

	}//end testAnEmptyAnswerIsNotUnread()

	/**
	 * Keyed answers are stored as a zero-based list, in their given order.
	 *
	 * @return void
	 */
	public function testTheAnswerIsReindexedAsAList(): void {
		$first  = ['title' => 'Besluit A', 'dueDate' => '2026-12-01', 'publishedAt' => '', 'reference' => 'a'];
		$second = ['title' => 'Besluit B', 'dueDate' => '', 'publishedAt' => '2026-11-01', 'reference' => 'b'];

		$event = new ObligationsRequestedEvent('dossiq');
		$event->setObligations(['x' => $first, 7 => $second]);

		$this->assertSame([0 => $first, 1 => $second], $event->getObligations());

	}//end testTheAnswerIsReindexedAsAList()

	/**
	 * A later answer replaces an earlier one.
	 *
	 * @return void
	 */
	public function testALaterAnswerReplacesTheEarlierOne(): void {
		$event = new ObligationsRequestedEvent('dossiq');
		$event->setObligations([['title' => 'Old', 'reference' => 'o']]);
		$event->setObligations([['title' => 'New', 'reference' => 'n']]);

		$this->assertSame([['title' => 'New', 'reference' => 'n']], $event->getObligations());

	}//end testALaterAnswerReplacesTheEarlierOne()
}//end class
