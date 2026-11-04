<?php

/**
 * Stub for OCA\OpenRegister\Db\MagicMapper.
 *
 * @category Test
 * @package  OCA\OpenCatalogi\Tests\Stubs
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Db;

/**
 * Minimal stub for MagicMapper: the table provisioning this app calls, with the real signature.
 */
class MagicMapper {

	/**
	 * Make sure the magic table for a register/schema pair exists.
	 *
	 * @param Register $register The register.
	 * @param Schema $schema The schema.
	 * @param boolean $force Recreate the table even when it exists.
	 *
	 * @return boolean Whether the table exists afterwards.
	 */
	public function ensureTableForRegisterSchema(Register $register, Schema $schema, bool $force = false): bool {
		return true;
	}//end ensureTableForRegisterSchema()
}//end class
