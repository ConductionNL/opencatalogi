<?php

/**
 * Pins what StatusPageService::setState() records and what it refuses.
 *
 * @category Test
 * @package  OCA\OpenCatalogi\Tests\Unit\Service\Community
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://www.OpenCatalogi.nl
 *
 * @spec openspec/changes/the-public-and-community-surface/specs/public-and-community-surface/spec.md#requirement-a-public-status-page-says-what-is-running-req-pcs-101
 */

declare(strict_types=1);

namespace Unit\Service\Community;

use DateTimeImmutable;
use DomainException;
use OCA\OpenCatalogi\Contract\StatusChange;
use OCA\OpenCatalogi\Service\Community\StatusPageService;
use PHPUnit\Framework\TestCase;

/**
 * Tests for StatusPageService::setState().
 */
class StatusPageSetStateTest extends TestCase {

	/**
	 * The recorded status: the trimmed component, the state, the message, the
	 * moment in UTC and who set it.
	 *
	 * @return void
	 */
	public function testTheStatusRecordsTheChangeAndTheMoment(): void {
		$status = $this->setState(component: '  DigiD ', state: 'maintenance');

		$this->assertSame(
			[
				'component' => 'DigiD',
				'state' => 'maintenance',
				'message' => 'Onderhoud tot 12:00.',
				'stateSetAt' => '2026-09-18T11:30:00+02:00',
				'stateSetBy' => 'beheerder',
			],
			$status
		);
	}//end testTheStatusRecordsTheChangeAndTheMoment()

	/**
	 * Without a moment the status is stamped now, in UTC.
	 *
	 * @return void
	 */
	public function testWithoutAMomentTheStatusIsStampedNowInUtc(): void {
		$before = time();
		$status = (new StatusPageService())->setState(change: $this->change(component: 'DigiD', state: 'available'));
		$after = time();

		$this->assertStringEndsWith('+00:00', $status['stateSetAt']);
		$stamped = (new DateTimeImmutable($status['stateSetAt']))->getTimestamp();
		$this->assertGreaterThanOrEqual($before, $stamped);
		$this->assertLessThanOrEqual($after, $stamped);
	}//end testWithoutAMomentTheStatusIsStampedNowInUtc()

	/**
	 * An unknown state is refused before a blank component is.
	 *
	 * @return void
	 */
	public function testAnUnknownStateIsRefusedFirst(): void {
		$this->expectException(DomainException::class);
		$this->expectExceptionMessage('A component cannot be set to "green": the status page knows only available, degraded, unavailable, maintenance.');

		$this->setState(component: ' ', state: 'green');
	}//end testAnUnknownStateIsRefusedFirst()

	/**
	 * A blank component is refused.
	 *
	 * @return void
	 */
	public function testABlankComponentIsRefused(): void {
		$this->expectException(DomainException::class);
		$this->expectExceptionMessage('A status needs the component it is about.');

		$this->setState(component: " \t", state: 'available');
	}//end testABlankComponentIsRefused()

	/**
	 * Set a state at a fixed moment.
	 *
	 * @param string $component The component.
	 * @param string $state The state.
	 *
	 * @return array<string, mixed>
	 */
	private function setState(string $component, string $state): array {
		return (new StatusPageService())->setState(
			change: $this->change(component: $component, state: $state),
			now: new DateTimeImmutable('2026-09-18T11:30:00+02:00')
		);
	}//end setState()

	/**
	 * A state change with its message, set by the administrator.
	 *
	 * @param string $component The component.
	 * @param string $state The state.
	 *
	 * @return StatusChange
	 */
	private function change(string $component, string $state): StatusChange {
		$change = new StatusChange();
		$change->component = $component;
		$change->state = $state;
		$change->message = 'Onderhoud tot 12:00.';
		$change->setBy = 'beheerder';

		return $change;
	}//end change()
}//end class
