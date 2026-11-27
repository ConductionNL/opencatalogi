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

use OCA\OpenCatalogi\Contract\PublisherFileStampInterface;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\IAppConfig;

/**
 * Hands a file OpenRegister's files API added to a publication
 * (`FileAddedEvent`, dispatched by `files#create`, `files#createMultipart`
 * and the create-from-URL route) to the publisher stamp. Files added to any
 * other object are left alone.
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
	 * @param IAppConfig                  $config Names the publication register and schema.
	 * @param PublisherFileStampInterface $stamp  Writes the labels and stores the RSIN.
	 *
	 * @spec exclude pure framework plumbing: constructor dependency injection.
	 */
	public function __construct(
		private readonly IAppConfig $config,
		private readonly PublisherFileStampInterface $stamp,
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
		if (is_a($event, self::FILE_ADDED_EVENT) === false) {
			return;
		}

		$data = $event->getData();
		$register = (string)($data['register'] ?? '');
		$schema = (string)($data['schema'] ?? '');
		$uuid = (string)$event->getObjectUuid();
		$fileId = (int)$event->getFileId();
		if ($this->isPublication(register: $register, schema: $schema) === false || $uuid === '' || $fileId <= 0) {
			return;
		}

		$this->stamp->stampUploaded(publicationId: $uuid, fileId: $fileId, register: $register, schema: $schema);

	}//end handle()

	/**
	 * Whether the object the file was added to is in the publication register and schema.
	 *
	 * @param string $register The object's register id.
	 * @param string $schema   The object's schema id.
	 *
	 * @return bool
	 *
	 * @spec openspec/changes/publications-name-their-responsible-organisation/tasks.md#task-2.2d
	 */
	private function isPublication(string $register, string $schema): bool {
		$configuredRegister = trim($this->config->getValueString('opencatalogi', 'publication_register', ''));
		$configuredSchema = trim($this->config->getValueString('opencatalogi', 'publication_schema', ''));

		return $schema !== '' && $schema === $configuredSchema && $register === $configuredRegister;
	}//end isPublication()
}//end class
