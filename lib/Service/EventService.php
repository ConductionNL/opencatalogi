<?php
/**
 * OpenCatalogi Event Service
 *
 * This file contains the service class for handling events in the OpenCatalogi application.
 *
 * @category Service
 * @package  OCA\OpenCatalogi\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenCatalogi.nl
 */

namespace OCA\OpenCatalogi\Service;

use OCP\IAppConfig;
use OCP\IRequest;
use OCP\App\IAppManager;
use Psr\Container\ContainerInterface;
use Psr\Container\ContainerExceptionInterface;
use Psr\Container\NotFoundExceptionInterface;
use OCP\AppFramework\Http\JSONResponse;
use Exception;

/**
 * Service for handling events and auto-publishing logic.
 *
 * Provides functionality for processing object creation and update events,
 * implementing auto-publishing features based on configuration settings.
 */
class EventService
{

    /**
     * This property holds the name of the application, which is used for identification and configuration purposes.
     *
     * @var string $appName The name of the app.
     */
    private string $appName;




    /**
     * EventService constructor.
     *
     * @param IAppConfig         $config           App configuration interface.
     * @param IRequest           $request          Request interface.
     * @param ContainerInterface $container        Container for dependency injection.
     * @param IAppManager        $appManager       App manager interface.
     * @param SettingsService    $settingsService  Settings service for configuration access.
     */
    public function __construct(
        private readonly IAppConfig $config,
        private readonly IRequest $request,
        private readonly ContainerInterface $container,
        private readonly IAppManager $appManager,
        private readonly SettingsService $settingsService
    ) {
        // Set the application name for identification and configuration purposes.
        $this->appName = 'opencatalogi';

    }//end __construct()


    /**
     * Attempts to retrieve the OpenRegister object service from the container.
     *
     * @return \OCA\OpenRegister\Service\ObjectService|null The OpenRegister service if available, null otherwise.
     * @throws \RuntimeException If the service is not available.
     */
    public function getObjectService(): ?\OCA\OpenRegister\Service\ObjectService
    {
        if (in_array(needle: 'openregister', haystack: $this->appManager->getInstalledApps()) === true) {
            return $this->container->get('OCA\OpenRegister\Service\ObjectService');
        }

        throw new \RuntimeException('OpenRegister object service is not available.');

    }//end getObjectService()


    /**
     * Attempts to retrieve the OpenRegister file service from the container.
     *
     * @return \OCA\OpenRegister\Service\FileService|null The OpenRegister file service if available, null otherwise.
     * @throws \RuntimeException If the service is not available.
     */
    public function getFileService(): ?\OCA\OpenRegister\Service\FileService
    {
        if (in_array(needle: 'openregister', haystack: $this->appManager->getInstalledApps()) === true) {
            return $this->container->get('OCA\OpenRegister\Service\FileService');
        }

        throw new \RuntimeException('OpenRegister file service is not available.');

    }//end getFileService()


    /**
     * Get the FileMapper from the container.
     *
     * This method provides access to the FileMapper for direct database access
     * to file information without triggering object updates.
     *
     * @return \OCA\OpenRegister\Db\FileMapper The FileMapper instance.
     * @throws \RuntimeException If the FileMapper is not available.
     */
    public function getFileMapper(): ?\OCA\OpenRegister\Db\FileMapper
    {
        if (in_array(needle: 'openregister', haystack: $this->appManager->getInstalledApps()) === true) {
            return $this->container->get('OCA\OpenRegister\Db\FileMapper');
        }

        throw new \RuntimeException('OpenRegister FileMapper is not available.');

    }//end getFileMapper()


    /**
     * Handle object creation events with auto-publishing logic.
     *
     * This method processes newly created objects and applies auto-publishing
     * rules based on the configuration settings.
     *
     * @param array $objects Array of created object data.
     *
     * @return array Results of the event processing including any auto-publishing actions.
     * @throws \RuntimeException If event processing fails.
     */
    public function handleObjectCreateEvents(array $objects): array
    {
        $results = [
            'processed' => 0,
            'published' => 0,
            'attachmentsPublished' => 0,
            'errors' => [],
            'details' => [],
        ];

        try {
            // Get current publishing options from configuration.
            $publishingOptions = $this->settingsService->getPublishingOptions();

            // Process each created object.
            foreach ($objects as $objectData) {
                $objectResult = [
                    'objectId' => $objectData['@self']['id'] ?? 'unknown',
                    'actions' => [],
                    'errors' => [],
                ];

                try {
                    // Check if auto-publish objects is enabled and object should be published.
                    if ($publishingOptions['auto_publish_objects'] === true) {
                        $shouldPublish = $this->shouldAutoPublishObject($objectData);
                        if ($shouldPublish === true) {
                            // Auto-publish the object.
                            $publishResult = $this->publishObject($objectData);
                            if ($publishResult['success'] === true) {
                                $objectResult['actions'][] = 'object_published';
                                $results['published']++;
                            } else {
                                $objectResult['errors'][] = 'Failed to auto-publish object: ' . $publishResult['error'];
                            }
                        }
                    }

                    // Check if auto-publish attachments is enabled and object has published status.
                    if ($publishingOptions['auto_publish_attachments'] === true && $this->isObjectPublished($objectData) === true) {
                        $attachmentResult = $this->publishObjectAttachments($objectData);
                        $objectResult['actions'][] = 'attachments_processed';
                        $results['attachmentsPublished'] += $attachmentResult['published'];
                        
                        if (empty($attachmentResult['errors']) === false) {
                            $objectResult['errors'] = array_merge($objectResult['errors'], $attachmentResult['errors']);
                        }
                    }

                    $results['processed']++;
                } catch (\Exception $e) {
                    $objectResult['errors'][] = $e->getMessage();
                    $results['errors'][] = 'Error processing object ' . $objectResult['objectId'] . ': ' . $e->getMessage();
                }

                $results['details'][] = $objectResult;
            }

            return $results;
        } catch (\Exception $e) {
            throw new \RuntimeException('Failed to handle object create events: ' . $e->getMessage());
        }//end try

    }//end handleObjectCreateEvents()


