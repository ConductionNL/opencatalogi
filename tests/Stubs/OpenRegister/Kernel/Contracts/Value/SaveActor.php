<?php

/**
 * Stub of OpenRegister's SaveActor for unit runs without OpenRegister.
 *
 * Mirrors the constructor of OCA\OpenRegister\Kernel\Contracts\Value\SaveActor on main.
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
 * Who a save acts as and who owns the result.
 */
final class SaveActor {

	/**
	 * Build the value.
	 */
	public function __construct(
		public readonly ?object $currentUser = null,
		public readonly ?string $owner = null,
		public readonly bool $unowned = false,
	) {
	}//end __construct()
}//end class
