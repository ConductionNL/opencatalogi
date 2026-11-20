<?php

/**
 * OpenCatalogi Rule Category Listener.
 *
 * Before OpenRegister writes a record, writes on it the Woo information
 * category of the published collection that publishes it, so the record
 * lands in that category's sitemap (REQ-CVS-003), the same category its
 * anonymous view shows. A configuration or condition that cannot be read
 * leaves the record as it was: filing is never a reason to refuse a save.
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
 * @spec openspec/changes/capability-rows-verify-scenarios/specs/woo-compliance/spec.md#requirement-a-publication-rule-names-the-information-category-its-records-are-filed-under-req-cvs-003
 */

declare(strict_types=1);

namespace OCA\OpenCatalogi\Listener;

use OCA\OpenCatalogi\Service\Publication\RuleCategoryStamp;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Event\ObjectCreatingEvent;
use OCA\OpenRegister\Event\ObjectUpdatingEvent;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Files a record a published collection publishes under that collection's category.
 *
 * @spec openspec/changes/capability-rows-verify-scenarios/specs/woo-compliance/spec.md#requirement-a-publication-rule-names-the-information-category-its-records-are-filed-under-req-cvs-003
 *
 * @template-implements IEventListener<Event>
 */
class RuleCategoryListener implements IEventListener {

	/**
	 * Constructor.
	 *
	 * @param RuleCategoryStamp $stamp Decides the category.
	 * @param ContainerInterface $container Resolves OpenRegister's SchemaMapper for a schema's slug.
	 * @param LoggerInterface $logger Records a configuration that could not be read.
	 *
	 * @spec exclude pure framework plumbing: constructor dependency injection.
	 */
	public function __construct(
		private readonly RuleCategoryStamp $stamp,
		private readonly ContainerInterface $container,
		private readonly LoggerInterface $logger,
	) {

	}//end __construct()

	/**
	 * Write the category on the record before it is saved.
	 *
	 * @param Event $event The OpenRegister pre-save event.
	 *
	 * @return void
	 *
	 * @psalm-suppress InvalidArgument OpenRegister events extend OCP Event.
	 *
	 * @spec openspec/changes/capability-rows-verify-scenarios/specs/woo-compliance/spec.md#requirement-a-publication-rule-names-the-information-category-its-records-are-filed-under-req-cvs-003
	 */
	public function handle(Event $event): void {
		if ($event instanceof ObjectCreatingEvent === true) {
			$this->stamp(event: $event, object: $event->getObject());
			return;
		}

		if ($event instanceof ObjectUpdatingEvent === true) {
			$this->stamp(event: $event, object: $event->getNewObject());
		}

	}//end handle()

	/**
	 * Add the category to the data the save writes, when a collection files the record.
	 *
	 * @param ObjectCreatingEvent|ObjectUpdatingEvent $event The pre-save event.
	 * @param ObjectEntity $object The object as it would be written.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/capability-rows-verify-scenarios/specs/woo-compliance/spec.md#requirement-a-publication-rule-names-the-information-category-its-records-are-filed-under-req-cvs-003
	 */
	private function stamp(ObjectCreatingEvent|ObjectUpdatingEvent $event, ObjectEntity $object): void {
		try {
			$types = $this->stamp->recordTypes();
			if ($types === []) {
				return;
			}

			$typeNames = $this->typeNames(object: $object, types: $types);
			if ($typeNames === []) {
				return;
			}

			$record = array_merge((array)$object->getObject(), $event->getModifiedData());
			$category = $this->stamp->categoryFor(record: $record, typeNames: $typeNames);
		} catch (\Throwable $e) {
			$this->logger->warning(
				'[RuleCategoryListener] The publication rules could not be read; the record is saved without a category from them: '
				. $e->getMessage()
			);
			return;
		}

		if ($category === null) {
			return;
		}

		$event->setModifiedData(array_merge($event->getModifiedData(), ['wooCategory' => $category]));

	}//end stamp()

	/**
	 * The names the object's schema goes by that a collection governs: its id, else its slug.
	 *
	 * The slug is only looked up when the id is not a governed type.
	 *
	 * @param ObjectEntity $object The object.
	 * @param array<int, string> $types The governed record types.
	 *
	 * @return array<int, string> The matching names; empty when no collection governs the schema.
	 */
	private function typeNames(ObjectEntity $object, array $types): array {
		$schemaId = trim((string)$object->getSchema());
		if ($schemaId === '') {
			return [];
		}

		if (in_array($schemaId, $types, true) === true) {
			return [$schemaId];
		}

		$schema = $this->container->get('OCA\OpenRegister\Db\SchemaMapper')->find($schemaId);
		$slug = trim((string)($schema?->getSlug() ?? ''));
		if ($slug === '' || in_array($slug, $types, true) === false) {
			return [];
		}

		return [$slug];

	}//end typeNames()
}//end class
