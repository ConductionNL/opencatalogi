<?php

/**
 * Builds the ObjectFindOptions OpenRegister's ObjectService::find() takes.
 *
 * @category Service
 * @package  OCA\OpenCatalogi\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenCatalogi.nl
 *
 * @spec exclude pure framework plumbing: builds OpenRegister's find value object.
 */

declare(strict_types=1);

namespace OCA\OpenCatalogi\Service;

use OCA\OpenRegister\Kernel\Contracts\Value\ObjectFindOptions;

/**
 * The register and schema of one read, as an ObjectFindOptions, so a class
 * that reads does not take on the value object itself.
 *
 * @spec exclude pure framework plumbing: builds OpenRegister's find value object.
 */
trait BuildsFindOptions {

	/**
	 * The options for one read from a register and schema.
	 *
	 * @param mixed $register The register (slug, uuid or id).
	 * @param mixed $schema The schema (slug, uuid or id).
	 *
	 * @return ObjectFindOptions The options.
	 *
	 * @spec exclude pure framework plumbing: builds OpenRegister's find value object.
	 */
	private function findOptions(mixed $register, mixed $schema): ObjectFindOptions {
		return new ObjectFindOptions(register: $register, schema: $schema);
	}//end findOptions()
}//end trait
