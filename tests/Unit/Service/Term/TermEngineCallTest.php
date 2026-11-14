<?php

/**
 * Unit tests for the requests the term service hands the OpenRegister timer engine.
 *
 * @category Test
 * @package  OCA\OpenCatalogi\Tests\Unit\Service\Term
 * @author   Conduction Development Team <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://www.OpenCatalogi.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenCatalogi\Tests\Unit\Service\Term;

use DateTimeImmutable;
use OCA\OpenCatalogi\Service\Term\TermEngineCall;
use PHPUnit\Framework\TestCase;

/**
 * Each builder passes its arguments to the right field of the engine's request.
 *
 * @covers \OCA\OpenCatalogi\Service\Term\TermEngineCall
 */
class TermEngineCallTest extends TestCase {

	/**
	 * An extension carries the timer, amount, unit and rationale.
	 *
	 * @return void
	 */
	public function testExtension(): void {
		$request = (new TermEngineCall())->extension('timer-1', 6, 'weeks', 'Complex request');

		$this->assertSame(
			['timer-1', 6, 'weeks', 'Complex request'],
			[$request->uuid, $request->amount, $request->unit, $request->rationale]
		);
	}//end testExtension()

	/**
	 * A suspension is open-ended and carries the reason and legal basis.
	 *
	 * @return void
	 */
	public function testSuspensionIsOpenEnded(): void {
		$suspension = (new TermEngineCall())->suspension('timer-2', 'Awaiting third party', 'Woo art. 4.4');

		$this->assertSame('timer-2', $suspension->uuid);
		$this->assertSame('Awaiting third party', $suspension->reason);
		$this->assertSame('Woo art. 4.4', $suspension->basis);
		$this->assertNull($suspension->until);
	}//end testSuspensionIsOpenEnded()

	/**
	 * A decision keeps its reason, which may be absent.
	 *
	 * @return void
	 */
	public function testDecision(): void {
		$call = new TermEngineCall();

		$decision = $call->decision('timer-3', 'Settled');
		$this->assertSame(['timer-3', 'Settled'], [$decision->uuid, $decision->reason]);

		$this->assertNull($call->decision('timer-3', null)->reason);
	}//end testDecision()

	/**
	 * An act carries the actor and the current time, with no separate effective date.
	 *
	 * @return void
	 */
	public function testActIsStampedNow(): void {
		$before = new DateTimeImmutable();
		$act    = (new TermEngineCall())->act('admin');
		$after  = new DateTimeImmutable();

		$this->assertSame('admin', $act->actor);
		$this->assertGreaterThanOrEqual($before, $act->now);
		$this->assertLessThanOrEqual($after, $act->now);
		$this->assertNull($act->when);
		$this->assertNull((new TermEngineCall())->act(null)->actor);
	}//end testActIsStampedNow()
}//end class
