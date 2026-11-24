<?php

/**
 * One permission question to OpenRegister's PermissionHandler, in the shape the installed OpenRegister takes.
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
 */

declare(strict_types=1);

namespace OCA\OpenCatalogi\Service;

/**
 * `PermissionHandler::hasPermission()` takes its question in two shapes: a
 * released OpenRegister takes `schema`, `action`, `userId`, `objectOwner` and
 * `object` as arguments of their own, a newer one takes one `PermissionAsk`.
 * Asking the newer handler in the old shape throws, which a fail-closed caller
 * reads as "no" for every user; {@see PermissionQuestion::ask()} asks in the
 * shape the installed handler takes.
 *
 * @spec openspec/specs/publications/spec.md#requirement-an-editor-publishes-or-withdraws-a-publication-in-one-action-req-ppw-002
 */
final class PermissionQuestion {

	/**
	 * OpenRegister's permission question, named by string so the app loads without OpenRegister.
	 */
	public const ASK_CLASS = 'OCA\OpenRegister\Service\Object\PermissionAsk';

	/**
	 * Ask the handler whether the action is allowed.
	 *
	 * @param object      $handler     OpenRegister's PermissionHandler.
	 * @param object      $schema      The schema asked about.
	 * @param string      $action      The action (`read`, `create`, `update`, ...).
	 * @param string|null $userId      The user; null for the signed-in user.
	 * @param string|null $objectOwner The owner of the object asked about.
	 * @param object|null $object      The object asked about.
	 * @param string      $askClass    The question class to look for.
	 *
	 * @return bool The handler's answer.
	 *
	 * @spec openspec/specs/publications/spec.md#requirement-an-editor-publishes-or-withdraws-a-publication-in-one-action-req-ppw-002
	 */
	public static function ask(
		object $handler,
		object $schema,
		string $action,
		?string $userId=null,
		?string $objectOwner=null,
		?object $object=null,
		string $askClass=self::ASK_CLASS
	): bool {
		if (class_exists($askClass) === true) {
			$ask = (new $askClass(schema: $schema, action: $action))->askedBy(userId: $userId, objectOwner: $objectOwner)->about(object: $object, register: null);
			return $handler->hasPermission(ask: $ask) === true;
		}

		return $handler->hasPermission(schema: $schema, action: $action, userId: $userId, objectOwner: $objectOwner, object: $object) === true;
	}//end ask()
}//end class
