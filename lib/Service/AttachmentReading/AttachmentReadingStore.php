<?php

/**
 * The attachmentReading objects, read and written as the system.
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
 * @spec openspec/changes/scanned-attachments-become-searchable/specs/search/spec.md#requirement-a-published-scan-is-read-by-ocr-so-search-finds-its-words-req-socr-001
 */

declare(strict_types=1);

namespace OCA\OpenCatalogi\Service\AttachmentReading;

use DateTimeImmutable;
use OCA\OpenCatalogi\Contract\AttachmentReadingStoreInterface;
use OCA\OpenCatalogi\Contract\OpenRegisterLookupInterface;
use OCA\OpenCatalogi\Service\ReadsObjectProperties;
use OCA\OpenRegister\Kernel\Contracts\Value\ObjectFindTarget;
use OCA\OpenRegister\Kernel\Contracts\Value\ObjectSaveOptions;
use OCP\IAppConfig;
use RuntimeException;

/**
 * Upserts the one reading-state object of a published attachment.
 *
 * @spec openspec/changes/scanned-attachments-become-searchable/specs/search/spec.md#requirement-a-published-scan-is-read-by-ocr-so-search-finds-its-words-req-socr-001
 */
class AttachmentReadingStore implements AttachmentReadingStoreInterface {
	use ReadsObjectProperties;

	/**
	 * The app-config key of the attachmentReading schema.
	 *
	 * @var string
	 */
	public const SCHEMA_KEY = 'attachment_reading_schema';

	/**
	 * Constructor.
	 *
	 * @param IAppConfig $config Holds the register and schema ids.
	 * @param OpenRegisterLookupInterface $openRegister OpenRegister's ObjectService.
	 */
	public function __construct(
		private readonly IAppConfig $config,
		private readonly OpenRegisterLookupInterface $openRegister,
	) {

	}//end __construct()

	/**
	 * Record the reading state of one attachment, replacing the one recorded before.
	 *
	 * @param int $fileId The Nextcloud file id.
	 * @param string $publicationId The publication.
	 * @param string $state One of the AttachmentReadingJob::STATE_* values.
	 * @param string $reason Why, when the state alone does not say it.
	 *
	 * @return void
	 *
	 * @throws RuntimeException When OpenRegister or the register configuration is not there.
	 *
	 * @spec openspec/changes/scanned-attachments-become-searchable/specs/search/spec.md#requirement-a-published-scan-is-read-by-ocr-so-search-finds-its-words-req-socr-001
	 */
	public function record(int $fileId, string $publicationId, string $state, string $reason): void {
		if ($this->openRegister->isInstalled() === false) {
			throw new RuntimeException('OpenRegister is not available, so the reading state cannot be written.');
		}

		$this->save(
			objects: $this->openRegister->objectService(),
			object: [
				'fileId' => $fileId,
				'publication' => $publicationId,
				'state' => $state,
				'reason' => $reason,
				'updatedAt' => (new DateTimeImmutable())->format('c'),
			]
		);

	}//end record()

	/**
	 * The reading states recorded for one publication's attachments.
	 *
	 * Read as the system: the caller has already checked that the user may read
	 * the publication, and the reading-state objects carry no text of the file.
	 *
	 * @param string $publicationId The publication.
	 *
	 * @return array<int, array{fileId: int, state: string, reason: string, updatedAt: string}> One entry per file, the newest per file.
	 *
	 * @throws RuntimeException When OpenRegister or the register configuration is not there.
	 *
	 * @spec openspec/changes/scanned-attachments-become-searchable/specs/search/spec.md#requirement-the-file-list-shows-whether-search-can-read-each-attachment-req-socr-004
	 */
	public function forPublication(string $publicationId): array {
		if ($this->openRegister->isInstalled() === false) {
			throw new RuntimeException('OpenRegister is not available, so the reading states cannot be read.');
		}

		$target = $this->target();
		$result = $this->openRegister->objectService()->searchObjectsPaginated(
			query: ['@self' => ['register' => $target[0], 'schema' => $target[1]], 'publication' => $publicationId, '_limit' => 500],
			_rbac: false,
			_multitenancy: false
		);

		$readings = [];
		foreach ((array)($result['results'] ?? []) as $row) {
			$reading = $this->readingOf(stored: $this->asRecord(object: $row), publicationId: $publicationId);
			if ($reading !== null && $this->isNewer(reading: $reading, known: $readings[$reading['fileId']] ?? null) === true) {
				$readings[$reading['fileId']] = $reading;
			}
		}

		return array_values($readings);

	}//end forPublication()

