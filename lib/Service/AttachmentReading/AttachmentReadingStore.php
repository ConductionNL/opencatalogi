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
use OCA\OpenCatalogi\Service\OpenRegisterLookup;
use OCA\OpenCatalogi\Service\ReadsObjectProperties;
use OCP\IAppConfig;
use RuntimeException;

/**
 * Upserts the one reading-state object of a published attachment.
 *
 * @spec openspec/changes/scanned-attachments-become-searchable/specs/search/spec.md#requirement-a-published-scan-is-read-by-ocr-so-search-finds-its-words-req-socr-001
 */
class AttachmentReadingStore {
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
	 * @param OpenRegisterLookup $openRegister OpenRegister's ObjectService.
	 */
	public function __construct(
		private readonly IAppConfig $config,
		private readonly OpenRegisterLookup $openRegister,
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

		$objects = $this->openRegister->objectService();
		[$register, $schema] = $this->target();

		$object = [
			'fileId' => $fileId,
			'publication' => $publicationId,
			'state' => $state,
			'reason' => $reason,
			'updatedAt' => (new DateTimeImmutable())->format('c'),
		];

		$objects->saveObject(
			object: $object,
			register: $register,
			schema: $schema,
			uuid: $this->existingId(objects: $objects, target: [$register, $schema], fileId: $fileId),
			_rbac: false,
			_multitenancy: false
		);

	}//end record()

	/**
	 * The id of the object already recorded for the file, or null.
	 *
	 * @param object $objects OpenRegister's ObjectService.
	 * @param array{0: string, 1: string} $target The register and the schema.
	 * @param int $fileId The Nextcloud file id.
	 *
	 * @return string|null The id.
	 */
	private function existingId(object $objects, array $target, int $fileId): ?string {
		$result = $objects->searchObjectsPaginated(
			query: ['@self' => ['register' => $target[0], 'schema' => $target[1]], 'fileId' => $fileId, '_limit' => 10],
			_rbac: false,
			_multitenancy: false
		);

		foreach ((array)($result['results'] ?? []) as $row) {
			$stored = $this->asRecord(object: $row);
			if ((int)($stored['fileId'] ?? 0) !== $fileId) {
				continue;
			}

			$id = (string)($stored['id'] ?? '');
			if ($id !== '') {
				return $id;
			}
		}

		return null;

	}//end existingId()

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
