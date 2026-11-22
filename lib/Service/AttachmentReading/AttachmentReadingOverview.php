<?php

/**
 * Whether search can read each attachment of a publication, for its file list.
 *
 * @category Service
 * @package  OCA\OpenCatalogi\Service\AttachmentReading
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenCatalogi.nl
 *
 * @spec openspec/changes/scanned-attachments-become-searchable/specs/search/spec.md#requirement-the-file-list-shows-whether-search-can-read-each-attachment-req-socr-004
 */

declare(strict_types=1);

namespace OCA\OpenCatalogi\Service\AttachmentReading;

use OCA\OpenCatalogi\Contract\AttachmentReadingOverviewInterface;
use OCA\OpenCatalogi\Contract\AttachmentReadingQueueInterface;
use OCA\OpenCatalogi\Contract\AttachmentReadingStoreInterface;
use OCA\OpenCatalogi\Contract\OpenRegisterLookupInterface;
use OCA\OpenCatalogi\Contract\PublicationRightsInterface;
use OCA\OpenCatalogi\Exception\AttachmentReadingRefusedException;
use OCA\OpenRegister\Kernel\Contracts\Value\ObjectFindOptions;
use Throwable;

/**
 * Reads the reading states of a publication the caller may read, and queues a new
 * reading for a caller who may update it.
 *
 * @spec openspec/changes/scanned-attachments-become-searchable/specs/search/spec.md#requirement-the-file-list-shows-whether-search-can-read-each-attachment-req-socr-004
 */
class AttachmentReadingOverview implements AttachmentReadingOverviewInterface {

	/**
	 * Constructor.
	 *
	 * @param AttachmentReadingStoreInterface         The recorded reading states.
	 * @param AttachmentReadingQueueInterface         Queues the reading job.
	 * @param PublicationRightsInterface             The update right on one publication.
	 * @param OpenRegisterLookupInterface      OpenRegister's ObjectService and the publication register.
	 */
	public function __construct(
		private readonly AttachmentReadingStoreInterface $store,
		private readonly AttachmentReadingQueueInterface $queue,
		private readonly PublicationRightsInterface $rights,
		private readonly OpenRegisterLookupInterface $openRegister,
	) {

	}//end __construct()

	/**
	 * Whether search can read each attachment of a publication, and whether the caller may ask for a new reading.
	 *
	 * @param string $publicationId The publication's uuid.
	 *
	 * @return array{readings: array<int, array{fileId: int, state: string, reason: string, updatedAt: string}>, canReadAgain: bool}
	 *
	 * @throws AttachmentReadingRefusedException 404 when the caller cannot read the publication, 503 when reading states are not set up.
	 *
	 * @spec openspec/changes/scanned-attachments-become-searchable/specs/search/spec.md#requirement-the-file-list-shows-whether-search-can-read-each-attachment-req-socr-004
	 */
	public function forPublication(string $publicationId): array {
		$this->requireConfigured();
		$publication = $this->readablePublication(id: $publicationId);

		return [
			'readings' => $this->readings(publicationId: $publicationId),
			'canReadAgain' => $this->rights->mayUpdate(publication: $publication),
		];

	}//end forPublication()

	/**
	 * Queue a new reading of one attachment that was read before ("Read again").
	 *
	 * Only a file with a recorded reading for this publication can be read again,
	 * so the route cannot be used to read files that were never published with it.
	 *
	 * @param string $publicationId The publication's uuid.
	 * @param int    $fileId        The Nextcloud file id of the attachment.
	 *
	 * @return array{fileId: int, queued: bool}
	 *
	 * @throws AttachmentReadingRefusedException 404 when the publication or the reading is not there, 403 without the update right, 503 when not set up or not queued.
	 *
	 * @spec openspec/changes/scanned-attachments-become-searchable/specs/search/spec.md#requirement-the-file-list-shows-whether-search-can-read-each-attachment-req-socr-004
	 */
	public function readAgain(string $publicationId, int $fileId): array {
		$this->requireConfigured();
		$publication = $this->readablePublication(id: $publicationId);
		if ($this->rights->mayUpdate(publication: $publication) === false) {
			throw new AttachmentReadingRefusedException(error: 'forbidden', status: 403);
		}

		$known = array_filter(
			$this->readings(publicationId: $publicationId),
			static fn (array $reading): bool => $reading['fileId'] === $fileId
		);
		if ($known === []) {
			throw new AttachmentReadingRefusedException(error: 'not-found', status: 404);
		}

		if ($this->queue->queueAgain(fileId: $fileId, publicationId: $publicationId) === false) {
			throw new AttachmentReadingRefusedException(error: 'not-queued', status: 503);
		}

		return ['fileId' => $fileId, 'queued' => true];

	}//end readAgain()

	/**
	 * The publication's reading states.
	 *
	 * @param string $publicationId The publication's uuid.
	 *
	 * @return array<int, array{fileId: int, state: string, reason: string, updatedAt: string}>
	 *
	 * @throws AttachmentReadingRefusedException 503 when they cannot be read.
	 */
	private function readings(string $publicationId): array {
		try {
			return $this->store->forPublication(publicationId: $publicationId);
		} catch (Throwable $e) {
			throw new AttachmentReadingRefusedException(error: 'not-configured', status: 503);
		}

	}//end readings()

	/**
	 * The publication read with RBAC on.
	 *
	 * Not there and not readable both answer 404, so an id nobody may read does
	 * not confirm that it exists.
	 *
	 * @param string $id The publication's uuid.
	 *
	 * @return object The publication.
	 *
	 * @throws AttachmentReadingRefusedException 404 when it cannot be read, 503 when the register is not reachable.
	 */
	private function readablePublication(string $id): object {
		try {
			$configuration = $this->openRegister->registerConfiguration(
				registerKey: 'publication_register',
				schemaKey: 'publication_schema'
			);
			$publication = $this->openRegister->objectService()->find(
				id: $id,
				options: new ObjectFindOptions(register: $configuration['register'], schema: $configuration['schema']),
				_rbac: true
			);
		} catch (Throwable $e) {
			if ($this->isMissingOrRefused(e: $e) === true) {
				throw new AttachmentReadingRefusedException(error: 'not-found', status: 404);
			}

			throw new AttachmentReadingRefusedException(error: 'not-configured', status: 503);
		}

		if (is_object($publication) === false) {
			throw new AttachmentReadingRefusedException(error: 'not-found', status: 404);
		}

		return $publication;

	}//end readablePublication()

	/**
	 * Refuse when reading states are not set up on this instance.
	 *
	 * @return void
	 *
	 * @throws AttachmentReadingRefusedException 503 when OpenRegister or the schema is missing.
	 */
	private function requireConfigured(): void {
		if ($this->openRegister->isInstalled() === false || $this->store->isConfigured() === false) {
			throw new AttachmentReadingRefusedException(error: 'not-configured', status: 503);
		}

	}//end requireConfigured()

	/**
	 * Whether OpenRegister answered that the object is not there or not for this caller.
	 *
	 * @param Throwable $e What OpenRegister threw.
	 *
	 * @return bool
	 */
	private function isMissingOrRefused(Throwable $e): bool {
		$class = get_class($e);
		return str_ends_with($class, 'NotAuthorizedException') === true || str_ends_with($class, 'DoesNotExistException') === true;

	}//end isMissingOrRefused()
}//end class