    /**
     * Handle object update events with auto-publishing logic.
     *
     * This method processes updated objects and applies auto-publishing
     * rules based on the configuration settings and object changes.
     *
     * @param array $objects Array of updated object data.
     *
     * @return array Results of the event processing including any auto-publishing actions.
     * @throws \RuntimeException If event processing fails.
     */
    public function handleObjectUpdateEvents(array $objects): array
    {
        $results = [
            'processed' => 0,
            'published' => 0,
            'attachmentsPublished' => 0,
            'errors' => [],
            'details' => [],
        ];

        try {
            // Get current publishing options from configuration.
            $publishingOptions = $this->settingsService->getPublishingOptions();

            // Process each updated object.
            foreach ($objects as $objectData) {
                $objectResult = [
                    'objectId' => $objectData['@self']['id'] ?? 'unknown',
                    'actions' => [],
                    'errors' => [],
                ];

                try {
                    // Check if auto-publish attachments is enabled and object is published.
                    if ($publishingOptions['auto_publish_attachments'] === true && $this->isObjectPublished($objectData) === true) {
                        $attachmentResult = $this->publishObjectAttachments($objectData);
                        $objectResult['actions'][] = 'attachments_processed';
                        $results['attachmentsPublished'] += $attachmentResult['published'];
                        
                        if (empty($attachmentResult['errors']) === false) {
                            $objectResult['errors'] = array_merge($objectResult['errors'], $attachmentResult['errors']);
                        }
                    }

                    $results['processed']++;
                } catch (\Exception $e) {
                    $objectResult['errors'][] = $e->getMessage();
                    $results['errors'][] = 'Error processing object ' . $objectResult['objectId'] . ': ' . $e->getMessage();
                }

                $results['details'][] = $objectResult;
            }

            return $results;
        } catch (\Exception $e) {
            throw new \RuntimeException('Failed to handle object update events: ' . $e->getMessage());
        }//end try

    }//end handleObjectUpdateEvents()


    /**
     * Determine if an object should be auto-published based on catalog matching.
     *
     * This method checks if the object's schema and register match any catalog
     * configuration, which indicates it should be automatically published.
     *
     * @param array $objectData The object data to evaluate.
     *
     * @return bool True if the object should be auto-published, false otherwise.
     */
    private function shouldAutoPublishObject(array $objectData): bool
    {
        // Check if object has register and schema information.
        if (isset($objectData['@self']['register']) === false || isset($objectData['@self']['schema']) === false) {
            return false;
        }

        $objectRegister = $objectData['@self']['register'];
        $objectSchema = $objectData['@self']['schema'];

        try {
            // Get the actual catalogs from the system to check if object matches any catalog.
            $objectService = $this->getObjectService();
            
            // Get catalogs (assuming they're stored in a specific register/schema).
            $settings = $this->settingsService->getSettings();
            $catalogRegister = $settings['configuration']['catalog_register'] ?? null;
            $catalogSchema = $settings['configuration']['catalog_schema'] ?? null;
            
            if ($catalogRegister && $catalogSchema) {
                // Get all catalog objects using findAll with proper filters.
                $catalogObjects = $objectService->findAll([
                    'filters' => [
                        'register' => $catalogRegister,
                        'schema' => $catalogSchema
                    ]
                ]);
                
                // Check each catalog to see if it includes our object's register and schema.
                foreach ($catalogObjects as $catalog) {
                    $catalogData = $catalog->jsonSerialize();
                    $catalogRegisters = $catalogData['registers'] ?? [];
                    $catalogSchemas = $catalogData['schemas'] ?? [];
                    
                    // Convert object register/schema to integers for comparison.
                    $objectRegisterInt = (int) $objectRegister;
                    $objectSchemaInt = (int) $objectSchema;
                    
                    // Check if this catalog includes the object's register and schema.
                    if (in_array($objectRegisterInt, $catalogRegisters) && in_array($objectSchemaInt, $catalogSchemas)) {
                        return true;
                    }
                }
            }

            return false;
        } catch (\Exception $e) {
            // Log error but don't fail the process.
            error_log('OpenCatalogi shouldAutoPublishObject: Error checking auto-publish criteria: ' . $e->getMessage());
            return false;
        }

    }//end shouldAutoPublishObject()


