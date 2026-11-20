<?php

/**
 * OpenCatalogi Handed Over Records Listener.
 *
 * Once every stored Woo request has moved to dossiq, the request schema is
 * read-only here: no new request, and no change to a stored one, except the
 * stamp dossiq's import writes.
 *
 * @category Listener
 * @package  OCA\OpenCatalogi\Listener
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * @version GIT: <git_id>
 * @link https://www.OpenCatalogi.nl
 * @spec openspec/changes/woo-request-intake-hands-over-to-dossiq/specs/woo-request-intake/spec.md#requirement-with-nothing-left-to-migrate-the-request-schema-is-read-only-req-whd-004
 */

declare(strict_types=1);

namespace OCA\OpenCatalogi\Listener;

use OCA\OpenCatalogi\Contract\HandoverStateInterface;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Event\ObjectCreatingEvent;
use OCA\OpenRegister\Event\ObjectUpdatingEvent;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\IAppConfig;

/**
 * Refuses a create, and an update that changes more than the stamp, on the Woo request schema while it is read-only.
 *
 * @template-implements IEventListener<Event>
 * @spec openspec/changes/woo-request-intake-hands-over-to-dossiq/specs/woo-request-intake/spec.md#requirement-with-nothing-left-to-migrate-the-request-schema-is-read-only-req-whd-004
 */
class HandedOverRecordsListener implements IEventListener {

	/**
	 * The refusal.
	 */
	public const REFUSAL = 'Woo requests are handled by dossiq; the requests stored in OpenCatalogi can no longer be changed.';

	/**
	 * The fields dossiq's import writes on a moved request.
	 */
	public const STAMP = ['migratedTo', 'migratedAt'];

	/**
	 * Constructor.
	 *
	 * @param IAppConfig $config Names the Woo request schema.
	 * @param HandoverStateInterface $state Whether the schema is read-only.
	 */
	public function __construct(
		private readonly IAppConfig $config,
		private readonly HandoverStateInterface $state,
	) {
	}//end __construct()

	/**
	 * Refuse the write when it is not allowed on a read-only request schema.
	 *
	 * @param Event $event The OpenRegister pre-save event.
	 *
	 * @return void
	 *
	 * @psalm-suppress InvalidArgument OpenRegister events extend OCP Event.
	 * @spec openspec/changes/woo-request-intake-hands-over-to-dossiq/specs/woo-request-intake/spec.md#requirement-with-nothing-left-to-migrate-the-request-schema-is-read-only-req-whd-004
	 */
	public function handle(Event $event): void {
		if ($event instanceof ObjectCreatingEvent === true) {
			if ($this->guarded(object: $event->getObject()) === true) {
				$this->refuse(event: $event);
			}

			return;
		}

		if ($event instanceof ObjectUpdatingEvent === false || $this->guarded(object: $event->getNewObject()) === false) {
			return;
		}

		if (self::onlyTheStampChanged(old: $event->getOldObject(), new: $event->getNewObject()) === false) {
			$this->refuse(event: $event);
		}
	}//end handle()

	/**
	 * Whether the object is a Woo request and the schema is read-only.
	 *
	 * @param ObjectEntity $object The object being written.
	 *
	 * @return bool
	 */
	private function guarded(ObjectEntity $object): bool {
		$schema = trim($this->config->getValueString('opencatalogi', 'woo_request_schema', ''));
		if ($schema === '' || (string)$object->getSchema() !== $schema) {
			return false;
		}

		return $this->state->isReadOnly();
	}//end guarded()

	/**
	 * Whether an update changes nothing but the stamp.
	 *
	 * Without the stored object nothing can be compared, so the update is not one.
	 *
	 * @param ObjectEntity|null $old The stored object.
	 * @param ObjectEntity $new The object as it would be written.
	 *
	 * @return bool
	 */
	private static function onlyTheStampChanged(?ObjectEntity $old, ObjectEntity $new): bool {
		if ($old === null) {
			return false;
		}

		return self::withoutStamp(data: ($old->getObject() ?? [])) === self::withoutStamp(data: ($new->getObject() ?? []));
	}//end onlyTheStampChanged()

	/**
	 * An object's data without the stamp and without the metadata OpenRegister adds.
	 *
	 * @param array<string, mixed> $data The object's data.
	 *
	 * @return mixed The data compared, its keys in order.
	 */
	private static function withoutStamp(array $data): mixed {
		return self::sorted(value: array_diff_key($data, array_flip([...self::STAMP, '@self', 'id'])));
	}//end withoutStamp()

	/**
	 * A value with every map's keys in order, so two equal objects compare equal whatever their key order.
	 *
	 * @param mixed $value The value.
	 *
	 * @return mixed The value, sorted.
	 */
	private static function sorted(mixed $value): mixed {
		if (is_array($value) === false) {
			return $value;
		}

		if (array_is_list($value) === false) {
			ksort($value);
		}

		return array_map(static fn (mixed $item): mixed => self::sorted(value: $item), $value);
	}//end sorted()

	/**
	 * Stop the write with the refusal.
	 *
	 * @param ObjectCreatingEvent|ObjectUpdatingEvent $event The pre-save event.
	 *
	 * @return void
	 */
	private function refuse(ObjectCreatingEvent|ObjectUpdatingEvent $event): void {
		$event->setErrors(['message' => self::REFUSAL]);
		$event->stopPropagation();
	}//end refuse()
}//end class
