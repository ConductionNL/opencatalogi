<?php

/**
 * OpenCatalogi Value List Choice Listener.
 *
 * A value-list choice holds only what the organisation owns about an entry:
 * its order, its own label, its explanation and the hidden and active flags.
 * The code, the URI and the source's label belong to the national list and are
 * refreshed from it. Before a choice is saved, a property outside the choice's
 * own set is refused, naming it, so an overlay can never change a normative
 * field and a refresh never has an overlay field to overwrite.
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
 * @spec openspec/changes/woo-value-list-curation/tasks.md#task-1.1
 */

declare(strict_types=1);

namespace OCA\OpenCatalogi\Listener;

use OCP\IL10N;
use OCP\IAppConfig;
use OCP\EventDispatcher\IEventListener;
use OCP\EventDispatcher\Event;
use OCA\OpenRegister\Event\ObjectUpdatingEvent;
use OCA\OpenRegister\Event\ObjectCreatingEvent;
use OCA\OpenRegister\Db\ObjectEntity;

/**
 * Refuses a value-list choice that sets a property it does not own.
 *
 * @spec openspec/changes/woo-value-list-curation/specs/woo-compliance/spec.md#requirement-the-organisations-own-text-sits-beside-the-normative-entry-req-wvl-001
 * @template-implements IEventListener<Event>
 */
class ValueListChoiceListener implements IEventListener {

	/**
	 * The app-config key of the valueListChoice schema; the store reads the same key.
	 *
	 * @var string
	 */
	public const SCHEMA_KEY = 'value_list_choice_schema';

	/**
	 * The properties a choice holds; every other property is refused on save.
	 *
	 * @var list<string>
	 */
	public const PROPERTIES = ['scheme', 'entry', 'order', 'localLabel', 'explanation', 'hidden', 'active'];

	/**
	 * The fields of a list entry that belong to the national list, not to the organisation.
	 *
	 * @var list<string>
	 */
	public const NORMATIVE = ['code', 'uri', 'sourceLabel', 'label', 'status'];

	/**
	 * The keys OpenRegister itself carries in an object's data, which are not properties of the choice.
	 *
	 * @var list<string>
	 */
	private const SYSTEM_KEYS = ['id', 'uuid'];

	/**
	 * Constructor.
	 *
	 * @param IAppConfig $config Names the valueListChoice schema.
	 * @param IL10N      $l10n   Localisation.
	 *
	 * @spec exclude pure framework plumbing: constructor dependency injection.
	 */
	public function __construct(
		private readonly IAppConfig $config,
		private readonly IL10N $l10n,
	) {

	}//end __construct()

	/**
	 * Refuse a choice that sets a normative field or a property a choice does not have.
	 *
	 * @param Event $event The OpenRegister pre-save event.
	 *
	 * @return void
	 *
	 * @psalm-suppress InvalidArgument OpenRegister events extend OCP Event.
	 *
	 * @spec openspec/changes/woo-value-list-curation/specs/woo-compliance/spec.md#requirement-the-organisations-own-text-sits-beside-the-normative-entry-req-wvl-001
	 */
	public function handle(Event $event): void {
		if ($event instanceof ObjectUpdatingEvent === true) {
			$this->guard(event: $event, written: $event->getNewObject());
			return;
		}

		if ($event instanceof ObjectCreatingEvent === true) {
			$this->guard(event: $event, written: $event->getObject());
		}

	}//end handle()

	/**
	 * Refuse the save when the object is a choice that sets a field it does not own.
	 *
	 * @param ObjectCreatingEvent|ObjectUpdatingEvent $event   The pre-save event.
	 * @param ObjectEntity                            $written The object as written.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/woo-value-list-curation/specs/woo-compliance/spec.md#requirement-the-organisations-own-text-sits-beside-the-normative-entry-req-wvl-001
	 */
	private function guard(ObjectCreatingEvent|ObjectUpdatingEvent $event, ObjectEntity $written): void {
		if ($this->isChoice(entity: $written) === false) {
			return;
		}

		$refusal = $this->refusal(data: ($written->getObject() ?? []));
		if ($refusal === null) {
			return;
		}

		$event->setErrors($refusal);
		$event->stopPropagation();

	}//end guard()

	/**
	 * Whether the object is a value-list choice.
	 *
	 * @param ObjectEntity $entity The object as written.
	 *
	 * @return bool
	 */
	private function isChoice(ObjectEntity $entity): bool {
		$schema = trim((string)$entity->getSchema());
		$configured = trim($this->config->getValueString('opencatalogi', self::SCHEMA_KEY, ''));

		return $schema !== '' && $schema === $configured;

	}//end isChoice()

	/**
	 * Why the choice may not be saved, or null when it may.
	 *
	 * @param array<string, mixed> $data The choice as written.
	 *
	 * @return array{message: string, property: string}|null The refusal.
	 */
	private function refusal(array $data): ?array {
		foreach (array_keys($data) as $key) {
			$key = (string)$key;
			if (in_array($key, self::PROPERTIES, true) === true
				|| in_array($key, self::SYSTEM_KEYS, true) === true
				|| str_starts_with($key, '@') === true
				|| str_starts_with($key, '_') === true
			) {
				continue;
			}

			if (in_array($key, self::NORMATIVE, true) === true) {
				return [
					'message' => $this->l10n->t('The field %s belongs to the national value list and cannot be changed here; change it at the source.', [$key]),
					'property' => $key,
				];
			}

			return [
				'message' => $this->l10n->t('A value list choice has no field %s.', [$key]),
				'property' => $key,
			];
		}//end foreach

		return null;

	}//end refusal()
}//end class
