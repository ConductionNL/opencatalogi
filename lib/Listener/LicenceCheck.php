<?php

/**
 * OpenCatalogi Licence Check.
 *
 * A catalogue's `dcatLicense` and a publication's `license` are emitted in the
 * DCAT feed only as a member of the EU licences authority list. A save that
 * writes a new value outside that list is refused before it is written, so the
 * officer learns it at the form and not later from the feed validator. A value
 * already stored and left unchanged passes: the feed omits and reports it, and
 * an unrelated edit of a legacy record is not blocked.
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
 * @spec openspec/changes/dcat-catalogue-completion/tasks.md#task-1.4
 */

declare(strict_types=1);

namespace OCA\OpenCatalogi\Listener;

use OCA\OpenCatalogi\Service\DcatVocabularyService;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Event\ObjectCreatingEvent;
use OCA\OpenRegister\Event\ObjectUpdatingEvent;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\IAppConfig;
use OCP\IL10N;

/**
 * Refuses a new licence outside the EU licences authority list on a catalogue or publication.
 *
 * @spec openspec/changes/dcat-catalogue-completion/specs/dcat-ap-harvest/spec.md#requirement-the-licence-comes-from-a-controlled-list-bind-or-omit-req-dcc-001
 *
 * @template-implements IEventListener<Event>
 */
class LicenceCheck implements IEventListener {

	/**
	 * The licence property per app config key of the schema that carries it.
	 */
	public const FIELDS = [
		'catalog_schema' => 'dcatLicense',
		'publication_schema' => 'license',
	];

	/**
	 * Constructor.
	 *
	 * @param IAppConfig $config Names the catalogue and publication schemas.
	 * @param DcatVocabularyService $vocabulary Resolves a licence against the bundled list.
	 * @param IL10N $l10n Localisation.
	 *
	 * @spec exclude pure framework plumbing: constructor dependency injection.
	 */
	public function __construct(
		private readonly IAppConfig $config,
		private readonly DcatVocabularyService $vocabulary,
		private readonly IL10N $l10n,
	) {

	}//end __construct()

	/**
	 * Check the licence a save writes, before it is written.
	 *
	 * @param Event $event The OpenRegister pre-save event.
	 *
	 * @return void
	 *
	 * @psalm-suppress InvalidArgument OpenRegister events extend OCP Event.
	 *
	 * @spec openspec/changes/dcat-catalogue-completion/specs/dcat-ap-harvest/spec.md#requirement-the-licence-comes-from-a-controlled-list-bind-or-omit-req-dcc-001
	 */
	public function handle(Event $event): void {
		if ($event instanceof ObjectCreatingEvent === true) {
			$this->check(event: $event, new: $event->getObject(), old: null);
			return;
		}

		if ($event instanceof ObjectUpdatingEvent === true) {
			$this->check(event: $event, new: $event->getNewObject(), old: $event->getOldObject());
		}

	}//end handle()

	/**
	 * Refuse the save when it writes a new licence the list does not hold.
	 *
	 * @param ObjectCreatingEvent|ObjectUpdatingEvent $event The pre-save event.
	 * @param ObjectEntity $new The object as it would be written.
	 * @param ObjectEntity|null $old The object as stored.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/dcat-catalogue-completion/specs/dcat-ap-harvest/spec.md#requirement-the-licence-comes-from-a-controlled-list-bind-or-omit-req-dcc-001
	 */
	private function check(ObjectCreatingEvent|ObjectUpdatingEvent $event, ObjectEntity $new, ?ObjectEntity $old): void {
		$field = $this->fieldOf(entity: $new);
		if ($field === null) {
			return;
		}

		$value = (($new->getObject() ?? [])[$field] ?? null);
		if ($value === null || $value === '') {
			return;
		}

		if ($old !== null && (($old->getObject() ?? [])[$field] ?? null) === $value) {
			return;
		}

		if (is_string($value) === true && $this->vocabulary->resolveLicence(value: $value) !== null) {
			return;
		}

		$event->setErrors(
			[
				'message' => $this->l10n->t('Choose a licence from the EU licences authority list.'),
				'property' => $field,
			]
		);
		$event->stopPropagation();

	}//end check()

	/**
	 * The licence property of the object's schema, or null when it is neither a catalogue nor a publication.
	 *
	 * @param ObjectEntity $entity The object as it would be written.
	 *
	 * @return string|null The property name.
	 *
	 * @spec openspec/changes/dcat-catalogue-completion/specs/dcat-ap-harvest/spec.md#requirement-the-licence-comes-from-a-controlled-list-bind-or-omit-req-dcc-001
	 */
	private function fieldOf(ObjectEntity $entity): ?string {
		$schema = (string)$entity->getSchema();
		if ($schema === '') {
			return null;
		}

		foreach (self::FIELDS as $key => $field) {
			if ($schema === trim($this->config->getValueString('opencatalogi', $key, ''))) {
				return $field;
			}
		}

		return null;

	}//end fieldOf()
}//end class
