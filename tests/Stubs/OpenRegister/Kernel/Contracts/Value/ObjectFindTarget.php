<?php

/**
 * OpenRegister ObjectFindTarget stub for tests and static analysis.
 *
 * Mirrors openregister/lib/Kernel/Contracts/Value/ObjectFindTarget.php on
 * main: the register and schema an object call targets. The real class
 * ships in openregister; this stub only exists for runs without it.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Kernel\Contracts\Value
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Kernel\Contracts\Value;

use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\Schema;

/**
 * The register and schema an object call targets.
 */
final class ObjectFindTarget {

	/**
	 * Constructor.
	 *
	 * @param Register|string|int|null $register The register, or null for any.
	 * @param Schema|string|int|null   $schema   The schema, or null for any.
	 */
	public function __construct(
		public readonly Register|string|int|null $register=null,
		public readonly Schema|string|int|null $schema=null,
	) {
	}//end __construct()
}//end class
