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
 * reads as "no" for every user; {@see PermissionQuestion::answerOf()} asks in
 * the shape the installed handler takes:
 *
 *     (new PermissionQuestion(schema: $schema, action: 'update'))
 *         ->askedBy(userId: null, objectOwner: $owner)->about(object: $publication)->answerOf(handler: $handler)
 *
 * @spec openspec/specs/publications/spec.md#requirement-an-editor-publishes-or-withdraws-a-publication-in-one-action-req-ppw-002
 */
final class PermissionQuestion {

	/**
	 * OpenRegister's permission question, named by string so the app loads without OpenRegister.
	 */
	public const ASK_CLASS = 'OCA\OpenRegister\Service\Object\PermissionAsk';

	/**
	 * The user asking; null for the signed-in user.
	 *
	 * @var string|null
	 */
	private ?string $userId = null;

	/**
	 * The owner of the object asked about.
	 *
	 * @var string|null
	 */
	private ?string $objectOwner = null;

	/**
	 * The object asked about.
	 *
	 * @var object|null
	 */
	private ?object $object = null;

	/**
	 * A question about an action on a schema.
	 *
	 * @param object $schema   The schema asked about.
	 * @param string $action   The action (`read`, `create`, `update`, ...).
	 * @param string $askClass The question class to look for in OpenRegister.
	 *
	 * @spec openspec/specs/publications/spec.md#requirement-an-editor-publishes-or-withdraws-a-publication-in-one-action-req-ppw-002
	 */
	public function __construct(
		private readonly object $schema,
		private readonly string $action,
		private readonly string $askClass=self::ASK_CLASS
	) {
	}//end __construct()

	/**
	 * Who asks, and who owns the object.
	 *
	 * @param string|null $userId      The user; null for the signed-in user.
	 * @param string|null $objectOwner The owner of the object asked about.
	 *
	 * @return self
	 *
	 * @spec openspec/specs/publications/spec.md#requirement-an-editor-publishes-or-withdraws-a-publication-in-one-action-req-ppw-002
	 */
	public function askedBy(?string $userId, ?string $objectOwner=null): self {
		$this->userId      = $userId;
		$this->objectOwner = $objectOwner;
		return $this;
	}//end askedBy()

	/**
	 * The object asked about.
	 *
	 * @param object|null $object The object.
	 *
	 * @return self
	 *
	 * @spec openspec/specs/publications/spec.md#requirement-an-editor-publishes-or-withdraws-a-publication-in-one-action-req-ppw-002
	 */
	public function about(?object $object): self {
		$this->object = $object;
		return $this;
	}//end about()

	/**
	 * The handler's answer, asked in the shape it takes.
	 *
	 * @param object $handler OpenRegister's PermissionHandler.
	 *
	 * @return bool
	 *
	 * @spec openspec/specs/publications/spec.md#requirement-an-editor-publishes-or-withdraws-a-publication-in-one-action-req-ppw-002
	 */
	public function answerOf(object $handler): bool {
		if (class_exists($this->askClass) === true) {
			$ask = new $this->askClass(schema: $this->schema, action: $this->action);
			$ask = $ask->askedBy(userId: $this->userId, objectOwner: $this->objectOwner)->about(object: $this->object, register: null);
			return $handler->hasPermission(ask: $ask) === true;
		}

		return $handler->hasPermission(
			schema: $this->schema,
			action: $this->action,
			userId: $this->userId,
			objectOwner: $this->objectOwner,
			object: $this->object
		) === true;
	}//end answerOf()
}//end class
