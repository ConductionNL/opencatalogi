<?php
/**
 * OpenCatalogi Object Updated Event Listener
 *
 * This file contains the listener class for handling object update events from OpenRegister.
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

use OCA\OpenRegister\Event\ObjectUpdatedEvent;
use OCA\OpenCatalogi\Service\EventService;
use OCA\OpenCatalogi\Service\SettingsService;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use Psr\Log\LoggerInterface;

/**
 * Event listener for object update events from OpenRegister.
 *
 * Listens to ObjectUpdatedEvent and applies auto-publishing logic
 * based on OpenCatalogi configuration settings.
 */
class ObjectUpdatedEventListener implements IEventListener
{


    /**
     * ObjectUpdatedEventListener constructor.
     */
    public function __construct()
    {

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
     */
    public function handle(Event $event): void
    {
        try {
            // Get logger first for all logging
            $logger = \OC::$server->get(\Psr\Log\LoggerInterface::class);

            // TEST LOGGING TO SEE IF OPENCATALOGI LISTENER WORKS
            $logger->debug("OPENCATALOGI_EVENT_LISTENER_CALLED_AT_".date('Y-m-d_H:i:s'));
            $logger->debug("OPENCATALOGI_EVENT_CLASS: ".get_class($event));

            // Verify this is the correct event type.
            if ($event instanceof ObjectUpdatedEvent === false) {
                $logger->debug("OPENCATALOGI_NOT_OBJECTUPDATEDEVENT_SKIPPING");
                return;
            }

            $logger->debug("OPENCATALOGI_CONFIRMED_OBJECTUPDATEDEVENT_PROCESSING");

            // Get services from the server container
            $settingsService = \OC::$server->get(\OCA\OpenCatalogi\Service\SettingsService::class);
            $eventService    = \OC::$server->get(\OCA\OpenCatalogi\Service\EventService::class);

            // Check if any auto-publishing features are enabled before processing.
            $publishingOptions = $settingsService->getPublishingOptions();

            // Skip processing if no auto-publishing features are enabled.
            if ($publishingOptions['auto_publish_objects'] === false && $publishingOptions['auto_publish_attachments'] === false) {
                return;
            }

            // Get the updated object from the event.
            $newObjectEntity = $event->getNewObject();
            $oldObjectEntity = $event->getOldObject();

            // Convert ObjectEntity to array format expected by EventService.
            $newObjectData = $this->convertObjectEntityToArray($newObjectEntity);

            // Check if this update should trigger auto-publishing logic.
            if ($this->shouldProcessUpdate($newObjectData, $oldObjectEntity, $publishingOptions) === false) {
                return;
            }

            // Process the object update event through EventService.
            $result = $eventService->handleObjectUpdateEvents([$newObjectData]);

            // Log successful processing for monitoring.
            if ($result['processed'] > 0) {
                $logger->info(
                    'OpenCatalogi: Processed object update event',
                    [
                        'objectId'             => ($newObjectData['@self']['id'] ?? 'unknown'),
                        'published'            => $result['published'],
                        'attachmentsPublished' => $result['attachmentsPublished'],
                    ]
                );
            }

            // Log any errors that occurred during processing.
            if (empty($result['errors']) === false) {
                foreach ($result['errors'] as $error) {
                    $logger->error(
                        'OpenCatalogi: Error processing object update event',
                        [
                            'error'    => $error,
                            'objectId' => ($newObjectData['@self']['id'] ?? 'unknown'),
                        ]
                    );
                }
            }
        } catch (\Exception $e) {
            // Log unexpected errors and continue gracefully.
            // Get logger if not already available
            if (!isset($logger)) {
                $logger = \OC::$server->get(\Psr\Log\LoggerInterface::class);
            }

            $logger->error('OpenCatalogi: Exception in object update event listener: '.$e->getMessage(), ['exception' => $e]);
        }//end try

    }//end handle()


    /**
     * Determine if an object update should trigger auto-publishing logic.
     *
     * This method checks if the update is relevant for auto-publishing based on
     * what changed and current configuration.
     *
     * @param array                             $newObjectData     The updated object data.
     * @param \OCA\OpenRegister\Db\ObjectEntity $oldObjectEntity   The original object entity.
     * @param array                             $publishingOptions The current publishing configuration.
     *
     * @return boolean True if the update should be processed, false otherwise.
     */
    private function shouldProcessUpdate(array $newObjectData, \OCA\OpenRegister\Db\ObjectEntity $oldObjectEntity, array $publishingOptions): bool
    {
        // If auto-publish attachments is enabled, always process updates for published objects.
        if ($publishingOptions['auto_publish_attachments'] === true) {
            $isNewObjectPublished = $this->isObjectPublished($newObjectData);
            if ($isNewObjectPublished === true) {
                return true;
            }
        }

        // If auto-publish objects is not enabled, no further processing needed.
        if ($publishingOptions['auto_publish_objects'] === false) {
            return false;
        }

        // Check if publication status changed from unpublished to published.
        $wasPublished = $this->isObjectEntityPublished($oldObjectEntity);
        $isPublished  = $this->isObjectPublished($newObjectData);

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
     */
    private function isObjectEntityPublished(\OCA\OpenRegister\Db\ObjectEntity $objectEntity): bool
    {
        $published   = $objectEntity->getPublished();
        $depublished = $objectEntity->getDepublished();

        // Object is published if it has a published date and no depublished date.
        if ($published !== null && $depublished === null) {
            return true;
        }

        // Object is published if published date is after depublished date.
        if ($published !== null && $depublished !== null) {
            return $published > $depublished;
        }

        return false;

    }//end isObjectEntityPublished()


    /**
     * Check if an object data array represents a published object.
     *
     * @param array $objectData The object data to check.
     *
     * @return boolean True if the object is published, false otherwise.
     */
    private function isObjectPublished(array $objectData): bool
    {
        $published   = $objectData['@self']['published'] ?? null;
        $depublished = $objectData['@self']['depublished'] ?? null;

        // Object is published if it has a published date and no depublished date.
        if ($published !== null && $depublished === null) {
            return true;
        }

        // Object is published if published date is after depublished date.
        if ($published !== null && $depublished !== null) {
            $publishedTime   = strtotime($published);
            $depublishedTime = strtotime($depublished);
            return $publishedTime > $depublishedTime;
        }

        return false;

    }//end isObjectPublished()


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
        $objectData['@self']['id']          = $objectEntity->getUuid();
        $objectData['@self']['uuid']        = $objectEntity->getUuid();
        $objectData['@self']['register']    = $objectEntity->getRegister();
        $objectData['@self']['schema']      = $objectEntity->getSchema();
        $objectData['@self']['published']   = $objectEntity->getPublished()?->format('c');
        $objectData['@self']['depublished'] = $objectEntity->getDepublished()?->format('c');

        // For now, don't fetch files to avoid infinite recursion.
        // The FileService->getFiles() call can trigger object updates which cause infinite loops.
        // TODO: Implement a safer way to get file information for attachment publishing.
        $objectData['@self']['files'] = [];

        return $objectData;

    }//end convertObjectEntityToArray()


}//end class
