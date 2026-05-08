<?php
/**
 * OpenCatalogi Catalog Cache Event Listener.
 *
 * This file contains the listener class for handling catalog object events from OpenRegister
 * to manage cache invalidation and warmup.
 *
 * @category Listener
 * @package  OCA\OpenCatalogi\Listener
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenCatalogi.nl
 */

namespace OCA\OpenCatalogi\Listener;

use OCA\OpenRegister\Event\ObjectCreatedEvent;
use OCA\OpenRegister\Event\ObjectUpdatedEvent;
use OCA\OpenRegister\Event\ObjectDeletedEvent;
use OCA\OpenCatalogi\Service\CatalogiService;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\IAppConfig;
use Psr\Log\LoggerInterface;

/**
 * Event listener for catalog object events from OpenRegister.
 *
 * Listens to object creation, update, and deletion events and manages
 * the catalog cache accordingly through invalidation and warmup operations.
 *
 * @template-implements IEventListener<Event>
 */
class CatalogCacheEventListener implements IEventListener
{
    /**
     * CatalogCacheEventListener constructor.
     */
    public function __construct()
    {

    }//end __construct()

    /**
     * Extract the object entity from the event based on event type.
     *
     * @param Event $event The event object.
     *
     * @return object|null The object entity or null if event type is unsupported.
     *
     * @psalm-suppress TypeDoesNotContainType — OpenRegister events extend Event
     */
    private function extractObjectFromEvent(Event $event): ?object
    {
        if ($event instanceof ObjectCreatedEvent) {
            return $event->getObject();
        }

        if ($event instanceof ObjectUpdatedEvent) {
            return $event->getNewObject();
        }

        if ($event instanceof ObjectDeletedEvent) {
            return $event->getObject();
        }

        return null;

    }//end extractObjectFromEvent()

    /**
     * Handle the event when a catalog object is created, updated, or deleted.
     *
     * This method checks if the event relates to a catalog object and performs
     * appropriate cache operations (invalidation and warmup).
     *
     * @param Event $event The event object containing the ObjectEntity.
     *
     * @return void
     *
     * @SuppressWarnings(PHPMD.CyclomaticComplexity)
     * @SuppressWarnings(PHPMD.NPathComplexity)
     */
    public function handle(Event $event): void
    {
        // Verify this is a supported event type.
        if ($event instanceof ObjectCreatedEvent === false
            && $event instanceof ObjectUpdatedEvent === false
            && $event instanceof ObjectDeletedEvent === false
        ) {
            return;
        }

        try {
            // Get services from the server container.
            $catalogiService = \OC::$server->get(
                \OCA\OpenCatalogi\Service\CatalogiService::class
            );
            $appConfig       = \OC::$server->get(\OCP\IAppConfig::class);
            $logger          = \OC::$server->get(\Psr\Log\LoggerInterface::class);

            // Get the object from the event based on event type.
            $objectEntity = $this->extractObjectFromEvent($event);
            if ($objectEntity === null) {
                return;
            }

            // Get catalog schema and register from config.
            $catalogSchema   = $appConfig->getValueString(
                app: 'opencatalogi',
                key: 'catalog_schema',
                default: ''
            );
            $catalogRegister = $appConfig->getValueString(
                app: 'opencatalogi',
                key: 'catalog_register',
                default: ''
            );

            // Only process if this is a catalog object.
            if ($objectEntity->getSchema() !== $catalogSchema
                || $objectEntity->getRegister() !== $catalogRegister
            ) {
                return;
            }

            // Get catalog data.
            $catalogData = $objectEntity->jsonSerialize();

            // Handle cache based on event type.
            if (isset($catalogData['slug']) === false) {
                return;
            }

            if ($event instanceof ObjectDeletedEvent) {
                // For deletion, only invalidate cache.
                $catalogiService->invalidateCatalogCache($catalogData['slug']);
                $logger->info(
                    message: 'OpenCatalogi: Catalog cache invalidated after deletion',
                    context: [
                        'catalogId' => $objectEntity->getUuid(),
                        'slug'      => $catalogData['slug'],
                    ]
                );
                return;
            }

            // For creation and updates, invalidate and warm up cache.
            $catalogiService->warmupCatalogCache($catalogData['slug']);
            $eventType = 'update';
            if ($event instanceof ObjectCreatedEvent) {
                $eventType = 'creation';
            }

            $logger->info(
                message: 'OpenCatalogi: Catalog cache warmed up after '.$eventType,
                context: [
                    'catalogId' => $objectEntity->getUuid(),
                    'slug'      => $catalogData['slug'],
                ]
            );
        } catch (\Exception $e) {
            // Log unexpected errors and continue gracefully.
            if (isset($logger) === false) {
                $logger = \OC::$server->get(\Psr\Log\LoggerInterface::class);
            }

            $logger->error(
                message: 'OpenCatalogi: Exception in catalog cache event listener: '.$e->getMessage(),
                context: ['exception' => $e]
            );
        }//end try

    }//end handle()
}//end class
