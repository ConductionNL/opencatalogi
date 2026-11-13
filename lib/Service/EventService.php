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

	use BuildsSaveOptions;

	/**
	 * The tally of an event before any object is processed.
	 *
	 * @var array<string, mixed>
	 */
	private const EMPTY_RESULTS = [
		'processed' => 0,
		'published' => 0,
		'attachmentsPublished' => 0,
		'errors' => [],
		'details' => [],
	];

	/**
	 * The counts and error of one object before it is processed.
	 *
	 * @var array<string, mixed>
	 */
	private const EMPTY_OUTCOME = [
		'processed' => 0,
		'published' => 0,
		'attachmentsPublished' => 0,
		'error' => null,
	];
	use ChecksPublicationWindow;

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
	 * @return \OCA\OpenRegister\Files\Db\FileMapper The FileMapper instance.
	 * @throws \RuntimeException If the FileMapper is not available.
	 *
	 * @spec exclude Lazy dependency-injection accessor — resolves the OpenRegister
	 *       FileMapper from the container; pure framework plumbing, no domain behavior.
	 */
	public function getFileMapper(): ?\OCA\OpenRegister\Files\Db\FileMapper {
		if (in_array(needle: 'openregister', haystack: $this->appManager->getInstalledApps()) === true) {
			return $this->container->get('OCA\OpenRegister\Files\Db\FileMapper');
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
		$results = self::EMPTY_RESULTS;

		try {
			$run = ['event' => $event, 'options' => $this->settingsService->getPublishingOptions()];
			foreach ($objects as $objectData) {
				$results = self::withOutcome(results: $results, outcome: $this->processObject(objectData: $objectData, run: $run));
			}

			return $results;
		} catch (\Exception $e) {
			throw new RuntimeException('Failed to handle object ' . $event . ' events: ' . $e->getMessage());
		}//end try
	}//end handleObjectEvents()

	/**
	 * The event's tally with one object's outcome added.
	 *
	 * @param array $results The tally so far.
	 * @param array $outcome The object's counts, error and detail.
	 *
	 * @return array
	 *
	 * @spec openspec/specs/auto-publishing/spec.md
	 */
	private static function withOutcome(array $results, array $outcome): array {
		foreach (['processed', 'published', 'attachmentsPublished'] as $count) {
			$results[$count] += $outcome[$count];
		}

		if ($outcome['error'] !== null) {
			$results['errors'][] = $outcome['error'];
		}

		$results['details'][] = $outcome['detail'];
		return $results;
	}//end withOutcome()

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
		] + self::EMPTY_OUTCOME;

		$context = ['objectData' => $objectData, 'run' => $run];
		try {
			$outcome = $this->withAttachments(outcome: $this->autoPublishObject(outcome: $outcome, context: $context), context: $context);
			$outcome['processed'] = 1;
		} catch (\Exception $e) {
			$outcome['detail']['errors'][] = $e->getMessage();
			$outcome['error'] = 'Error processing object ' . $outcome['detail']['objectId'] . ': ' . $e->getMessage();
		}//end try

		return $outcome;
	}//end processObject()

	/**
	 * The outcome after publishing the attachments of a live object, when the options say so.
	 *
	 * An object without a uuid or id in its @self envelope cannot be looked up, so its
	 * attachments are skipped with a warning and the rest of the batch goes on.
	 *
	 * @param array $outcome The object's outcome so far.
	 * @param array $context The object data and the run (event and publishing options).
	 *
	 * @return array
	 *
	 * @spec openspec/specs/auto-publishing/spec.md
	 */
	private function withAttachments(array $outcome, array $context): array {
		$objectData = $context['objectData'];
		if ($context['run']['options']['auto_publish_attachments'] !== true || $this->isObjectPublished(objectData: $objectData) === false) {
			return $outcome;
		}

		if (($objectData['@self']['uuid'] ?? $objectData['@self']['id'] ?? null) === null) {
			$this->logger->warning('OpenCatalogi: skipped the attachments of an object without an id', ['object' => $outcome['detail']['objectId']]);
			return $outcome;
		}

		$attachmentResult = $this->publishObjectAttachments(objectData: $objectData);
		$outcome['detail']['actions'][] = 'attachments_processed';
		$outcome['attachmentsPublished'] = $attachmentResult['published'];
		$outcome['detail']['errors'] = array_merge($outcome['detail']['errors'], $attachmentResult['errors']);

		return $outcome;
	}//end withAttachments()

	/**
	 * On a create event with auto-publishing on, publish an object that matches a catalog, and record the result.
	 *
	 * @param array<string, mixed> $outcome The object's outcome so far.
	 * @param array<string, mixed> $context The object data and the run (event and publishing options).
	 *
	 * @return array<string, mixed> The outcome with the publish action or error added.
	 *
	 * @spec openspec/specs/auto-publishing/spec.md
	 */
	private function autoPublishObject(array $outcome, array $context): array {
		if ($this->wantsAutoPublish(context: $context) === false) {
			return $outcome;
		}

		$publishResult = $this->publishObject(objectData: $context['objectData']);
		if ($publishResult['success'] === true) {
			$outcome['detail']['actions'][] = 'object_published';
			$outcome['published'] = 1;
			return $outcome;
		}

		$outcome['detail']['errors'][] = 'Failed to auto-publish object: ' . $publishResult['error'];
		return $outcome;
	}//end autoPublishObject()

	/**
	 * Whether the object is to be published: a create event, auto-publishing on, and a catalog that lists it.
	 *
	 * @param array<string, mixed> $context The object data and the run (event and publishing options).
	 *
	 * @return boolean
	 *
	 * @spec openspec/specs/auto-publishing/spec.md
	 */
	private function wantsAutoPublish(array $context): bool {
		return $context['run']['event'] === 'create'
			&& $context['run']['options']['auto_publish_objects'] === true
			&& $this->shouldAutoPublishObject(objectData: $context['objectData']) === true;
	}//end wantsAutoPublish()

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

		try {
			return $this->inAnyCatalog(objectSelf: $objectData['@self']);
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
	 * Whether any configured catalog lists the object's register and schema.
	 *
	 * @param array $objectSelf The object's @self envelope.
	 *
	 * @return boolean False when the catalog register or schema is not configured.
	 *
	 * @spec openspec/specs/auto-publishing/spec.md
	 */
	private function inAnyCatalog(array $objectSelf): bool {
		$objectService = $this->getObjectService();
		$catalogQuery = $this->catalogQuery();
		if ($catalogQuery === null) {
			return false;
		}

		foreach ($objectService->searchObjects(query: $catalogQuery) as $catalog) {
			if ($this->catalogIncludes(catalogData: $catalog->jsonSerialize(), objectSelf: $objectSelf) === true) {
				return true;
			}
		}

		return false;
	}//end inAnyCatalog()

	/**
	 * The search query for the configured catalogs.
	 *
	 * @return array|null The query, or null when the catalog register or schema is not configured.
	 */
	private function catalogQuery(): ?array {
		$settings = $this->settingsService->getSettings();
		$catalogRegister = $settings['configuration']['catalog_register'] ?? null;
		$catalogSchema = $settings['configuration']['catalog_schema'] ?? null;
		if ($catalogRegister === null || $catalogSchema === null) {
			return null;
		}

		return [
			'@self' => [
				'register' => $catalogRegister,
				'schema' => $catalogSchema,
			],
		];
	}//end catalogQuery()

	/**
	 * Whether a catalog includes both the register and the schema of an object.
	 *
	 * The object's register and schema are compared as integers, loosely, against the catalog's lists.
	 *
	 * @param mixed $catalogData The catalog's data.
	 * @param array $objectSelf The object's `@self`, with its register and schema.
	 *
	 * @return bool True when both are in the catalog.
	 */
	private function catalogIncludes(mixed $catalogData, array $objectSelf): bool {
		return in_array((int)$objectSelf['register'], ($catalogData['registers'] ?? [])) === true
			&& in_array((int)$objectSelf['schema'], ($catalogData['schemas'] ?? [])) === true;
	}//end catalogIncludes()

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
			$target = self::publicationTarget(objectSelf: ($objectData['@self'] ?? []));
			$data = self::publishedPayload(objectData: $objectData);

			$objectService->saveObject(
				object: $data,
				options: $this->saveOptions(register: (string)$target['register'], schema: (string)$target['schema'], uuid: (string)$target['id'])
			);

			return ['success' => true, 'objectId' => $target['id'], 'publishedAt' => $data['publicationDate']];
		} catch (\Exception $e) {
			return ['success' => false, 'objectId' => ($objectData['@self']['id'] ?? 'unknown'), 'error' => $e->getMessage()];
		}//end try
	}//end publishObject()

	/**
	 * The id, register and schema an object is saved under.
	 *
	 * @param array $objectSelf The object's @self envelope.
	 *
	 * @return array{id: mixed, register: mixed, schema: mixed}
	 *
	 * @throws RuntimeException When one of them is missing.
	 *
	 * @spec openspec/specs/auto-publishing/spec.md#APB-006
	 */
	private static function publicationTarget(array $objectSelf): array {
		$target = [
			'id' => ($objectSelf['uuid'] ?? $objectSelf['id'] ?? null),
			'register' => ($objectSelf['register'] ?? null),
			'schema' => ($objectSelf['schema'] ?? null),
		];
		if (in_array(null, $target, true) === true) {
			throw new RuntimeException('Object is missing id, register or schema and cannot be published.');
		}

		return $target;
	}//end publicationTarget()

	/**
	 * The object's own fields without the read-only @self envelope, published now.
	 *
	 * Clearing any prior depublication date keeps the object live.
	 *
	 * @param array $objectData The object data.
	 *
	 * @return array
	 *
	 * @spec openspec/specs/auto-publishing/spec.md#APB-006
	 */
	private static function publishedPayload(array $objectData): array {
		$data = $objectData;
		unset($data['@self']);
		$data['publicationDate'] = (new DateTime())->format(\DateTimeInterface::ATOM);
		$data['depublicationDate'] = null;

		return $data;
	}//end publishedPayload()

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
	 * @spec openspec/specs/auto-publishing/spec.md
	 */
	private function publishObjectAttachments(array $objectData): array {
		$objectId = ($objectData['@self']['uuid'] ?? $objectData['@self']['id']);
		$result = ['published' => 0, 'skipped' => 0, 'errors' => []];

		try {
			$fileService = $this->getFileService();
			$files = $this->objectFiles(objectId: $objectId);
		} catch (\Exception $e) {
			$result['errors'][] = 'Failed to access file service: ' . $e->getMessage();
			return $result;
		}

		foreach ($files as $file) {
			$result = $this->withSharedFile(result: $result, share: ['fileService' => $fileService, 'file' => $file]);
		}

		return $result;
	}//end publishObjectAttachments()

	/**
	 * The files of an object, read through FileMapper, or none when the object does not exist.
	 *
	 * FileMapper reads the files straight from the database, so listing them
	 * triggers no object update (and no new event).
	 *
	 * @param mixed $objectId The object uuid or id.
	 *
	 * @return array The files, with their share information.
	 *
	 * @spec openspec/specs/auto-publishing/spec.md
	 */
	private function objectFiles(mixed $objectId): array {
		$fileMapper = $this->getFileMapper();
		$objectEntity = $this->getObjectService()->find($objectId);
		if ($objectEntity === null) {
			return [];
		}

		return $fileMapper->getFilesForObject($objectEntity);
	}//end objectFiles()

	/**
	 * The attachment tally after sharing one file: skipped when it already has a share token.
	 *
	 * @param array $result The published and skipped counts and the errors so far.
	 * @param array $share The file service and the file.
	 *
	 * @return array
	 *
	 * @spec openspec/specs/auto-publishing/spec.md
	 */
	private function withSharedFile(array $result, array $share): array {
		if (empty($share['file']['share_token']) === false) {
			$result['skipped']++;
			return $result;
		}

		$error = $this->shareFileError(fileService: $share['fileService'], file: $share['file']);
		if ($error === null) {
			$result['published']++;
			return $result;
		}

		$result['errors'][] = $error;
		return $result;
	}//end withSharedFile()

	/**
	 * Create the share link of one file, and say why when that failed.
	 *
	 * The FileMapper path gets the `/OpenRegister/` prefix the file service expects.
	 * An empty link, or one that says the file was not found, counts as a failure.
	 *
	 * @param mixed $fileService The OpenRegister file service.
	 * @param array $file The file, as FileMapper lists it.
	 *
	 * @return string|null The error, or null when the share link was created.
	 */
	private function shareFileError(mixed $fileService, array $file): ?string {
		$fileName = ($file['name'] ?? 'unknown');
		try {
			$shareLink = $fileService->createShareLink('/OpenRegister/' . ($file['path'] ?? ''));
		} catch (\Exception $e) {
			return "Failed to publish file {$fileName}: " . $e->getMessage();
		}

		if (self::isFailedShareLink(shareLink: $shareLink) === true) {
			return "Failed to create share link for file {$fileName}";
		}

		return null;
	}//end shareFileError()

	/**
	 * Whether a share link answer is empty or says the file was not found.
	 *
	 * @param mixed $shareLink The answer of createShareLink().
	 *
	 * @return boolean
	 */
	private static function isFailedShareLink(mixed $shareLink): bool {
		return $shareLink === null
			|| $shareLink === ''
			|| str_contains($shareLink, 'not found') === true
			|| str_contains($shareLink, 'couldn\'t be found') === true;
	}//end isFailedShareLink()
}//end class
