<?php

/**
 * Contract of AttachmentReadingStore, for callers in other components.
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
 * Contract of AttachmentReadingStore, for callers in other components.
 *
 * Callers outside Service/ depend on this interface instead of on the class.
 *
 * @spec openspec/changes/scanned-attachments-become-searchable/specs/search/spec.md#requirement-a-published-scan-is-read-by-ocr-so-search-finds-its-words-req-socr-001
 */
interface AttachmentReadingStoreInterface {
	/**
	 * Record the reading state of one attachment, replacing the one recorded before.
	 *
	 * @param int    $fileId        The Nextcloud file id.
	 * @param string $publicationId The publication.
	 * @param string $state         One of the AttachmentReadingJob::STATE_* values.
	 * @param string $reason        Why, when the state alone does not say it.
	 *
	 * @return void
	 *
	 * @throws \RuntimeException When OpenRegister or the register configuration is not there.
	 *
	 * @spec openspec/changes/scanned-attachments-become-searchable/specs/search/spec.md#requirement-a-published-scan-is-read-by-ocr-so-search-finds-its-words-req-socr-001
	 */
	public function record(int $fileId, string $publicationId, string $state, string $reason): void;

	/**
	 * The reading states recorded for one publication's attachments.
	 *
	 * @param string $publicationId The publication.
	 *
	 * @return array<int, array{fileId: int, state: string, reason: string, updatedAt: string}> One entry per file.
	 *
	 * @throws \RuntimeException When OpenRegister or the register configuration is not there.
	 *
	 * @spec openspec/changes/scanned-attachments-become-searchable/specs/search/spec.md#requirement-the-file-list-shows-whether-search-can-read-each-attachment-req-socr-004
	 */
	public function forPublication(string $publicationId): array;

	/**
	 * Whether the register and the attachmentReading schema are configured.
	 *
	 * @return bool
	 *
	 * @spec openspec/changes/scanned-attachments-become-searchable/specs/search/spec.md#requirement-the-file-list-shows-whether-search-can-read-each-attachment-req-socr-004
	 */
	public function isConfigured(): bool;
}//end interface
