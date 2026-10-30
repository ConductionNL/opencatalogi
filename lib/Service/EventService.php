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
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2024 Conduction B.V. <info@conduction.nl>
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenCatalogi.nl
 *
 * @spec openspec/specs/auto-publishing/spec.md
 * @spec openspec/specs/auto-publishing/spec.md
 * @spec openspec/specs/auto-publishing/spec.md
 * @spec openspec/specs/auto-publishing/spec.md
 * @spec openspec/specs/auto-publishing/spec.md
 * @spec openspec/specs/auto-publishing/spec.md
 */

namespace OCA\OpenCatalogi\Service;

use DateTime;
use OCA\OpenCatalogi\Contract\EventServiceInterface;
use OCP\App\IAppManager;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Service for handling events and auto-publishing logic.
 *
 * Provides functionality for processing object creation and update events,
 * implementing auto-publishing features based on configuration settings.
 *
 * @SuppressWarnings(PHPMD.ExcessiveClassComplexity)
 *
 * @spec openspec/specs/auto-publishing/spec.md
 */
class EventService implements EventServiceInterface {
	/**
	 * EventService constructor.
	 *
	 * @param ContainerInterface $container Container for dependency injection.
	 * @param IAppManager $appManager App manager interface.
	 * @param SettingsService $settingsService Settings service for configuration access.
	 * @param LoggerInterface $logger PSR-3 logger.
	 */
	public function __construct(
		private readonly ContainerInterface $container,
		private readonly IAppManager $appManager,
		private readonly SettingsService $settingsService,
		private readonly LoggerInterface $logger,
	) {

	}//end __construct()

	/**
	 * Attempts to retrieve the OpenRegister object service from the container.
	 *
	 * @return \OCA\OpenRegister\Service\ObjectService|null The OpenRegister service if available, null otherwise.
	 * @throws \RuntimeException If the service is not available.
	 *
	 * @spec exclude Lazy dependency-injection accessor — resolves the OpenRegister
	 *       ObjectService from the container; pure framework plumbing, no domain behavior.
	 */
	public function getObjectService(): ?\OCA\OpenRegister\Service\ObjectService {
		if (in_array(needle: 'openregister', haystack: $this->appManager->getInstalledApps()) === true) {
			return $this->container->get('OCA\OpenRegister\Service\ObjectService');
		}

		throw new RuntimeException('OpenRegister object service is not available.');
	}//end getObjectService()

	/**
	 * Attempts to retrieve the OpenRegister file service from the container.
	 *
	 * @return \OCA\OpenRegister\Service\FileService|null The OpenRegister file service if available, null otherwise.
	 * @throws \RuntimeException If the service is not available.
	 *
	 * @spec exclude Lazy dependency-injection accessor — resolves the OpenRegister
	 *       FileService from the container; pure framework plumbing, no domain behavior.
	 */
	public function getFileService(): ?\OCA\OpenRegister\Service\FileService {
		if (in_array(needle: 'openregister', haystack: $this->appManager->getInstalledApps()) === true) {
			return $this->container->get('OCA\OpenRegister\Service\FileService');
		}

		throw new RuntimeException('OpenRegister file service is not available.');
	}//end getFileService()

