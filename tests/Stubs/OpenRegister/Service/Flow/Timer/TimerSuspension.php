<?php

/**
 * Stub for OCA\OpenRegister\Service\Flow\Timer\TimerSuspension.
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

use DateTimeInterface;

/**
 * Mirrors the real value object: one suspension of a timer.
 */
final class TimerSuspension {

	/**
	 * Constructor.
	 *
	 * @param string $uuid The timer uuid.
	 * @param string $reason Why the term is suspended.
	 * @param DateTimeInterface|null $until When the suspension ends, if known.
	 * @param string|null $basis The legal basis of the suspension.
	 */
	public function __construct(
		public readonly string $uuid,
		public readonly string $reason,
		public readonly ?DateTimeInterface $until = null,
		public readonly ?string $basis = null,
	) {
	}//end __construct()
}//end class
