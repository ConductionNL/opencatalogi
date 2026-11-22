<?php

/**
 * Contract of AttachmentReadingQueue, for callers in other components.
 *
 * @category Contract
 * @package  OCA\OpenCatalogi\Contract
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
 * @spec openspec/changes/scanned-attachments-become-searchable/specs/search/spec.md#requirement-a-published-scan-is-read-by-ocr-so-search-finds-its-words-req-socr-001
 */

declare(strict_types=1);

namespace OCA\OpenCatalogi\Contract;

/**
 * Contract of AttachmentReadingQueue, for callers in other components.
 *
 * Callers outside BackgroundJob/ depend on this interface instead of on the class.
 *
 * @spec openspec/changes/scanned-attachments-become-searchable/specs/search/spec.md#requirement-a-published-scan-is-read-by-ocr-so-search-finds-its-words-req-socr-001
 */
interface AttachmentReadingQueueInterface {
	/**
	 * Queue the reading of one published attachment.
	 *
	 * @param int    $fileId        The Nextcloud file id that is published.
	 * @param string $publicationId The publication.
	 *
	 * @return bool Whether the job was queued.
	 *
	 * @spec openspec/changes/scanned-attachments-become-searchable/specs/search/spec.md#requirement-a-published-scan-is-read-by-ocr-so-search-finds-its-words-req-socr-001
	 */
	public function queue(int $fileId, string $publicationId): bool;

	/**
	 * Queue a fresh reading of an attachment that was read before ("Read again").
	 *
	 * @param int    $fileId        The Nextcloud file id that is published.
	 * @param string $publicationId The publication.
	 *
	 * @return bool Whether the job was queued.
	 *
	 * @spec openspec/changes/scanned-attachments-become-searchable/specs/search/spec.md#requirement-the-file-list-shows-whether-search-can-read-each-attachment-req-socr-004
	 */
	public function queueAgain(int $fileId, string $publicationId): bool;
}//end interface