	/**
	 * Get the FileMapper from the container.
	 *
	 * This method provides access to the FileMapper for direct database access
	 * to file information without triggering object updates.
	 *
	 * @return \OCA\OpenRegister\Db\FileMapper The FileMapper instance.
	 * @throws \RuntimeException If the FileMapper is not available.
	 *
	 * @spec exclude Lazy dependency-injection accessor — resolves the OpenRegister
	 *       FileMapper from the container; pure framework plumbing, no domain behavior.
	 */
	public function getFileMapper(): ?\OCA\OpenRegister\Db\FileMapper {
		if (in_array(needle: 'openregister', haystack: $this->appManager->getInstalledApps()) === true) {
			return $this->container->get('OCA\OpenRegister\Db\FileMapper');
		}

		throw new RuntimeException('OpenRegister FileMapper is not available.');
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
	 *
	 * @spec openspec/specs/auto-publishing/spec.md
	 */
	public function handleObjectCreateEvents(array $objects): array {
		return $this->handleObjectEvents(objects: $objects, event: 'create');
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
	 *
	 * @spec openspec/specs/auto-publishing/spec.md
	 */
	public function handleObjectUpdateEvents(array $objects): array {
		return $this->handleObjectEvents(objects: $objects, event: 'update');
	}//end handleObjectUpdateEvents()

	/**
	 * Apply the auto-publish rules to every object of one event and tally the outcome.
	 *
	 * Only a create event may publish the object itself; both events publish the
	 * attachments of an object that is live.
	 *
	 * @param array $objects The event's object data.
	 * @param string $event 'create' or 'update'.
	 *
	 * @return array The processed, published and attachmentsPublished counts, errors and per-object details.
	 * @throws \RuntimeException If the publishing options cannot be read.
	 *
	 * @spec openspec/specs/auto-publishing/spec.md
	 */
	private function handleObjectEvents(array $objects, string $event): array {
		$results = [
			'processed' => 0,
			'published' => 0,
			'attachmentsPublished' => 0,
			'errors' => [],
			'details' => [],
		];

		try {
			$run = ['event' => $event, 'options' => $this->settingsService->getPublishingOptions()];
			foreach ($objects as $objectData) {
				$outcome = $this->processObject(objectData: $objectData, run: $run);
				$results['processed'] += $outcome['processed'];
				$results['published'] += $outcome['published'];
				$results['attachmentsPublished'] += $outcome['attachmentsPublished'];
				if ($outcome['error'] !== null) {
					$results['errors'][] = $outcome['error'];
				}

				$results['details'][] = $outcome['detail'];
			}

			return $results;
		} catch (\Exception $e) {
			throw new RuntimeException('Failed to handle object ' . $event . ' events: ' . $e->getMessage());
		}//end try

	}//end handleObjectEvents()

	/**
	 * Apply the auto-publish rules to one object.
	 *
	 * @param array $objectData The object data.
	 * @param array<string, mixed> $run The event ('create' or 'update') and the publishing options.
	 *
	 * @return array The object's detail, its counts and the error to report, if any.
	 *
	 * @spec openspec/specs/auto-publishing/spec.md
	 */
	private function processObject(array $objectData, array $run): array {
		$outcome = [
			'detail' => ['objectId' => ($objectData['@self']['id'] ?? 'unknown'), 'actions' => [], 'errors' => []],
			'processed' => 0,
			'published' => 0,
			'attachmentsPublished' => 0,
			'error' => null,
		];

		try {
			if ($run['event'] === 'create' && $run['options']['auto_publish_objects'] === true) {
				$outcome = $this->autoPublishObject(objectData: $objectData, outcome: $outcome);
			}

			if ($run['options']['auto_publish_attachments'] === true && $this->isObjectPublished(objectData: $objectData) === true) {
				$attachmentResult = $this->publishObjectAttachments(objectData: $objectData);
				$outcome['detail']['actions'][] = 'attachments_processed';
				$outcome['attachmentsPublished'] = $attachmentResult['published'];
				$outcome['detail']['errors'] = array_merge($outcome['detail']['errors'], $attachmentResult['errors']);
			}

			$outcome['processed'] = 1;
		} catch (\Exception $e) {
			$outcome['detail']['errors'][] = $e->getMessage();
			$outcome['error'] = 'Error processing object ' . $outcome['detail']['objectId'] . ': ' . $e->getMessage();
		}//end try

		return $outcome;
	}//end processObject()

	/**
	 * Publish an object that matches a catalog, and record the result on its outcome.
	 *
	 * @param array $objectData The object data.
	 * @param array<string, mixed> $outcome The object's outcome so far.
	 *
	 * @return array<string, mixed> The outcome with the publish action or error added.
	 *
	 * @spec openspec/specs/auto-publishing/spec.md
	 */
	private function autoPublishObject(array $objectData, array $outcome): array {
		if ($this->shouldAutoPublishObject(objectData: $objectData) === false) {
			return $outcome;
		}

		$publishResult = $this->publishObject(objectData: $objectData);
		if ($publishResult['success'] === true) {
			$outcome['detail']['actions'][] = 'object_published';
			$outcome['published'] = 1;
			return $outcome;
		}

		$outcome['detail']['errors'][] = 'Failed to auto-publish object: ' . $publishResult['error'];
		return $outcome;
	}//end autoPublishObject()

	/**
	 * Determine if an object should be auto-published based on catalog matching.
	 *
	 * This method checks if the object's schema and register match any catalog
	 * configuration, which indicates it should be automatically published.
	 *
	 * @param array $objectData The object data to evaluate.
	 *
	 * @return boolean True if the object should be auto-published, false otherwise.
	 *
	 * @spec openspec/specs/auto-publishing/spec.md
	 */
	private function shouldAutoPublishObject(array $objectData): bool {
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

			if ($catalogRegister !== null && $catalogSchema !== null) {
				// Get all catalog objects using searchObjects with proper filters.
				$catalogObjects = $objectService->searchObjects(
					query: [
						'@self' => [
							'register' => $catalogRegister,
							'schema' => $catalogSchema,
						],
					]
				);

				// Check each catalog to see if it includes our object's register and schema.
				foreach ($catalogObjects as $catalog) {
					$catalogData = $catalog->jsonSerialize();
					$catalogRegisters = ($catalogData['registers'] ?? []);
					$catalogSchemas = ($catalogData['schemas'] ?? []);

					// Convert object register/schema to integers for comparison.
					$objectRegisterInt = (int)$objectRegister;
					$objectSchemaInt = (int)$objectSchema;

					// Check if this catalog includes the object's register and schema.
					if (in_array($objectRegisterInt, $catalogRegisters) === true
						&& in_array($objectSchemaInt, $catalogSchemas) === true
					) {
						return true;
					}
				}
			}//end if

			return false;
		} catch (\Exception $e) {
			// Log error but don't fail the process.
			$this->logger->error(
				'OpenCatalogi shouldAutoPublishObject: Error checking auto-publish criteria: ' . $e->getMessage(),
				['exception' => $e]
			);
			return false;
		}//end try

	}//end shouldAutoPublishObject()

	/**
	 * Check if an object is currently published.
	 *
	 * Visibility is governed by the live OpenRegister RBAC model (APB-006): an
	 * object is public when its own `publicationDate` field is set and is at or
	 * before "now", and either carries no `depublicationDate` or one that is
	 * still in the future. The removed object-level `@self.published` /
	 * `@self.depublished` predicate is no longer consulted.
	 *
	 * @param array $objectData The object data to check.
	 *
	 * @return boolean True if the object is published, false otherwise.
	 *
	 * @spec openspec/specs/auto-publishing/spec.md#APB-006
	 */
	private function isObjectPublished(array $objectData): bool {
		$publicationDate = ($objectData['publicationDate'] ?? null);
		$depublicationDate = ($objectData['depublicationDate'] ?? null);

		// Not published until a publication date is set and reached.
		if ($publicationDate === null || $publicationDate === '') {
			return false;
		}

		$now = time();
		$publishedTime = strtotime((string)$publicationDate);
		if ($publishedTime === false || $publishedTime > $now) {
			return false;
		}

		// No depublication date -> still live.
		if ($depublicationDate === null || $depublicationDate === '') {
			return true;
		}

		// Live only while the depublication date is still in the future.
		$depublishedTime = strtotime((string)$depublicationDate);
		return ($depublishedTime === false || $depublishedTime > $now);
	}//end isObjectPublished()

	/**
	 * Publish an object via the live OpenRegister RBAC model.
	 *
	 * The removed `ObjectService::publish()` predicate is no longer used.
	 * "Publish" means setting the object's own `publicationDate` field to "now"
	 * (and clearing any `depublicationDate`) and persisting it through the
	 * normal OpenRegister save path, so the public RBAC rule
	 * `{group:public, match:{publicationDate:{$lte:$now}}}` makes it visible.
	 *
	 * @param array $objectData The object data to publish.
	 *
	 * @return array Result of the publish operation.
	 *
	 * @spec openspec/specs/auto-publishing/spec.md#APB-006
	 */
	private function publishObject(array $objectData): array {
		try {
			$objectService = $this->getObjectService();
			$objectId = ($objectData['@self']['uuid'] ?? $objectData['@self']['id'] ?? null);
			$register = ($objectData['@self']['register'] ?? null);
			$schema = ($objectData['@self']['schema'] ?? null);

			if ($objectId === null || $register === null || $schema === null) {
				throw new RuntimeException('Object is missing id, register or schema and cannot be published.');
			}

			// Build the persisted payload from the object's own fields (drop the
			// read-only @self envelope) and set the publication date to now.
			$data = $objectData;
			unset($data['@self']);
			$now = new DateTime();
			$data['publicationDate'] = $now->format(\DateTimeInterface::ATOM);
			// Clearing any prior depublication date keeps the object live.
			$data['depublicationDate'] = null;

			$objectService->saveObject(
				object: $data,
				register: (string)$register,
				schema: (string)$schema,
				uuid: (string)$objectId,
			);

			return [
				'success' => true,
				'objectId' => $objectId,
				'publishedAt' => $data['publicationDate'],
			];
		} catch (\Exception $e) {
			return [
				'success' => false,
				'objectId' => ($objectData['@self']['id'] ?? 'unknown'),
				'error' => $e->getMessage(),
			];
		}//end try

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
	 *
	 * @SuppressWarnings(PHPMD.CyclomaticComplexity)
	 *
	 * @spec openspec/specs/auto-publishing/spec.md
	 */
	private function publishObjectAttachments(array $objectData): array {
		$objectId = ($objectData['@self']['uuid'] ?? $objectData['@self']['id']);

		$result = [
			'published' => 0,
			'skipped' => 0,
			'errors' => [],
		];

		try {
			$fileService = $this->getFileService();
			$fileMapper = $this->getFileMapper();

			// Use FileMapper to get files directly from database without triggering object updates.
			// This completely avoids the infinite loop issue.
			// First, we need to get the ObjectEntity to use with FileMapper.
			$objectService = $this->getObjectService();
			$objectEntity = $objectService->find($objectId);

			if ($objectEntity === null) {
				return $result;
			}

			// Use FileMapper to get files directly from database (no object updates triggered).
			$files = $fileMapper->getFilesForObject($objectEntity);

			// Process each file from the FileMapper.
			foreach ($files as $file) {
				$fileName = ($file['name'] ?? 'unknown');
				$filePath = ($file['path'] ?? '');

				// Check if file is already published by checking if it has a share token.
				// FileMapper already includes share information in the file data.
				if (empty($file['share_token']) === false) {
					$result['skipped']++;
					continue;
				}

				// Create share link directly without updating the object.
				// Convert FileMapper path to OpenRegister format by adding /OpenRegister/ prefix.
				$openRegisterPath = '/OpenRegister/' . $filePath;

				try {
					// Use the converted OpenRegister path format.
					$shareLink = $fileService->createShareLink($openRegisterPath);

					if ($shareLink !== null
						&& $shareLink !== ''
						&& str_contains($shareLink, 'not found') === false
						&& str_contains($shareLink, 'couldn\'t be found') === false
					) {
						$result['published']++;
					}

					if ($shareLink === null
						|| $shareLink === ''
						|| str_contains($shareLink, 'not found') === true
						|| str_contains($shareLink, 'couldn\'t be found') === true
					) {
						$result['errors'][] = "Failed to create share link for file {$fileName}";
					}
				} catch (\Exception $e) {
					$result['errors'][] = "Failed to publish file {$fileName}: " . $e->getMessage();
				}//end try
			}//end foreach

			return $result;
		} catch (\Exception $e) {
			$result['errors'][] = 'Failed to access file service: ' . $e->getMessage();
			return $result;
		}//end try

	}//end publishObjectAttachments()
}//end class
