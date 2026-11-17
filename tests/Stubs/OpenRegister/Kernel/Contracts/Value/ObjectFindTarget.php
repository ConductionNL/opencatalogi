<?php

/**
 * Stub of OpenRegister's ObjectFindTarget for unit runs without OpenRegister.
 *
 * Mirrors the constructor of OCA\OpenRegister\Kernel\Contracts\Value\ObjectFindTarget on main.
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
 * Which register and schema an object lives in.
 */
final class ObjectFindTarget {

	/**
	 * Build the value.
	 */
	public function __construct(
		public readonly mixed $register = null,
		public readonly mixed $schema = null,
	) {
	}//end __construct()
}//end class
