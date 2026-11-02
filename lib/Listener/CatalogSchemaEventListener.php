<?php

/**
 * OpenCatalogi Catalog Schema Event Listener.
 *
 * This file contains the listener class that normalises the `registers` and `schemas`
 * fields of catalog objects from slugs/uuids into integer ids before the object is
 * persisted by OpenRegister.
 *
 * @category Listener
 * @package  OCA\OpenCatalogi\Listener
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2024 Conduction B.V. <info@conduction.nl>
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenCatalogi.nl
 *
 * @spec openspec/specs/catalogs/spec.md
 */

namespace OCA\OpenCatalogi\Listener;

use OCA\OpenCatalogi\Contract\CatalogiServiceInterface;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Event\ObjectCreatingEvent;
use OCA\OpenRegister\Event\ObjectUpdatingEvent;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\IAppConfig;
use Psr\Log\LoggerInterface;

/**
 * Pre-save listener for catalog object normalisation.
 *
 * Subscribes to `ObjectCreatingEvent` and `ObjectUpdatingEvent` so that slug-to-id
 * rewriting of the `registers` and `schemas` fields runs **before** the entity is
 * persisted. The rewritten values are pushed back to the in-flight save via
 * `$event->setModifiedData(...)`, which OpenRegister's `MagicMapper` merges into the
 * single write that triggered the event. This avoids the second save (and second
 * `ObjectUpdatedEvent`) that previously caused an infinite event loop.
 *
 * @template-implements IEventListener<Event>
 */
class CatalogSchemaEventListener implements IEventListener {
	/**
	 * Constructor.
	 *
	 * @param CatalogiServiceInterface $catalogiService Service used to compute rewritten registers/schemas.
	 * @param LoggerInterface $logger Logger for event-handling diagnostics.
	 * @param IAppConfig $appConfig App configuration for catalog schema/register routing.
	 */
	public function __construct(
		private readonly CatalogiServiceInterface $catalogiService,
		private readonly LoggerInterface $logger,
		private readonly IAppConfig $appConfig,
	) {
	}//end __construct()

	/**
	 * Handle a pre-save event for a catalog object.
	 *
	 * @param Event $event The event being dispatched.
	 *
	 * @return void
	 *
	 * @psalm-suppress InvalidArgument OpenRegister events extend OCP Event.
	 *
	 * @spec openspec/specs/catalogs/spec.md
	 */
	public function handle(Event $event): void {
		if ($event instanceof ObjectCreatingEvent === false
			&& $event instanceof ObjectUpdatingEvent === false
		) {
			return;
		}

		try {
			$modified = $this->catalogRewrites(event: $event);
			if ($modified === []) {
				return;
			}

			// Merge with any previously-set modifications from other listeners on the
			// same event, then publish the combined payload back to the event so the
			// mapper picks it up before the row is written.
			$event->setModifiedData(array_merge($event->getModifiedData(), $modified));
		} catch (\Exception $e) {
			// Pre-save listeners must NOT block the user's save: log and let
			// the original (un-rewritten) payload flow through.
			$this->logger->error(
				'OpenCatalogi: Exception in catalog schema event listener: ' . $e->getMessage(),
				['exception' => $e]
			);
		}//end try

	}//end handle()

	/**
	 * The rewritten registers and schemas of the catalog an event saves.
	 *
	 * @param Event $event The pre-save event.
	 *
	 * @return array<string, mixed> The keys to rewrite, or none when the object is no catalog.
	 */
	private function catalogRewrites(Event $event): array {
		$objectEntity = $this->getEntityFromEvent(event: $event);
		if ($objectEntity === null || $this->isCatalog(objectEntity: $objectEntity) === false) {
			return [];
		}

		return $this->catalogiService->computeRewrittenRegistersAndSchemas($objectEntity->getObject() ?? []);

	}//end catalogRewrites()

	/**
	 * Whether an object lives in the configured catalog schema and register.
	 *
	 * @param ObjectEntity $objectEntity The object.
	 *
	 * @return bool
	 */
	private function isCatalog(ObjectEntity $objectEntity): bool {
		$catalogSchema = $this->appConfig->getValueString('opencatalogi', 'catalog_schema', '');
		$catalogRegister = $this->appConfig->getValueString('opencatalogi', 'catalog_register', '');

		return $objectEntity->getSchema() === $catalogSchema
			&& $objectEntity->getRegister() === $catalogRegister;

	}//end isCatalog()

	/**
	 * Pull the catalog entity out of either supported pre-save event.
	 *
	 * @param Event $event The dispatched pre-save event.
	 *
	 * @return ObjectEntity|null The catalog entity or null when the event is unsupported.
	 *
	 * @psalm-suppress TypeDoesNotContainType OpenRegister events extend OCP Event.
	 *
	 * @spec openspec/specs/catalogs/spec.md
	 */
	private function getEntityFromEvent(Event $event): ?ObjectEntity {
		if ($event instanceof ObjectCreatingEvent) {
			return $event->getObject();
		}

		if ($event instanceof ObjectUpdatingEvent) {
			return $event->getNewObject();
		}

		return null;
	}//end getEntityFromEvent()
}//end class
