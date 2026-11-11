<?php

/**
 * Stub for OCA\OpenRegister\Service\Flow\Timer\RollRule.
 *
 * @category Test
 * @package  OCA\OpenCatalogi\Tests\Stubs
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Flow\Timer;

/**
 * Mirrors the real value object: how a moment that lands on a non-working day moves.
 */
final class RollRule {

	/**
	 * Constructor.
	 *
	 * @param string $roll One of SlaCalculatorInterface::ROLLS.
	 * @param WorkingCalendar|null $calendar The resolved calendar.
	 */
	public function __construct(
		public readonly string $roll,
		public readonly ?WorkingCalendar $calendar,
	) {
	}//end __construct()
}//end class