	/**
	 * Whether the register and the attachmentReading schema are configured.
	 *
	 * @return bool
	 *
	 * @spec openspec/changes/scanned-attachments-become-searchable/specs/search/spec.md#requirement-the-file-list-shows-whether-search-can-read-each-attachment-req-socr-004
	 */
	public function isConfigured(): bool {
		try {
			$this->target();
		} catch (RuntimeException $e) {
			return false;
		}

		return true;

	}//end isConfigured()

	/**
	 * The reading state a stored object holds, or null when it is not one of the publication.
	 *
	 * @param array<string, mixed> $stored        The stored object.
	 * @param string               $publicationId The publication.
	 *
	 * @return array{fileId: int, state: string, reason: string, updatedAt: string}|null
	 */
	private function readingOf(array $stored, string $publicationId): ?array {
		$fileId = (int)($stored['fileId'] ?? 0);
		$state = (string)($stored['state'] ?? '');
		if ($fileId <= 0 || $state === '' || (string)($stored['publication'] ?? '') !== $publicationId) {
			return null;
		}

		return [
			'fileId' => $fileId,
			'state' => $state,
			'reason' => (string)($stored['reason'] ?? ''),
			'updatedAt' => (string)($stored['updatedAt'] ?? ''),
		];

	}//end readingOf()

	/**
	 * Whether a reading is newer than the one already kept for its file.
	 *
	 * @param array{updatedAt: string}      $reading The reading.
	 * @param array{updatedAt: string}|null $known   The one kept, if any.
	 *
	 * @return bool
	 */
	private function isNewer(array $reading, ?array $known): bool {
		return $known === null || strcmp($reading['updatedAt'], $known['updatedAt']) > 0;

	}//end isNewer()

	/**
	 * Save the reading state over the object already recorded for its file, if any.
	 *
	 * @param object               $objects OpenRegister's ObjectService.
	 * @param array<string, mixed> $object  The reading state, its fileId included.
	 *
	 * @return void
	 */
	private function save(object $objects, array $object): void {
		[$register, $schema] = $this->target();
		$objects->saveObject(
			object: $object,
			options: new ObjectSaveOptions(
				target: new ObjectFindTarget(register: $register, schema: $schema),
				uuid: $this->existingId(objects: $objects, fileId: (int)$object['fileId'])
			),
			_rbac: false,
			_multitenancy: false
		);

	}//end save()

	/**
	 * The id of the object already recorded for the file, or null.
	 *
	 * @param object $objects OpenRegister's ObjectService.
	 * @param int $fileId The Nextcloud file id.
	 *
	 * @return string|null The id.
	 */
	private function existingId(object $objects, int $fileId): ?string {
		$target = $this->target();
		$result = $objects->searchObjectsPaginated(
			query: ['@self' => ['register' => $target[0], 'schema' => $target[1]], 'fileId' => $fileId, '_limit' => 10],
			_rbac: false,
			_multitenancy: false
		);

		foreach ((array)($result['results'] ?? []) as $row) {
			$id = $this->idFor(stored: $this->asRecord(object: $row), fileId: $fileId);
			if ($id !== null) {
				return $id;
			}
		}

		return null;

	}//end existingId()

	/**
	 * The id of a stored reading state when it is the one of the file, else null.
	 *
	 * @param array<string, mixed> $stored The stored reading state.
	 * @param int                  $fileId The Nextcloud file id.
	 *
	 * @return string|null
	 */
	private function idFor(array $stored, int $fileId): ?string {
		$id = (string)($stored['id'] ?? '');
		if ((int)($stored['fileId'] ?? 0) !== $fileId || $id === '') {
			return null;
		}

		return $id;

	}//end idFor()

	/**
	 * The publication register and the attachmentReading schema from the app config.
	 *
	 * @return array{0: string, 1: string}
	 *
	 * @throws RuntimeException When either is not configured.
	 */
	private function target(): array {
		$register = trim($this->config->getValueString('opencatalogi', 'publication_register', ''));
		$schema = trim($this->config->getValueString('opencatalogi', self::SCHEMA_KEY, ''));
		if ($register === '' || $schema === '') {
			throw new RuntimeException('Register configuration is not set for publication_register / ' . self::SCHEMA_KEY . '.');
		}

		return [$register, $schema];

	}//end target()
}//end class
