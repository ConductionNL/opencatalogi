<?php

/**
 * Stub for OCA\OpenRegister\Kernel\Contracts\TimerChange.
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

namespace OCA\OpenRegister\Kernel\Contracts;

use DateTimeInterface;

/**
 * Mirrors the real value object: why a timer changes, who changed it and when.
 */
final class TimerChange {

	/**
	 * Constructor.
	 *
	 * @param string $reason Why.
	 * @param string|null $actor Who, or null for the system.
	 * @param DateTimeInterface|null $now When, or null for now.
	 */
	public function __construct(
		public readonly string $reason,
		public readonly ?string $actor = null,
		public readonly ?DateTimeInterface $now = null,
	) {
	}//end __construct()
}//end class
