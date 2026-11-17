<?php

/**
 * Stub of OpenRegister's OwnedRecord, for runs without OpenRegister.
 *
 * Mirrors the constructor of OCA\OpenRegister\Service\Rbac\OwnedRecord on
 * main: the register, schema and object a release-to-system call acts on.
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

namespace OCA\OpenRegister\Service\Rbac;

/**
 * The record an ownership change acts on.
 */
final class OwnedRecord {

	/**
	 * Build the record.
	 *
	 * @param mixed $register The register.
	 * @param mixed $schema   The schema.
	 * @param mixed $object   The object.
	 */
	public function __construct(
		public readonly mixed $register,
		public readonly mixed $schema,
		public readonly mixed $object,
	) {
	}//end __construct()
}//end class
