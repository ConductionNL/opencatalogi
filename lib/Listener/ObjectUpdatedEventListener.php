<?php

/**
 * OpenCatalogi Object Updated Event Listener.
 *
 * Handles OR ObjectUpdatedEvent and triggers the OpenCatalogi-specific
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
 * @spec openspec/specs/auto-publishing/spec.md
 * @spec openspec/changes/migrate-activity-to-activity-leaf/tasks.md#task-4
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

namespace OCA\OpenCatalogi\Listener;

use OCA\OpenCatalogi\Service\EventService;
use OCA\OpenCatalogi\Service\RetentionService;
use OCA\OpenRegister\Event\ObjectUpdatedEvent;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use Psr\Log\LoggerInterface;

/**
 * Event listener for object update events from OpenRegister.
 *
 * Listens to ObjectUpdatedEvent and applies the auto-publishing side effect
 * based on OpenCatalogi configuration settings. Scope is limited to the
 * auto-publishing side effect only (catalogue-membership + WOO publishing
 * policy); the activity feed is consumed from the OR activity leaf per
 * ADR-022 / APB-ACT-001 and NOT reimplemented here.
 *
 * @template-implements IEventListener<Event>
 *
 * @spec openspec/changes/migrate-activity-to-activity-leaf/tasks.md#task-4
 */
class ObjectUpdatedEventListener implements IEventListener {
	/**
	 * ObjectUpdatedEventListener constructor.
	 *
	 * @param RetentionService $retentionService Stamps retention defaults at publication time (RET-004).
	 * @param LoggerInterface $logger Logger for the retention side effect.
	 */
	public function __construct(
		private readonly RetentionService $retentionService,
		private readonly LoggerInterface $logger,
	) {

	}//end __construct()

	/**
	 * Handle the event when an object is updated.
	 *
	 * This method checks if auto-publishing features are enabled and processes
	 * the updated object accordingly.
	 *
	 * @param Event $event The event object containing the updated ObjectEntity.
	 *
	 * @return void
	 *
	 * @SuppressWarnings(PHPMD.CyclomaticComplexity)
	 *
	 * @spec openspec/specs/auto-publishing/spec.md
	 * @spec openspec/changes/migrate-activity-to-activity-leaf/tasks.md#task-4
	 */
	public function handle(Event $event): void {
		// Verify this is the correct event type.
		if ($event instanceof ObjectUpdatedEvent === false) {
			return;
		}

		// Retention defaults are stamped on the unpublished -> published
		// transition (RET-004), independent of the auto-publishing options below.
		$this->stampRetentionDefaults(event: $event);

		try {
			// Get services from the server container.
			$logger = \OC::$server->get(\Psr\Log\LoggerInterface::class);
			$settingsService = \OC::$server->get(
				\OCA\OpenCatalogi\Service\SettingsService::class
			);
			$eventService = \OC::$server->get(
				\OCA\OpenCatalogi\Service\EventService::class
			);

			// Check if any auto-publishing features are enabled.
			$publishingOptions = $settingsService->getPublishingOptions();

			// Skip if no auto-publishing features are enabled.
			if ($publishingOptions['auto_publish_objects'] === false
				&& $publishingOptions['auto_publish_attachments'] === false
			) {
				return;
			}

			// Get the updated object from the event.
			$newObjectEntity = $event->getNewObject();
			$oldObjectEntity = $event->getOldObject();

			// Convert ObjectEntity to array format expected by EventService.
			$newObjectData = $this->convertObjectEntityToArray(objectEntity: $newObjectEntity);

			// Check if this update should trigger auto-publishing logic.
			if ($this->shouldProcessUpdate(
				newObjectData: $newObjectData,
				oldObjectEntity: $oldObjectEntity,
				publishingOptions: $publishingOptions
			) === false
			) {
				return;
			}

			// Process the object update event through EventService.
			$result = $eventService->handleObjectUpdateEvents([$newObjectData]);

			// Log successful processing for monitoring.
			if ($result['processed'] > 0) {
				$logger->info(
					message: 'OpenCatalogi: Processed object update event',
					context: [
						'objectId' => ($newObjectData['@self']['id'] ?? 'unknown'),
						'published' => $result['published'],
						'attachmentsPublished' => $result['attachmentsPublished'],
					]
				);
			}

			// Log any errors that occurred during processing.
			if (empty($result['errors']) === false) {
				foreach ($result['errors'] as $error) {
					$logger->error(
						message: 'OpenCatalogi: Error processing object update event',
						context: [
							'error' => $error,
							'objectId' => ($newObjectData['@self']['id'] ?? 'unknown'),
						]
					);
				}
			}
		} catch (\Throwable $e) {
			// Log unexpected errors and continue gracefully. \Throwable, not
			// \Exception: a missing class or type error is a PHP Error, and an
			// uncaught one here would abort the OR save pipeline.
			$this->logger->error(
				message: 'OpenCatalogi: Exception in object update event listener: ' . $e->getMessage(),
				context: ['exception' => $e]
			);
		}//end try

	}//end handle()

