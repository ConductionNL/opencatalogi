<?php

/**
 * Stub for OCA\OpenRegister\Files\Db\FileMapper.
 *
 * @category Test
 * @package  OCA\OpenCatalogi\Tests\Stubs
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Files\Db;

use OCA\OpenRegister\Db\ObjectEntity;

/**
 * Minimal stub for FileMapper.
 */
class FileMapper {

	/**
	 * Stub for findByFileId.
	 *
	 * @param integer $fileId The file ID.
	 *
	 * @return mixed
	 */
	public function findByFileId(int $fileId): mixed {
		return null;
	}//end findByFileId()

	/**
	 * Stub for getFiles.
	 *
	 * @param integer|null $node The parent folder id, or null for any.
	 * @param array<int>|null $ids The file ids, or null for any.
	 *
	 * @return array<mixed>
	 */
	public function getFiles(?int $node = null, ?array $ids = null): array {
		return [];
	}//end getFiles()

	/**
	 * Stub for getFilesForObject.
	 *
	 * @param ObjectEntity $object The object entity.
	 *
	 * @return array<mixed>
	 */
	public function getFilesForObject(ObjectEntity $object): array {
		return [];
	}//end getFilesForObject()

	/**
	 * Stub for findByFileIds: the metadata rows of the files, keyed by file id.
	 *
	 * @param array<int> $fileIds The file ids.
	 *
	 * @return array<int, mixed>
	 */
	public function findByFileIds(array $fileIds): array {
		return [];
	}//end findByFileIds()

	/**
	 * Stub for setPublicationWindowForFile.
	 *
	 * @param integer           $fileId The file id.
	 * @param PublicationWindow $window The window.
	 *
	 * @return mixed
	 */
	public function setPublicationWindowForFile(int $fileId, PublicationWindow $window): mixed {
		return null;
	}//end setPublicationWindowForFile()

}//end class
