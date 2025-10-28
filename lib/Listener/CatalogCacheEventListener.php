<?php
/**
 * OpenCatalogi Catalog Cache Event Listener
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
     * Handle the event when a catalog object is created, updated, or deleted.
     *
     * This method checks if the event relates to a catalog object and performs
     * appropriate cache operations (invalidation and warmup).
     *
     * @param Event $event The event object containing the ObjectEntity.
     *
     * @return void
     */
    public function handle(Event $event): void
    {
        // Verify this is a supported event type
        if ($event instanceof ObjectCreatedEvent === false
            && $event instanceof ObjectUpdatedEvent === false
            && $event instanceof ObjectDeletedEvent === false
        ) {
            return;
        }

        try {
            // Get services from the server container
            $catalogiService = \OC::$server->get(\OCA\OpenCatalogi\Service\CatalogiService::class);
            $appConfig = \OC::$server->get(\OCP\IAppConfig::class);
            $logger = \OC::$server->get(\Psr\Log\LoggerInterface::class);

            // Get the object from the event (different methods for different event types)
            if ($event instanceof ObjectCreatedEvent) {
                $objectEntity = $event->getObject();
            } elseif ($event instanceof ObjectUpdatedEvent) {
                $objectEntity = $event->getNewObject();
            } elseif ($event instanceof ObjectDeletedEvent) {
                $objectEntity = $event->getObject();
            } else {
                return;
            }

            // Get catalog schema and register from config
            $catalogSchema = $appConfig->getValueString('opencatalogi', 'catalog_schema', '');
            $catalogRegister = $appConfig->getValueString('opencatalogi', 'catalog_register', '');

            // Only process if this is a catalog object
            if ($objectEntity->getSchema() !== $catalogSchema || $objectEntity->getRegister() !== $catalogRegister) {
                return;
            }

            // Get catalog data
            $catalogData = $objectEntity->jsonSerialize();

            // Handle cache based on event type
            if ($event instanceof ObjectDeletedEvent) {
                // For deletion, only invalidate cache
                if (isset($catalogData['slug']) === true) {
                    $catalogiService->invalidateCatalogCache($catalogData['slug']);
                    $logger->info('OpenCatalogi: Catalog cache invalidated after deletion', [
                        'catalogId' => $objectEntity->getUuid(),
                        'slug' => $catalogData['slug'],
                    ]);
                }
            } else {
                // For creation and updates, invalidate and warm up cache
                if (isset($catalogData['slug']) === true) {
                    $catalogiService->warmupCatalogCache($catalogData['slug']);
                    $logger->info('OpenCatalogi: Catalog cache warmed up after ' . ($event instanceof ObjectCreatedEvent ? 'creation' : 'update'), [
                        'catalogId' => $objectEntity->getUuid(),
                        'slug' => $catalogData['slug'],
                    ]);
                }
            }
        } catch (\Exception $e) {
            // Log unexpected errors and continue gracefully
            // Get logger if not already available
            if (!isset($logger)) {
                $logger = \OC::$server->get(\Psr\Log\LoggerInterface::class);
            }
            $logger->error('OpenCatalogi: Exception in catalog cache event listener: ' . $e->getMessage(), ['exception' => $e]);
        }

    }//end handle()


}//end class

