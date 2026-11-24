<?php

/**
 * Reads a PermissionHandler::hasPermission() call in the release's argument order.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Tests
 * @package  OCA\OpenCatalogi\Tests\Support
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.conduction.nl
 */

declare(strict_types=1);

namespace OCA\OpenCatalogi\Tests\Support;

/**
 * OpenRegister main asks `hasPermission(PermissionAsk $ask, bool $_rbac)`; the
 * release asks `hasPermission($schema, $action, $userId, $objectOwner, $_rbac, $object)`.
 * A test written against the release reads either through {@see PermissionArgs::release()}.
 */
final class PermissionArgs {

	/**
	 * The call's arguments as `[schema, action, userId, objectOwner, _rbac, object]`.
	 *
	 * @param array<int, mixed> $args The arguments the mock received.
	 *
	 * @return array<int, mixed> The arguments in the release's order.
	 */
	public static function release(array $args): array {
		$ask = ($args[0] ?? null);
		if (is_object($ask) === true && property_exists($ask, 'action') === true && property_exists($ask, 'schema') === true) {
			return [$ask->schema, $ask->action, $ask->userId, $ask->objectOwner, ($args[1] ?? true), $ask->object];
		}

		return [($args[0] ?? null), ($args[1] ?? null), ($args[2] ?? null), ($args[3] ?? null), ($args[4] ?? true), ($args[5] ?? null)];
	}//end release()
}//end class
