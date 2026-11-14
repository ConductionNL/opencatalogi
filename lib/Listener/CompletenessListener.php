<?php

/**
 * Writes a publication's completeness on every save.
 *
 * `completeness` is read-only to callers: whatever a write sends is replaced
 * by the value OpenCatalogi evaluates. A new publication starts incomplete,
 * because its files are attached after it exists. An update is evaluated;
 * when the evaluation cannot run, the stored value is kept and a warning is
 * logged, so a failed lookup never claims a publication complete
 * (publication-tells-its-source, REQ-PTS-002).
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
 * @spec openspec/changes/publication-tells-its-source/specs/publications/spec.md#requirement-a-publication-records-whether-it-is-complete-and-public-req-pts-002
 */

declare(strict_types=1);

namespace OCA\OpenCatalogi\Listener;

use OCA\OpenCatalogi\Service\Publication\CompletenessService;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Event\ObjectCreatingEvent;
use OCA\OpenRegister\Event\ObjectUpdatingEvent;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\IAppConfig;
use Psr\Log\LoggerInterface;

/**
 * Sets `completeness` and `completeAt` on a publication before it is stored.
 *
 * @template-implements IEventListener<Event>
 *
 * @spec openspec/changes/publication-tells-its-source/specs/publications/spec.md#requirement-a-publication-records-whether-it-is-complete-and-public-req-pts-002
 */
class CompletenessListener implements IEventListener {

	/**
	 * Constructor.
	 *
	 * @param CompletenessService $completeness Evaluates the publication.
	 * @param IAppConfig          $config       Names the publication schema.
	 * @param LoggerInterface     $logger       Records an evaluation that could not run.
	 *
	 * @spec openspec/changes/publication-tells-its-source/specs/publications/spec.md#requirement-a-publication-records-whether-it-is-complete-and-public-req-pts-002
	 */
	public function __construct(
		private readonly CompletenessService $completeness,
		private readonly IAppConfig $config,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Set the completeness of a publication about to be stored.
	 *
	 * @param Event $event The pre-save event.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/publication-tells-its-source/specs/publications/spec.md#requirement-a-publication-records-whether-it-is-complete-and-public-req-pts-002
	 */
	public function handle(Event $event): void {
		if ($event instanceof ObjectCreatingEvent === true) {
			if ($this->isPublication(object: $event->getObject()) === true) {
				// Its files are attached after it exists: a new publication starts incomplete.
				$this->write(event: $event, properties: ['completeness' => CompletenessService::INCOMPLETE]);
			}

			return;
		}

		if ($event instanceof ObjectUpdatingEvent === true && $this->isPublication(object: $event->getNewObject()) === true) {
			$this->evaluate(event: $event);
		}
	}//end handle()

	/**
	 * Evaluate an updated publication, or keep its stored value when that cannot run.
	 *
	 * @param ObjectUpdatingEvent $event The update.
	 *
	 * @return void
	 */
	private function evaluate(ObjectUpdatingEvent $event): void {
		$new         = $event->getNewObject();
		$previous    = $event->getOldObject()?->getObject() ?? [];
		$publication = array_merge((array)$new->getObject(), $event->getModifiedData());

		try {
			$value = $this->completeness->evaluate(publication: $publication, object: $new);
		} catch (\Throwable $e) {
			$this->logger->warning(
				'[CompletenessListener] The completeness of publication ' . (string)$new->getUuid()
				. ' could not be evaluated, so the stored value is kept: ' . $e->getMessage()
			);
			$this->write(event: $event, properties: $this->completeness->storedProperties(previous: (array)$previous));
			return;
		}

		$this->write(event: $event, properties: $this->completeness->propertiesFor(value: $value, previous: (array)$previous));
	}//end evaluate()

	/**
	 * Add properties to the data the event stores, replacing what the caller sent for them.
	 *
	 * @param ObjectCreatingEvent|ObjectUpdatingEvent $event      The pre-save event.
	 * @param array<string, string>                   $properties The properties.
	 *
	 * @return void
	 */
	private function write(ObjectCreatingEvent|ObjectUpdatingEvent $event, array $properties): void {
		$event->setModifiedData(array_merge($event->getModifiedData(), $properties));
	}//end write()

	/**
	 * Whether the object is a publication.
	 *
	 * @param ObjectEntity $object The object.
	 *
	 * @return bool
	 */
	private function isPublication(ObjectEntity $object): bool {
		$schema = $this->config->getValueString('opencatalogi', 'publication_schema', '');

		return $schema !== '' && (string)$object->getSchema() === $schema;
	}//end isPublication()
}//end class
