<?php

/**
 * Builds the ObjectSaveOptions OpenRegister's ObjectService::saveObject() takes.
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
 * @spec exclude pure framework plumbing: builds OpenRegister's save value objects.
 */

declare(strict_types=1);

namespace OCA\OpenCatalogi\Service;

use OCA\OpenRegister\Service\Object\ObjectFindTarget;
use OCA\OpenRegister\Service\Object\ObjectSaveOptions;
use OCA\OpenRegister\Service\Object\SaveActor;

/**
 * The register, schema, uuid and ownership of one save, as an ObjectSaveOptions,
 * so a class that saves does not take on the three value objects itself.
 *
 * @spec exclude pure framework plumbing: builds OpenRegister's save value objects.
 */
trait BuildsSaveOptions {

	/**
	 * The options for one save into a register and schema.
	 *
	 * @param mixed $register The register (slug, uuid or id).
	 * @param mixed $schema The schema (slug, uuid or id).
	 * @param string|null $uuid The object's uuid, for an update.
	 *
	 * @return ObjectSaveOptions The options.
	 *
	 * @spec exclude pure framework plumbing: builds OpenRegister's save value objects.
	 */
	private function saveOptions(mixed $register, mixed $schema, ?string $uuid = null): ObjectSaveOptions {
		return new ObjectSaveOptions(target: new ObjectFindTarget(register: $register, schema: $schema), uuid: $uuid);
	}//end saveOptions()

	/**
	 * The options for a save that leaves the new object without an owner.
	 *
	 * @param mixed $register The register (slug, uuid or id).
	 * @param mixed $schema The schema (slug, uuid or id).
	 *
	 * @return ObjectSaveOptions The options.
	 *
	 * @spec exclude pure framework plumbing: builds OpenRegister's save value objects.
	 */
	private function unownedSaveOptions(mixed $register, mixed $schema): ObjectSaveOptions {
		return new ObjectSaveOptions(
			target: new ObjectFindTarget(register: $register, schema: $schema),
			actor: new SaveActor(unowned: true)
		);
	}//end unownedSaveOptions()
}//end trait
