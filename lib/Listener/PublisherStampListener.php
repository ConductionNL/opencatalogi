<?php

/**
 * OpenCatalogi Publisher Stamp Listener.
 *
 * A file uploaded to a publication outside a Woo batch gets the same
 * publisher labels as an attached batch document, and its publication the
 * publisher's RSIN.
 *
 * @category Listener
 * @package  OCA\OpenCatalogi\Listener
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenCatalogi.nl
 *
 * @spec openspec/changes/publications-name-their-responsible-organisation/tasks.md#task-2.2d
 */

declare(strict_types=1);

namespace OCA\OpenCatalogi\Listener;

use OCA\OpenCatalogi\Service\Publication\PublisherFileStamp;
use OCA\OpenRegister\Kernel\Contracts\Value\ObjectFindOptions;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\IAppConfig;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Stamps the publisher's labels onto a file OpenRegister's files API added to
 * a publication (`FileAddedEvent`, dispatched by `files#create`,
 * `files#createMultipart` and the create-from-URL route), and stores the
 * publisher's RSIN on the publication. Files added to any other object are
 * left alone. A failure is logged and never undoes the upload.
 *
 * @spec openspec/changes/publications-name-their-responsible-organisation/specs/woo-compliance/spec.md#requirement-the-publishers-rsin-travels-with-every-stored-document-req-pro-002
 *
 * @template-implements IEventListener<Event>
 */
class PublisherStampListener implements IEventListener {

	/**
	 * OpenRegister's event for a file added to an object, named as a string so
	 * this app loads with an OpenRegister that does not dispatch it yet.
	 *
	 * @var string
	 */
	public const FILE_ADDED_EVENT = 'OCA\OpenRegister\Event\FileAddedEvent';

	/**
	 * Constructor.
	 *
	 * @param IAppConfig         $config    Names the publication register and schema.
	 * @param PublisherFileStamp $stamp     Writes the labels and stores the RSIN.
	 * @param ContainerInterface $container Resolves OpenRegister's file and object services.
	 * @param LoggerInterface    $logger    Logs a stamp that failed.
	 *
	 * @spec exclude pure framework plumbing: constructor dependency injection.
	 */
	public function __construct(
		private readonly IAppConfig $config,
		private readonly PublisherFileStamp $stamp,
		private readonly ContainerInterface $container,
		private readonly LoggerInterface $logger,
	) {

	}//end __construct()

	/**
	 * Stamp a file added to a publication.
	 *
	 * @param Event $event OpenRegister's FileAddedEvent.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/publications-name-their-responsible-organisation/tasks.md#task-2.2d
	 */
	public function handle(Event $event): void {
		$target = $this->publicationFile(event: $event);
		if ($target === null) {
			return;
		}

		[$uuid, $fileId, $register, $schema] = $target;
		try {
			$objects = $this->container->get('OCA\OpenRegister\Service\ObjectService');
			$publication = $objects->find(
				id: $uuid,
				options: new ObjectFindOptions(register: $register, schema: $schema),
				_rbac: false,
				_multitenancy: false
			);
			$labels = $this->stamp->stamp(
				files: $this->container->get('OCA\OpenRegister\Service\FileService'),
				publication: $publication,
				fileId: $fileId
			);
			if ($labels !== null) {
				$this->stamp->record(objects: $objects, publicationId: $uuid, labels: $labels, register: $register, schema: $schema);
			}
		} catch (Throwable $e) {
			$this->logger->warning(
				'[PublisherStampListener] The publisher was not stamped on file {file} of publication {id}: {error}',
				['file' => $fileId, 'id' => $uuid, 'error' => $e->getMessage()]
			);
		}

	}//end handle()

	/**
	 * The publication uuid, file id, register and schema of an added file, or
	 * null when the event is not a file added to a publication.
	 *
	 * @param Event $event The event.
	 *
	 * @return array{0: string, 1: int, 2: string, 3: string}|null
	 *
	 * @spec openspec/changes/publications-name-their-responsible-organisation/tasks.md#task-2.2d
	 */
	private function publicationFile(Event $event): ?array {
		if (is_a($event, self::FILE_ADDED_EVENT) === false) {
			return null;
		}

		$data = $event->getData();
		$register = (string)($data['register'] ?? '');
		$schema = (string)($data['schema'] ?? '');
		$configuredRegister = trim($this->config->getValueString('opencatalogi', 'publication_register', ''));
		$configuredSchema = trim($this->config->getValueString('opencatalogi', 'publication_schema', ''));
		if ($schema === '' || $schema !== $configuredSchema || $register !== $configuredRegister) {
			return null;
		}

		$uuid = (string)$event->getObjectUuid();
		$fileId = (int)$event->getFileId();
		if ($uuid === '' || $fileId <= 0) {
			return null;
		}

		return [$uuid, $fileId, $register, $schema];
	}//end publicationFile()
}//end class
