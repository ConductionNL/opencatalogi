<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace Unit\Service;

use InvalidArgumentException;
use OCA\OpenCatalogi\Service\AppliedFilters;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Unit tests for the applied-filters collector (REQ-ASF-002).
 *
 * @covers \OCA\OpenCatalogi\Service\AppliedFilters
 */
class AppliedFiltersTest extends TestCase {

	/**
	 * Each name is answered once, with its last value, in the order first recorded.
	 *
	 * @return void
	 */
	public function testRecordedFiltersAreReturnedOnce(): void {
		$filters = new AppliedFilters();
		$filters->record(name: 'publicOnly', value: true, reason: 'first');
		$filters->record(name: 'catalogScope', value: ['schemas' => ['a'], 'registers' => ['r']], reason: 'scope');
		$filters->record(name: 'publicOnly', value: true, reason: 'second');

		$this->assertSame(
			[
				['name' => 'publicOnly', 'value' => true, 'reason' => 'second'],
				['name' => 'catalogScope', 'value' => ['schemas' => ['a'], 'registers' => ['r']], 'reason' => 'scope'],
			],
			$filters->all()
		);
	}//end testRecordedFiltersAreReturnedOnce()

	/**
	 * A name outside the stable list is refused, so a typo cannot reach the API.
	 *
	 * @return void
	 */
	public function testANameOutsideTheListIsRefused(): void {
		$filters = new AppliedFilters();

		try {
			$filters->record(name: 'unlisted', value: true, reason: 'not built yet');
			$this->fail('An unknown name must be refused.');
		} catch (InvalidArgumentException $e) {
			$this->assertSame('Unknown applied filter name: unlisted', $e->getMessage());
		}

		$this->assertSame([], $filters->all());
	}//end testANameOutsideTheListIsRefused()

	/**
	 * Nothing recorded answers an empty list.
	 *
	 * @return void
	 */
	public function testNothingRecordedIsAnEmptyList(): void {
		$this->assertSame([], (new AppliedFilters())->all());
		$this->assertSame([], AppliedFilters::forResponse(appliedFilters: new AppliedFilters()));
	}//end testNothingRecordedIsAnEmptyList()

	/**
	 * A counting filter starts at one and adds one per call, keeping its latest reason.
	 *
	 * @return void
	 */
	public function testIncrementCountsFromOne(): void {
		$filters = new AppliedFilters();
		$filters->increment(name: 'archivedExcluded', reason: 'r1');
		$filters->increment(name: 'archivedExcluded', reason: 'r2');

		$this->assertSame([['name' => 'archivedExcluded', 'value' => 2, 'reason' => 'r2']], $filters->all());
	}//end testIncrementCountsFromOne()

	/**
	 * A count over a non-integer value restarts at one, and an unknown name is refused.
	 *
	 * @return void
	 */
	public function testIncrementRestartsOverANonCountAndRefusesAnUnknownName(): void {
		$filters = new AppliedFilters();
		$filters->record(name: 'archivedExcluded', value: 'many', reason: 'r');
		$filters->increment(name: 'archivedExcluded', reason: 'r');
		$this->assertSame(1, $filters->all()[0]['value']);

		$this->expectException(InvalidArgumentException::class);
		$filters->increment(name: 'nope', reason: 'r');
	}//end testIncrementRestartsOverANonCountAndRefusesAnUnknownName()

	/**
	 * Without a collector, or with one that fails, a response says the filters
	 * are unknown rather than claiming none applied.
	 *
	 * @return void
	 */
	public function testAMissingOrFailingCollectorAnswersUnknown(): void {
		$unknown = [['name' => 'unknown', 'value' => null, 'reason' => 'not recorded']];
		$failing = new class extends AppliedFilters {
			public function all(): array {
				throw new RuntimeException('broken');
			}
		};

		$this->assertSame($unknown, AppliedFilters::forResponse(appliedFilters: null));
		$this->assertSame($unknown, AppliedFilters::forResponse(appliedFilters: $failing));
	}//end testAMissingOrFailingCollectorAnswersUnknown()

	/**
	 * A working collector's entries pass through unchanged.
	 *
	 * @return void
	 */
	public function testAWorkingCollectorPassesItsEntries(): void {
		$filters = new AppliedFilters();
		$filters->record(name: 'callerScopeIgnored', value: ['_schemas'], reason: 'r');

		$this->assertSame($filters->all(), AppliedFilters::forResponse(appliedFilters: $filters));
	}//end testAWorkingCollectorPassesItsEntries()

}//end class
