<?php

/**
 * OpenCatalogi Catalog Access Listener.
 *
 * Guards a catalogue's staff access before it is written, and turns it into
 * OpenRegister rules after: only an administrator may change who sees and
 * edits a catalogue's publications, and every catalogue save or delete
 * recomputes the rules of the schemas it covered and covers.
 *
 * @category Listener
 * @package  OCA\OpenCatalogi\Listener
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
 * @spec openspec/changes/catalogue-staff-access/specs/catalogs/spec.md#requirement-staff-access-becomes-openregister-rules-on-the-covered-schemas-req-csa-002
 */

declare(strict_types=1);

namespace OCA\OpenCatalogi\Listener;

use OCA\OpenCatalogi\Service\CatalogAccessService;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Event\ObjectCreatedEvent;
use OCA\OpenRegister\Event\ObjectCreatingEvent;
use OCA\OpenRegister\Event\ObjectDeletedEvent;
use OCA\OpenRegister\Event\ObjectUpdatedEvent;
use OCA\OpenRegister\Event\ObjectUpdatingEvent;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\IAppConfig;
use OCP\IGroupManager;
use OCP\IUserSession;

/**
 * Before a catalogue is written: refuse a staff access change by anyone but
 * an administrator. After: recompute the covered schemas' rules.
 *
 * @template-implements IEventListener<Event>
 *
 * @spec openspec/changes/catalogue-staff-access/specs/catalogs/spec.md#requirement-staff-access-becomes-openregister-rules-on-the-covered-schemas-req-csa-002
 */
class CatalogAccessListener implements IEventListener {

	/**
	 * The refusal a non-administrator gets.
	 */
	public const REFUSAL = 'Only an administrator may change who can see and edit the publications of a catalogue.';

	/**
	 * Constructor.
	 *
	 * @param CatalogAccessService $access       Writes the rules.
	 * @param IAppConfig           $config       App configuration.
	 * @param IUserSession         $userSession  The session.
	 * @param IGroupManager        $groupManager For the administrator check.
	 */
	public function __construct(
		private readonly CatalogAccessService $access,
		private readonly IAppConfig $config,
		private readonly IUserSession $userSession,
		private readonly IGroupManager $groupManager,
	) {

	}//end __construct()

	/**
	 * Guard before a catalogue write, recompute after one.
	 *
	 * @param Event $event The OpenRegister object event.
	 *
	 * @return void
	 *
	 * @psalm-suppress InvalidArgument OpenRegister events extend OCP Event.
	 *
	 * @spec openspec/changes/catalogue-staff-access/specs/catalogs/spec.md#requirement-staff-access-becomes-openregister-rules-on-the-covered-schemas-req-csa-002
	 */
	public function handle(Event $event): void {
		if ($event instanceof ObjectCreatingEvent === true) {
			$this->guard(event: $event, new: $event->getObject(), old: null);
			return;
		}

		if ($event instanceof ObjectUpdatingEvent === true) {
			$this->guard(event: $event, new: $event->getNewObject(), old: $event->getOldObject());
			return;
		}

		$objects = $this->savedObjects(event: $event);
		if ($objects === [] || $this->isCatalog(object: $objects[0]) === false) {
			return;
		}

		$schemaIds = [];
		foreach ($objects as $object) {
			$schemaIds = array_merge($schemaIds, $this->access->schemaIdsOf(catalog: ($object->getObject() ?? [])));
		}

		foreach (array_unique($schemaIds) as $schemaId) {
			$this->access->recompute(schemaId: $schemaId);
		}

	}//end handle()

	/**
	 * Refuse the write when someone other than an administrator changes the staff access.
	 *
	 * A write without a session (a background job, an import) is the system's own.
	 *
	 * @param ObjectCreatingEvent|ObjectUpdatingEvent $event The pre-save event.
	 * @param ObjectEntity                            $new   The object as it would be written.
	 * @param ObjectEntity|null                       $old   The object as stored.
	 *
	 * @return void
	 */
	private function guard(ObjectCreatingEvent|ObjectUpdatingEvent $event, ObjectEntity $new, ?ObjectEntity $old): void {
		if ($this->isCatalog(object: $new) === false) {
			return;
		}

		$before = (($old?->getObject() ?? [])['staffAccess'] ?? null);
		$after = (($new->getObject() ?? [])['staffAccess'] ?? null);
		if ($this->normalise(access: $before) === $this->normalise(access: $after)) {
			return;
		}

		$user = $this->userSession->getUser();
		if ($user === null || $this->groupManager->isAdmin($user->getUID()) === true) {
			return;
		}

		$event->setErrors(['message' => self::REFUSAL]);
		$event->stopPropagation();

	}//end guard()

	/**
	 * Staff access in one comparable shape; absent and unrestricted-and-empty are equal.
	 *
	 * @param mixed $access The stored staff access.
	 *
	 * @return array<string, mixed> The shape.
	 */
	private function normalise(mixed $access): array {
		if (is_array($access) === false) {
			$access = [];
		}

		$groups = static function (mixed $value): array {
			if (is_array($value) === false) {
				return [];
			}

			$list = array_map('strval', $value);
			sort($list);
			return $list;
		};

		return [
			'restricted' => ($access['restricted'] ?? false) === true,
			'readGroups' => $groups($access['readGroups'] ?? []),
			'editGroups' => $groups($access['editGroups'] ?? []),
		];

	}//end normalise()

	/**
	 * The catalogue versions a post-save event carries: the new and the old one.
	 *
	 * @param Event $event The event.
	 *
	 * @return array<int, ObjectEntity> The objects; none for another event.
	 *
	 * @psalm-suppress TypeDoesNotContainType OpenRegister events extend OCP Event.
	 */
	private function savedObjects(Event $event): array {
		if ($event instanceof ObjectCreatedEvent === true || $event instanceof ObjectDeletedEvent === true) {
			return [$event->getObject()];
		}

		if ($event instanceof ObjectUpdatedEvent === true) {
			return array_values(array_filter([$event->getNewObject(), $event->getOldObject()]));
		}

		return [];

	}//end savedObjects()

	/**
	 * Whether an object is a catalogue.
	 *
	 * @param ObjectEntity $object The object.
	 *
	 * @return bool True for a catalogue.
	 */
	private function isCatalog(ObjectEntity $object): bool {
		return (string)$object->getSchema() === $this->config->getValueString('opencatalogi', 'catalog_schema', '')
			&& (string)$object->getRegister() === $this->config->getValueString('opencatalogi', 'catalog_register', '');

	}//end isCatalog()
}//end class
