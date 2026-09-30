<?php

/**
 * OpenRegister PermissionHandler stub for bare CI.
 *
 * @category Test
 * @package  OCA\OpenRegister\Service\Object
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenCatalogi.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Object;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Schema;

/**
 * Minimal stub for PermissionHandler used by PHPUnit mocks in bare CI.
 * The signature matches the real hasPermission() so named-parameter calls work.
 */
class PermissionHandler {

	/**
	 * Whether the user may take the action on the schema, or on one object of it.
	 *
	 * @param Schema            $schema      The schema.
	 * @param string            $action      The CRUD action.
	 * @param string|null       $userId      The user, the current one when null.
	 * @param string|null       $objectOwner The object's owner.
	 * @param boolean           $_rbac       Whether to apply RBAC.
	 * @param ObjectEntity|null $object      The object, for conditional rules.
	 *
	 * @return boolean
	 */
	public function hasPermission(
		Schema $schema,
		string $action,
		?string $userId = null,
		?string $objectOwner = null,
		bool $_rbac = true,
		?ObjectEntity $object = null,
	): bool {
		return false;
	}//end hasPermission()
}//end class
