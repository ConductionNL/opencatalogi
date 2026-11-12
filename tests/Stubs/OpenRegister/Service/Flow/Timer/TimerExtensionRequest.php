<?php

/**
 * Stub for OCA\OpenRegister\Service\Flow\Timer\TimerExtensionRequest.
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
 * Mirrors the real value object: one extension of a timer.
 */
final class TimerExtensionRequest {

	/**
	 * Constructor.
	 *
	 * @param string $uuid The timer uuid.
	 * @param integer $amount The extension amount.
	 * @param string $unit The extension unit.
	 * @param string $rationale Why the term is extended.
	 */
	public function __construct(
		public readonly string $uuid,
		public readonly int $amount,
		public readonly string $unit,
		public readonly string $rationale,
	) {
	}//end __construct()
}//end class
