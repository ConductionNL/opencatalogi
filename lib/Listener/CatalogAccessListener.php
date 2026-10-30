<?php

/**
 * OpenCatalogi Catalog Access Listener.
 *
 * Guards a catalogue's staff access before it is written, and turns it into
 * OpenRegister rules after: only an administrator may change who sees and
 * edits a catalogue's publications, and every catalogue save or delete
 * recomputes the rules of the schemas it covered and covers. Because every
 * catalogue covering a schema feeds that schema's rules, a change to a
 * catalogue's schemas or filters, and creating or deleting a catalogue, is
 * guarded the same way when it touches a schema a restricted catalogue covers.
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

use OCA\OpenCatalogi\Contract\CatalogAccessServiceInterface;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Event\ObjectCreatedEvent;
use OCA\OpenRegister\Event\ObjectCreatingEvent;
use OCA\OpenRegister\Event\ObjectDeletedEvent;
use OCA\OpenRegister\Event\ObjectDeletingEvent;
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
	 * @param CatalogAccessServiceInterface $access       Writes the rules.
	 * @param IAppConfig           $config       App configuration.
	 * @param IUserSession         $userSession  The session.
	 * @param IGroupManager        $groupManager For the administrator check.
	 */
	public function __construct(
		private readonly CatalogAccessServiceInterface $access,
		private readonly IAppConfig $config,
		private readonly IUserSession $userSession,
		private readonly IGroupManager $groupManager,
	) {

	}//end __construct()

	/**
	 * Guard before a catalogue write, recompute after one.
	 *
	 * The post-save recompute stays in the request on purpose (ADR-078 rule 3).
	 * It reads the catalogues once for every covered schema and never throws.
	 *
	 * @param Event $event The OpenRegister object event.
	 *
	 * @return void
	 *
	 * @psalm-suppress InvalidArgument OpenRegister events extend OCP Event.
	 *
	 * @listener-placement inline correctness — deferred, a just-restricted catalogue stays open to every signed-in user until the job runs
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

		if ($event instanceof ObjectDeletingEvent === true) {
			$this->guard(event: $event, new: null, old: $event->getObject());
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

		$this->access->recomputeMany(schemaIds: $schemaIds);

	}//end handle()

	/**
	 * Refuse the write when someone other than an administrator changes who may
	 * see and edit a restricted catalogue's publications.
	 *
	 * That is a change to the staff access itself, or a change to the schemas or
	 * filters (creating and deleting a catalogue included) whose old or new
	 * schemas include one a restricted catalogue covers. When the catalogues
	 * cannot be read, a scope change is refused: fail closed.
	 *
	 * A write without a session (a background job, an import) is the system's own.
	 *
	 * @param ObjectCreatingEvent|ObjectUpdatingEvent|ObjectDeletingEvent $event The pre-save event.
	 * @param ObjectEntity|null                                           $new   The object as it would be written; null on delete.
	 * @param ObjectEntity|null                                           $old   The object as stored; null on create.
	 *
	 * @return void
	 */
	private function guard(ObjectCreatingEvent|ObjectUpdatingEvent|ObjectDeletingEvent $event, ?ObjectEntity $new, ?ObjectEntity $old): void {
		$subject = ($new ?? $old);
		if ($subject === null || $this->isCatalog(object: $subject) === false) {
			return;
		}

		$before = ($old?->getObject() ?? []);
		$after = ($new?->getObject() ?? []);
		$accessChanged = $this->normalise(access: ($before['staffAccess'] ?? null)) !== $this->normalise(access: ($after['staffAccess'] ?? null));
		$scopeChanged = $this->scope(catalog: $before) !== $this->scope(catalog: $after);
		if ($accessChanged === false && $scopeChanged === false) {
			return;
		}

		$user = $this->userSession->getUser();
		if ($user === null || $this->groupManager->isAdmin($user->getUID()) === true) {
			return;
		}

		if ($accessChanged === false && $this->touchesARestrictedSchema(before: $before, after: $after) === false) {
			return;
		}

		$event->setErrors(['message' => self::REFUSAL]);
		$event->stopPropagation();

	}//end guard()

	/**
	 * Whether the old or new schemas of a catalogue include one a restricted catalogue covers.
	 *
	 * @param array<string, mixed> $before The catalogue as stored.
	 * @param array<string, mixed> $after  The catalogue as it would be written.
	 *
	 * @return bool True when it does, or when the catalogues cannot be read.
	 */
	private function touchesARestrictedSchema(array $before, array $after): bool {
		$touched = array_merge($this->access->schemaIdsOf(catalog: $before), $this->access->schemaIdsOf(catalog: $after));
		if ($touched === []) {
			return false;
		}

		$restricted = $this->access->restrictedSchemaIds();
		if ($restricted === null) {
			return true;
		}

		return array_intersect($touched, $restricted) !== [];

	}//end touchesARestrictedSchema()

	/**
	 * A catalogue's scope in one comparable shape: its schemas and its filters.
	 *
	 * @param array<string, mixed> $catalog The catalogue.
	 *
	 * @return array<string, mixed> The shape.
	 */
	private function scope(array $catalog): array {
		$schemas = [];
		if (is_array($catalog['schemas'] ?? null) === true) {
			$schemas = array_map(static fn (mixed $entry): string => (string)json_encode($entry), $catalog['schemas']);
			sort($schemas);
		}

		$filters = [];
		if (is_array($catalog['filters'] ?? null) === true) {
			$filters = $catalog['filters'];
		}

		return ['schemas' => array_values(array_unique($schemas)), 'filters' => $filters];

	}//end scope()

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
