<?php

/**
 * Stub of OpenRegister's SavePolicy for unit runs without OpenRegister.
 *
 * Mirrors the constructor of OCA\OpenRegister\Kernel\Contracts\Value\SavePolicy on main.
 *
 * @category Test
 * @package  OCA\OpenCatalogi\Tests
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Kernel\Contracts\Value;

/**
 * How a save validates and reports.
 */
final class SavePolicy {

	/**
	 * Build the value.
	 */
	public function __construct(
		public readonly bool $silent = false,
		public readonly bool $validation = true,
		public readonly bool $failIfExists = false,
		public readonly bool $dedupOverride = false,
	) {
	}//end __construct()
}//end class
