<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Tests for how DepublicationService reads the withdrawal rows of a stored
 * depublication: rows that are not rows, and acknowledgements that arrive
 * for a channel further down the list.
 */

declare(strict_types=1);

namespace Unit\Service\Publication;

use DateTimeImmutable;
use OCA\OpenCatalogi\Service\Publication\DepublicationService;
use OCA\OpenCatalogi\Service\Publication\NationalIndexService;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Unit tests for the withdrawal rows of a depublication.
 *
 * @covers \OCA\OpenCatalogi\Service\Publication\DepublicationService
 */
class DepublicationWithdrawalRowsTest extends TestCase {

	/**
	 * The service; its index is never asked in these tests.
	 *
	 * @return DepublicationService The service.
	 */
	private function service(): DepublicationService {
		$index = $this->getMockBuilder(NationalIndexService::class)->disableOriginalConstructor()->onlyMethods(['withdraw'])->getMock();
		$index->expects($this->never())->method('withdraw');

		return new DepublicationService($index, $this->createMock(LoggerInterface::class));
	}//end service()

	/**
	 * A withdrawal row that is not an array is skipped, not counted as outstanding.
	 *
	 * @return void
	 */
	public function testARowThatIsNotARowIsSkipped(): void {
		$depublication = [
			'withdrawals' => [
				'garbage',
				['channel' => 'plooi', 'acknowledgedAt' => null],
				42,
				['channel' => 'national-woo-index', 'acknowledgedAt' => '2026-09-19T09:00:00+00:00'],
			],
		];

		$this->assertSame(['plooi'], $this->service()->outstandingChannels(depublication: $depublication));
		$this->assertFalse($this->service()->isComplete(depublication: $depublication));

	}//end testARowThatIsNotARowIsSkipped()

	/**
	 * A row without a channel name is outstanding but never named.
	 *
	 * @return void
	 */
	public function testARowWithoutAChannelIsNotNamed(): void {
		$depublication = ['withdrawals' => [['acknowledgedAt' => '  ']]];

		$this->assertSame([], $this->service()->outstandingChannels(depublication: $depublication));
		$this->assertTrue($this->service()->isComplete(depublication: $depublication));

	}//end testARowWithoutAChannelIsNotNamed()

	/**
	 * An acknowledgement closes only its own channel's row, wherever it sits.
	 *
	 * @return void
	 */
	public function testTheAcknowledgementLandsOnItsOwnChannel(): void {
		$depublication = [
			'withdrawals' => [
				['channel' => 'plooi', 'acknowledgedAt' => null],
				['channel' => 'national-woo-index', 'acknowledgedAt' => null],
			],
		];

		$updated = $this->service()->recordAcknowledgement(
			depublication: $depublication,
			channel: 'national-woo-index',
			answer: 'verwijderd',
			now: new DateTimeImmutable('2026-09-19T11:30:00+02:00')
		);

		$this->assertNull($updated['withdrawals'][0]['acknowledgedAt']);
		$this->assertArrayNotHasKey('answer', $updated['withdrawals'][0]);
		$this->assertSame('2026-09-19T11:30:00+02:00', $updated['withdrawals'][1]['acknowledgedAt']);
		$this->assertSame('verwijderd', $updated['withdrawals'][1]['answer']);
		$this->assertSame(['plooi'], $this->service()->outstandingChannels(depublication: $updated));

	}//end testTheAcknowledgementLandsOnItsOwnChannel()

	/**
	 * An acknowledgement for a channel that was never asked changes nothing.
	 *
	 * @return void
	 */
	public function testAnAcknowledgementForAnUnknownChannelChangesNothing(): void {
		$depublication = ['withdrawals' => [['channel' => 'plooi', 'acknowledgedAt' => null]]];

		$updated = $this->service()->recordAcknowledgement(
			depublication: $depublication,
			channel: 'elsewhere',
			answer: 'ok',
			now: new DateTimeImmutable('2026-09-19T09:00:00+00:00')
		);

		$this->assertSame($depublication['withdrawals'], $updated['withdrawals']);

	}//end testAnAcknowledgementForAnUnknownChannelChangesNothing()
}//end class