    /**
     * Check if an object is currently published.
     *
     * An object is considered published if it has a published timestamp
     * and no depublished timestamp, or if depublished is before published.
     *
     * @param array $objectData The object data to check.
     *
     * @return bool True if the object is published, false otherwise.
     */
    private function isObjectPublished(array $objectData): bool
    {
        $published = $objectData['@self']['published'] ?? null;
        $depublished = $objectData['@self']['depublished'] ?? null;

        // Object is published if it has a published date and no depublished date.
        if ($published !== null && $depublished === null) {
            return true;
        }

        // Object is published if published date is after depublished date.
        if ($published !== null && $depublished !== null) {
            $publishedTime = strtotime($published);
            $depublishedTime = strtotime($depublished);
            return $publishedTime > $depublishedTime;
        }

        return false;

    }//end isObjectPublished()


    /**
     * Publish an object using the OpenRegister service.
     *
     * This method sets the object's published status using the OpenRegister
     * ObjectService publish functionality.
     *
     * @param array $objectData The object data to publish.
     *
     * @return array Result of the publish operation.
     */
    private function publishObject(array $objectData): array
    {
        try {
            $objectService = $this->getObjectService();
            $objectId = $objectData['@self']['uuid'] ?? $objectData['@self']['id'];

            // Use the OpenRegister ObjectService to publish the object.
            $publishedObject = $objectService->publish($objectId);

            return [
                'success' => true,
                'objectId' => $objectId,
                'publishedAt' => $publishedObject->getPublished()?->format('c'),
            ];
        } catch (\Exception $e) {
            return [
                'success' => false,
                'objectId' => $objectData['@self']['id'] ?? 'unknown',
                'error' => $e->getMessage(),
            ];
        }

    }//end publishObject()


    /**
     * Publish all unpublished attachments for an object.
     *
     * This method processes all files attached to an object and publishes
     * any that don't already have an access url (indicating they're unpublished).
     *
     * @param array $objectData The object data containing object information.
     *
     * @return array Result of the attachment publishing operation.
     */
    private function publishObjectAttachments(array $objectData): array
    {
        $objectId = $objectData['@self']['uuid'] ?? $objectData['@self']['id'];
        
        $result = [
            'published' => 0,
            'skipped' => 0,
            'errors' => [],
        ];

        try {
            $fileService = $this->getFileService();
            $fileMapper = $this->getFileMapper();
            
            // Use FileMapper to get files directly from database without triggering object updates
            // This completely avoids the infinite loop issue
            
            // First, we need to get the ObjectEntity to use with FileMapper
            $objectService = $this->getObjectService();
            $objectEntity = $objectService->find($objectId);
            
            if ($objectEntity === null) {
                return $result;
            }
            
            // Use FileMapper to get files directly from database (no object updates triggered)
            $files = $fileMapper->getFilesForObject($objectEntity);

            // Process each file from the FileMapper.
            foreach ($files as $file) {
                try {
                    $fileName = $file['name'] ?? 'unknown';
                    $filePath = $file['path'] ?? '';
                    
                    // Check if file is already published by checking if it has a share token
                    // FileMapper already includes share information in the file data
                    if (!empty($file['share_token'])) {
                        $result['skipped']++;
                        continue;
                    }

                    // Create share link directly without updating the object.
                    // Convert FileMapper path to OpenRegister format by adding /OpenRegister/ prefix
                    $openRegisterPath = '/OpenRegister/' . $filePath;
                    
                    try {
                        // Use the converted OpenRegister path format
                        $shareLink = $fileService->createShareLink($openRegisterPath);
                        
                        if ($shareLink && !str_contains($shareLink, 'not found') && !str_contains($shareLink, 'couldn\'t be found')) {
                            $result['published']++;
                        } else {
                            $result['errors'][] = "Failed to create share link for file {$fileName}";
                        }
                    } catch (\Exception $shareException) {
                        $result['errors'][] = "Exception creating share link for file {$fileName}: " . $shareException->getMessage();
                    }
                } catch (\Exception $e) {
                    $fileName = $file['name'] ?? 'unknown';
                    $result['errors'][] = "Failed to publish file {$fileName}: " . $e->getMessage();
                }
            }

            return $result;
        } catch (\Exception $e) {
            $result['errors'][] = 'Failed to access file service: ' . $e->getMessage();
            return $result;
        }

    }//end publishObjectAttachments()


}//end class 