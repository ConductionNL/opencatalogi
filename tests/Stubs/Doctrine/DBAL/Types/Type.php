<?php

/**
 * Stub for Doctrine\DBAL\Types\Type.
 *
 * @category Test
 * @package  OCA\OpenCatalogi\Tests\Stubs
 * @license  EUPL-1.2
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace Doctrine\DBAL\Types;

/**
 * Stub for Doctrine DBAL Type.
 */
abstract class Type {

	/**
	 * @return string
	 */
	abstract public function getName(): string;

}//end class
