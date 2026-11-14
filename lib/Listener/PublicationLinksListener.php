<?php

/**
 * OpenCatalogi Publication Links Listener.
 *
 * Before a publication is written: refuse a relation that points the
 * publication at itself, and mirror its case reference into its source
 * identifiers.
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
 * @spec openspec/changes/publication-relations-place-and-source-ids/specs/publications/spec.md#requirement-a-publication-names-its-relation-to-another-publication-req-prs-001
 */

declare(strict_types=1);

namespace OCA\OpenCatalogi\Listener;

use OCA\OpenCatalogi\Service\Publication\PublicationLinkRules;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Event\ObjectCreatingEvent;
use OCA\OpenRegister\Event\ObjectUpdatingEvent;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\IAppConfig;

/**
 * Pre-save checks on a publication's relations and source identifiers.
 *
 * Only objects of the configured publication register and schema are
 * touched. A self-relation stops the save with an error naming the property;
 * a case reference is added to `sourceIdentifiers` through the event's
 * modified data, so no second save is made.
 *
 * @template-implements IEventListener<Event>
 *
 * @spec openspec/changes/publication-relations-place-and-source-ids/specs/publications/spec.md#requirement-a-publication-names-its-relation-to-another-publication-req-prs-001
 */
class PublicationLinksListener implements IEventListener {

	/**
	 * Constructor.
	 *
	 * @param IAppConfig $config App configuration naming the publication register and schema.
	 */
	public function __construct(
		private readonly IAppConfig $config,
	) {

	}//end __construct()

	/**
	 * Check and complete a publication before it is written.
	 *
	 * @param Event $event The pre-save event.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/publication-relations-place-and-source-ids/specs/publications/spec.md#requirement-a-publication-names-its-relation-to-another-publication-req-prs-001
	 */
	public function handle(Event $event): void {
		if ($event instanceof ObjectCreatingEvent === true) {
			$this->check(event: $event, object: $event->getObject());
			return;
		}

		if ($event instanceof ObjectUpdatingEvent === true) {
			$this->check(event: $event, object: $event->getNewObject());
		}

	}//end handle()

	/**
	 * Refuse a self-relation, else mirror the case reference.
	 *
	 * @param ObjectCreatingEvent|ObjectUpdatingEvent $event The pre-save event.
	 * @param ObjectEntity $object The object about to be written.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/publication-relations-place-and-source-ids/specs/publications/spec.md#requirement-a-publication-carries-its-source-identifiers-searchable-req-prs-003
	 */
	private function check(ObjectCreatingEvent|ObjectUpdatingEvent $event, ObjectEntity $object): void {
		if ($this->isPublication(object: $object) === false) {
			return;
		}

		$data = array_merge(($object->getObject() ?? []), $event->getModifiedData());
		$property = (new PublicationLinkRules())->selfRelation(data: $data, publicationId: (string)($object->getUuid() ?? ''));
		if ($property !== null) {
			$event->setErrors(['message' => sprintf('A publication cannot relate to itself: remove it from "%s".', $property), 'property' => $property]);
			$event->stopPropagation();
			return;
		}

		$identifiers = (new PublicationLinkRules())->withMirroredCaseReference(data: $data);
		if ($identifiers !== null) {
			$event->setModifiedData(array_merge($event->getModifiedData(), ['sourceIdentifiers' => $identifiers]));
		}

	}//end check()

	/**
	 * Whether the object is a publication of the configured register and schema.
	 *
	 * @param ObjectEntity $object The object.
	 *
	 * @return bool True for a publication.
	 */
	private function isPublication(ObjectEntity $object): bool {
		$schema = $this->config->getValueString('opencatalogi', 'publication_schema', '');
		$register = $this->config->getValueString('opencatalogi', 'publication_register', '');
		if ($schema === '' || $register === '') {
			return false;
		}

		return (string)$object->getSchema() === $schema && (string)$object->getRegister() === $register;
	}//end isPublication()
}//end class