	/**
	 * Stamp retention defaults when an object first becomes published (RET-004).
	 *
	 * The transition check (was unpublished, is now published) lives here; the
	 * register/schema guard, the fill-empties-only rule and the no-change-no-save
	 * idempotency live in {@see RetentionService::applyDefaultsAtPublication()}.
	 * Failures are logged and swallowed: retention stamping must never block the
	 * OpenRegister save pipeline.
	 *
	 * @param ObjectUpdatedEvent $event The OR object update event.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/publication-retention-lifecycle/spec.md#requirement-per-catalog-retention-defaults-per-woo-information-category-ret-004
	 */
	private function stampRetentionDefaults(ObjectUpdatedEvent $event): void {
		try {
			$oldObjectEntity = $event->getOldObject();
			$newObjectData = $this->convertObjectEntityToArray(objectEntity: $event->getNewObject());

			$wasPublished = false;
			if ($oldObjectEntity !== null) {
				$wasPublished = $this->isObjectEntityPublished(objectEntity: $oldObjectEntity);
			}

			if ($wasPublished === false && $this->isObjectPublished(objectData: $newObjectData) === true) {
				$this->retentionService->applyDefaultsAtPublication(objectData: $newObjectData);
			}
		} catch (\Throwable $e) {
			$this->logger->error(
				message: 'OpenCatalogi: failed to stamp retention defaults at publication: ' . $e->getMessage(),
				context: ['exception' => $e]
			);
		}

	}//end stampRetentionDefaults()

	/**
	 * Determine if an object update should trigger auto-publishing logic.
	 *
	 * @param array $newObjectData The updated object data.
	 * @param \OCA\OpenRegister\Db\ObjectEntity|null $oldObjectEntity The original object entity.
	 * @param array $publishingOptions The publishing configuration.
	 *
	 * @return boolean True if the update should be processed, false otherwise.
	 *
	 * @spec openspec/specs/auto-publishing/spec.md
	 */
	private function shouldProcessUpdate(
		array $newObjectData,
		?\OCA\OpenRegister\Db\ObjectEntity $oldObjectEntity,
		array $publishingOptions,
	): bool {
		// If auto-publish attachments is enabled, always process for published objects.
		if ($publishingOptions['auto_publish_attachments'] === true) {
			$isNewObjectPublished = $this->isObjectPublished(objectData: $newObjectData);
			if ($isNewObjectPublished === true) {
				return true;
			}
		}

		// If auto-publish objects is not enabled, no further processing needed.
		if ($publishingOptions['auto_publish_objects'] === false) {
			return false;
		}

		// Check if publication status changed from unpublished to published.
		$wasPublished = false;
		if ($oldObjectEntity !== null) {
			$wasPublished = $this->isObjectEntityPublished(objectEntity: $oldObjectEntity);
		}

		$isPublished = $this->isObjectPublished(objectData: $newObjectData);

		// Process if object became published.
		if ($wasPublished === false && $isPublished === true) {
			return true;
		}

		return false;
	}//end shouldProcessUpdate()

	/**
	 * Check if an ObjectEntity is currently published.
	 *
	 * @param \OCA\OpenRegister\Db\ObjectEntity $objectEntity The object entity to check.
	 *
	 * @return boolean True if the object is published, false otherwise.
	 *
	 * @spec openspec/specs/auto-publishing/spec.md
	 */
	private function isObjectEntityPublished(\OCA\OpenRegister\Db\ObjectEntity $objectEntity): bool {
		// Visibility is governed by the object's own publicationDate/depublicationDate
		// fields under the live OpenRegister RBAC model (APB-006); the removed
		// object-level @self.published getters no longer exist.
		$objectData = $objectEntity->jsonSerialize();
		if (is_array($objectData) === false) {
			return false;
		}

		return $this->isObjectPublished(objectData: $objectData);
	}//end isObjectEntityPublished()

	/**
	 * Check if an object data array represents a published object.
	 *
	 * @param array $objectData The object data to check.
	 *
	 * @return boolean True if the object is published, false otherwise.
	 *
	 * @spec openspec/specs/auto-publishing/spec.md
	 */
	private function isObjectPublished(array $objectData): bool {
		// Live OpenRegister RBAC visibility model (APB-006): published iff the
		// object's own publicationDate is set and reached, and any
		// depublicationDate is still in the future. The removed object-level
		// @self.published predicate is no longer consulted.
		$publicationDate = ($objectData['publicationDate'] ?? null);
		$depublicationDate = ($objectData['depublicationDate'] ?? null);

		if ($publicationDate === null || $publicationDate === '') {
			return false;
		}

		$now = time();
		$publishedTime = strtotime((string)$publicationDate);
		if ($publishedTime === false || $publishedTime > $now) {
			return false;
		}

		if ($depublicationDate === null || $depublicationDate === '') {
			return true;
		}

		$depublishedTime = strtotime((string)$depublicationDate);
		return ($depublishedTime === false || $depublishedTime > $now);
	}//end isObjectPublished()

	/**
	 * Convert ObjectEntity to array format for EventService.
	 *
	 * @param \OCA\OpenRegister\Db\ObjectEntity $objectEntity The object entity to convert.
	 *
	 * @return array The object data in array format.
	 *
	 * @spec openspec/specs/auto-publishing/spec.md
	 */
	private function convertObjectEntityToArray(\OCA\OpenRegister\Db\ObjectEntity $objectEntity): array {
		// Use the ObjectEntity's jsonSerialize method to get array representation.
		$objectData = $objectEntity->jsonSerialize();

		// Ensure the @self metadata is properly structured.
		if (isset($objectData['@self']) === false) {
			$objectData['@self'] = [];
		}

		// Add essential metadata for event processing.
		$objectData['@self']['id'] = $objectEntity->getUuid();
		$objectData['@self']['uuid'] = $objectEntity->getUuid();
		$objectData['@self']['register'] = $objectEntity->getRegister();
		$objectData['@self']['schema'] = $objectEntity->getSchema();
		// Visibility is governed by the object's own publicationDate/depublicationDate
		// fields (already present via jsonSerialize) under the live OpenRegister RBAC
		// model (APB-006). The removed object-level @self.published is no longer set.
		// Don't fetch files to avoid infinite recursion.
		$objectData['@self']['files'] = [];

		return $objectData;
	}//end convertObjectEntityToArray()
}//end class
