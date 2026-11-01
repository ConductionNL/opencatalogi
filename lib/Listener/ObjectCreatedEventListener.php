<?php

/**
 * OpenCatalogi Object Created Event Listener.
 *
 * Handles OR ObjectCreatedEvent and triggers the OpenCatalogi-specific
 * auto-publishing side effect only (catalogue-membership + WOO publishing
 * policy). The per-publication activity feed is consumed from the OR activity
 * leaf (ADR-022 / APB-ACT-001), not reimplemented here. See
 * openspec/changes/migrate-activity-to-activity-leaf/design.md for the
 * keep/migrate split rationale.
 *
 * @category Listener
 * @package  OCA\OpenCatalogi\Listener
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenCatalogi.nl
 *
 * @spec openspec/specs/auto-publishing/spec.md
 * @spec openspec/specs/auto-publishing/spec.md
 * @spec openspec/changes/migrate-activity-to-activity-leaf/tasks.md#task-3
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

namespace OCA\OpenCatalogi\Listener;

use OCA\OpenCatalogi\Contract\EventServiceInterface;
use OCA\OpenCatalogi\Contract\RetentionServiceInterface;
use OCA\OpenCatalogi\Contract\SettingsServiceInterface;
use OCA\OpenRegister\Event\ObjectCreatedEvent;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use Psr\Log\LoggerInterface;

/**
 * Event listener for object creation events from OpenRegister.
 *
 * Listens to ObjectCreatedEvent and applies the auto-publishing side effect
 * based on OpenCatalogi configuration settings. Scope is limited to the
 * auto-publishing side effect only (catalogue-membership + WOO publishing
 * policy); the activity feed is consumed from the OR activity leaf per
 * ADR-022 / APB-ACT-001 and NOT reimplemented here.
 *
 * @template-implements IEventListener<Event>
 *
 * @spec openspec/changes/migrate-activity-to-activity-leaf/tasks.md#task-3
 */
class ObjectCreatedEventListener implements IEventListener {
	use ReportsAutoPublishOutcome;
	/**
	 * ObjectCreatedEventListener constructor.
	 *
	 * The auto-publishing collaborators are injected rather than looked up on
	 * the global server at handle time: the listener is only built when an
	 * object event fires, so nothing here runs at boot, and a unit test can
	 * hand in its own doubles.
	 *
	 * @param RetentionServiceInterface $retentionService Stamps retention defaults at publication time (RET-004).
	 * @param SettingsServiceInterface $settingsService Answers which auto-publishing options are on.
	 * @param EventServiceInterface $eventService Applies the auto-publishing side effect.
	 * @param LoggerInterface $logger Logger for both side effects.
	 */
	public function __construct(
		private readonly RetentionServiceInterface $retentionService,
		private readonly SettingsServiceInterface $settingsService,
		private readonly EventServiceInterface $eventService,
		private readonly LoggerInterface $logger,
	) {

	}//end __construct()

	/**
	 * Handle the event when an object is created.
	 *
	 * This method checks if auto-publishing features are enabled and processes
	 * the created object accordingly.
	 *
	 * @param Event $event The event object containing the created ObjectEntity.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/auto-publishing/spec.md
	 * @spec openspec/changes/migrate-activity-to-activity-leaf/tasks.md#task-3
	 */
	public function handle(Event $event): void {

		// Verify this is the correct event type.
		if ($event instanceof ObjectCreatedEvent === false) {
			return;
		}

		// An object created with its publicationDate already set is published from
		// birth: stamp retention defaults now (RET-004), independent of the
		// auto-publishing options below.
		$this->stampRetentionDefaults(event: $event);

		try {
			// Check if any auto-publishing features are enabled before processing.
			$publishingOptions = $this->settingsService->getPublishingOptions();

			// Skip processing if no auto-publishing features are enabled.
			if ($publishingOptions['auto_publish_objects'] === false
				&& $publishingOptions['auto_publish_attachments'] === false
			) {
				return;
			}

			// Get the created object from the event.
			$objectEntity = $event->getObject();

			// Convert ObjectEntity to array format expected by EventService.
			$objectData = $this->convertObjectEntityToArray(objectEntity: $objectEntity);

			// Process the object creation event through EventService.
			$result = $this->eventService->handleObjectCreateEvents([$objectData]);

			$this->logAutoPublishOutcome(result: $result, about: ['event' => 'creation', 'objectId' => ($objectData['@self']['id'] ?? 'unknown')]);
		} catch (\Throwable $e) {
			// Log unexpected errors and continue gracefully. \Throwable, not
			// \Exception: a missing class or type error is a PHP Error, and an
			// uncaught one here would abort the OR save pipeline.
			$this->logger->error(
				message: 'OpenCatalogi: Exception in object creation event listener: ' . $e->getMessage(),
				context: ['exception' => $e]
			);
		}//end try

	}//end handle()

	/**
	 * Stamp retention defaults when an object is created already published (RET-004).
	 *
	 * The register/schema guard, the publicationDate requirement, the
	 * fill-empties-only rule and the no-change-no-save idempotency all live in
	 * {@see RetentionService::applyDefaultsAtPublication()}. Failures are logged
	 * and swallowed: retention stamping must never block the OpenRegister save
	 * pipeline.
	 *
	 * @param ObjectCreatedEvent $event The OR object creation event.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/publication-retention-lifecycle/spec.md#requirement-per-catalog-retention-defaults-per-woo-information-category-ret-004
	 */
	private function stampRetentionDefaults(ObjectCreatedEvent $event): void {
		try {
			$objectData = $this->convertObjectEntityToArray(objectEntity: $event->getObject());
			$this->retentionService->applyDefaultsAtPublication(objectData: $objectData);
		} catch (\Throwable $e) {
			$this->logger->error(
				message: 'OpenCatalogi: failed to stamp retention defaults at publication: ' . $e->getMessage(),
				context: ['exception' => $e]
			);
		}

	}//end stampRetentionDefaults()

	/**
	 * Convert ObjectEntity to array format for EventService.
	 *
	 * This method transforms the ObjectEntity from OpenRegister into the array
	 * format expected by our EventService.
	 *
	 * @param \OCA\OpenRegister\Db\ObjectEntity $objectEntity The object entity to convert.
	 *
	 * @return array The object data in array format.
	 *
	 * @spec openspec/specs/auto-publishing/spec.md
	 */
	private function convertObjectEntityToArray(\OCA\OpenRegister\Db\ObjectEntity $objectEntity): array {
		return $this->eventObjectData(objectEntity: $objectEntity);
	}//end convertObjectEntityToArray()
}//end class
