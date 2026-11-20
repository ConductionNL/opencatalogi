<?php

/**
 * Unit tests for TermRoll::roll(): a date that is already given, rolled to the
 * next working day through the term engine.
 *
 * @category Test
 * @package  OCA\OpenCatalogi\Tests\Unit\Service\Publication
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://www.OpenCatalogi.nl
 */

declare(strict_types=1);

namespace Unit\Service\Publication;

require_once __DIR__ . '/TermRollFixture.php';

use DateTimeImmutable;
use OCA\OpenCatalogi\Service\Publication\TermRollUnavailableException;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\OpenCatalogi\Service\Publication\TermRoll
 */
class TermRollTest extends TestCase {
	use TermRollFixture;

	/**
	 * A given Saturday lands on the Monday, and says where it was and why.
	 *
	 * @return void
	 */
	public function testAGivenDateOnASaturdayRollsToMonday(): void {
		$end = $this->termRoll()->roll(at: new DateTimeImmutable('2026-12-12T17:00:00+01:00'));

		$this->assertSame('2026-12-14T17:00:00+01:00', $end['at']->format(DATE_ATOM));
		$this->assertSame('2026-12-12T17:00:00+01:00', $end['unrolledAt']->format(DATE_ATOM));
		$this->assertSame('weekend', $end['rolledBy']);

	}//end testAGivenDateOnASaturdayRollsToMonday()

	/**
	 * A working day stays where it is, with nothing to explain.
	 *
	 * @return void
	 */
	public function testAWorkingDayIsUnchanged(): void {
		$end = $this->termRoll()->roll(at: new DateTimeImmutable('2026-12-15T09:30:00+00:00'));

		$this->assertSame('2026-12-15T09:30:00+00:00', $end['at']->format(DATE_ATOM));
		$this->assertNull($end['unrolledAt']);
		$this->assertNull($end['rolledBy']);

	}//end testAWorkingDayIsUnchanged()

	/**
	 * A given Christmas Day rolls past the second Christmas day and the weekend.
	 *
	 * @return void
	 */
	public function testAGivenHolidayRollsPastIt(): void {
		$end = $this->termRoll()->roll(at: new DateTimeImmutable('2026-12-25T00:00:00+00:00'));

		$this->assertSame('2026-12-28T00:00:00+00:00', $end['at']->format(DATE_ATOM));
		$this->assertSame('Kerstmis', $end['rolledBy']);

	}//end testAGivenHolidayRollsPastIt()

	/**
	 * With no engine the roll is refused, not computed here.
	 *
	 * @return void
	 */
	public function testAnUnreachableEngineRefusesTheRoll(): void {
		$this->expectException(TermRollUnavailableException::class);

		$this->termRoll(engineAvailable: false)->roll(at: new DateTimeImmutable('2026-12-12T00:00:00+00:00'));

	}//end testAnUnreachableEngineRefusesTheRoll()
}//end class
