<?php
/**
 * OpenCatalogi Object Created Event Listener
 *
 * This file contains the listener class for handling object creation events from OpenRegister.
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
use OCA\OpenCatalogi\Service\EventService;
use OCA\OpenCatalogi\Service\SettingsService;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use Psr\Log\LoggerInterface;

/**
 * Event listener for object creation events from OpenRegister.
 *
 * Listens to ObjectCreatedEvent and applies auto-publishing logic
 * based on OpenCatalogi configuration settings.
 */
class ObjectCreatedEventListener implements IEventListener
{

    /**
     * ObjectCreatedEventListener constructor.
     */
    public function __construct() {

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
     */
    public function handle(Event $event): void
    {


        // Verify this is the correct event type.
        if ($event instanceof ObjectCreatedEvent === false) {
            return;
        }

        try {
            // Get services from the server container
            $settingsService = \OC::$server->get(\OCA\OpenCatalogi\Service\SettingsService::class);
            $eventService = \OC::$server->get(\OCA\OpenCatalogi\Service\EventService::class);
            $logger = \OC::$server->get(\Psr\Log\LoggerInterface::class);
            
            // Check if any auto-publishing features are enabled before processing.
            $publishingOptions = $settingsService->getPublishingOptions();
            
            // Skip processing if no auto-publishing features are enabled.
            if ($publishingOptions['auto_publish_objects'] === false && $publishingOptions['auto_publish_attachments'] === false) {
                return;
            }

            // Get the created object from the event.
            $objectEntity = $event->getObject();
            
            // Convert ObjectEntity to array format expected by EventService.
            $objectData = $this->convertObjectEntityToArray($objectEntity);
            
            // Check if this is a catalog object and invalidate cache if needed
            $this->handleCatalogCacheInvalidation($objectData);
            
            // Process the object creation event through EventService.
            $result = $eventService->handleObjectCreateEvents([$objectData]);
            
            // Log successful processing for monitoring.
            if ($result['processed'] > 0) {
                $logger->info('OpenCatalogi: Processed object creation event', [
                    'objectId' => $objectData['@self']['id'] ?? 'unknown',
                    'published' => $result['published'],
                    'attachmentsPublished' => $result['attachmentsPublished']
                ]);
            }
            
            // Log any errors that occurred during processing.
            if (empty($result['errors']) === false) {
                foreach ($result['errors'] as $error) {
                    $logger->error('OpenCatalogi: Error processing object creation event', [
                        'error' => $error,
                        'objectId' => $objectData['@self']['id'] ?? 'unknown'
                    ]);
                }
            }
        } catch (\Exception $e) {
            // Log unexpected errors and continue gracefully.
            error_log('OpenCatalogi: Exception in object creation event listener: ' . $e->getMessage());
        }

    }//end handle()

    /**
     * Handles catalog cache invalidation when a catalog object is created.
     *
     * Checks if the created object is a catalog by comparing its schema and register
     * against the configured catalog schema and register. If it matches, invalidates
     * the catalog cache to ensure fresh data is used.
     *
     * @param array<string, mixed> $objectData The object data array
     * @return void
     */
    private function handleCatalogCacheInvalidation(array $objectData): void
    {
        try {
            // Get the catalog configuration to identify catalog objects
            $config = \OC::$server->get(\OCP\IAppConfig::class);
            $catalogSchema = $config->getValueString('opencatalogi', 'catalog_schema', '');
            $catalogRegister = $config->getValueString('opencatalogi', 'catalog_register', '');
            
            // Check if both catalog schema and register are configured
            if (empty($catalogSchema) || empty($catalogRegister)) {
                return; // No catalog configuration, nothing to invalidate
            }
            
            // Get the object's schema and register from @self metadata
            $objectSchema = $objectData['@self']['schema'] ?? '';
            $objectRegister = $objectData['@self']['register'] ?? '';
            
            // Check if this object is a catalog
            if ($objectSchema === $catalogSchema && $objectRegister === $catalogRegister) {
                // This is a catalog object - invalidate the cache
                $catalogiService = \OC::$server->get(\OCA\OpenCatalogi\Service\CatalogiService::class);
                $invalidated = $catalogiService->invalidateCatalogCache();
                
                if ($invalidated) {
                    error_log('OpenCatalogi: Invalidated catalog cache due to catalog creation: ' . ($objectData['@self']['id'] ?? 'unknown'));
                } else {
                    error_log('OpenCatalogi: Failed to invalidate catalog cache for catalog creation: ' . ($objectData['@self']['id'] ?? 'unknown'));
                }
            }
            
        } catch (\Exception $e) {
            // Log error but don't fail the event processing
            error_log('OpenCatalogi: Exception during catalog cache invalidation: ' . $e->getMessage());
        }
    }

    /**
     * Convert ObjectEntity to array format for EventService.
     *
     * This method transforms the ObjectEntity from OpenRegister into the array
     * format expected by our EventService.
     *
     * @param \OCA\OpenRegister\Db\ObjectEntity $objectEntity The object entity to convert.
     *
     * @return array The object data in array format.
     */
    private function convertObjectEntityToArray(\OCA\OpenRegister\Db\ObjectEntity $objectEntity): array
    {
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
        $objectData['@self']['published'] = $objectEntity->getPublished()?->format('c');
        $objectData['@self']['depublished'] = $objectEntity->getDepublished()?->format('c');
        
        return $objectData;

    }//end convertObjectEntityToArray()


}//end class 