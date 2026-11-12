<?php

/**
 * Stub for OCA\OpenRegister\Service\Flow\Timer\TimerDecision.
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
 * Mirrors the real value object: one timer and the reason for deciding on it.
 */
final class TimerDecision {

	/**
	 * Constructor.
	 *
	 * @param string $uuid The timer uuid.
	 * @param string|null $reason The reason.
	 */
	public function __construct(
		public readonly string $uuid,
		public readonly ?string $reason,
	) {
	}//end __construct()
}//end class
