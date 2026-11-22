<?php

/**
 * Contract of AttachmentReadingOverview, for callers in other components.
 *
 * @category Contract
 * @package  OCA\OpenCatalogi\Contract
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

namespace OCA\OpenCatalogi\Contract;

/**
 * Contract of AttachmentReadingOverview, for callers in other components.
 *
 * Callers outside Service/ depend on this interface instead of on the class.
 *
 * @spec openspec/changes/scanned-attachments-become-searchable/specs/search/spec.md#requirement-the-file-list-shows-whether-search-can-read-each-attachment-req-socr-004
 */
interface AttachmentReadingOverviewInterface {
	/**
	 * The publication, read with the caller's rights; refuses one the caller cannot read.
	 *
	 * @param string $publicationId The publication's uuid.
	 *
	 * @return object The publication, to hand to readings().
	 *
	 * @throws \OCA\OpenCatalogi\Exception\AttachmentReadingRefusedException 404 when the caller cannot read it,
	 *                                                                     503 when reading states are not set up.
	 *
	 * @spec openspec/changes/scanned-attachments-become-searchable/specs/search/spec.md#requirement-the-file-list-shows-whether-search-can-read-each-attachment-req-socr-004
	 */
	public function requireReadable(string $publicationId): object;

	/**
	 * The publication, when the caller may also update it; refuses otherwise.
	 *
	 * @param string $publicationId The publication's uuid.
	 *
	 * @return object The publication, to hand to readAgain().
	 *
	 * @throws \OCA\OpenCatalogi\Exception\AttachmentReadingRefusedException 404, 403 without the update right, or 503.
	 *
	 * @spec openspec/changes/scanned-attachments-become-searchable/specs/search/spec.md#requirement-the-file-list-shows-whether-search-can-read-each-attachment-req-socr-004
	 */
	public function requireUpdatable(string $publicationId): object;

	/**
	 * Whether search can read each attachment of a publication, and whether the caller may ask for a new reading.
	 *
	 * @param object $publication   The publication requireReadable() returned.
	 * @param string $publicationId The publication's uuid.
	 *
	 * @return array{readings: array<int, array{fileId: int, state: string, reason: string, updatedAt: string}>, canReadAgain: bool}
	 *
	 * @throws \OCA\OpenCatalogi\Exception\AttachmentReadingRefusedException 503 when the states cannot be read.
	 *
	 * @spec openspec/changes/scanned-attachments-become-searchable/specs/search/spec.md#requirement-the-file-list-shows-whether-search-can-read-each-attachment-req-socr-004
	 */
	public function readings(object $publication, string $publicationId): array;

	/**
	 * Queue a new reading of one attachment that was read before ("Read again").
	 *
	 * @param object $publication   The publication requireUpdatable() returned.
	 * @param string $publicationId The publication's uuid.
	 * @param int    $fileId        The Nextcloud file id of the attachment.
	 *
	 * @return array{fileId: int, queued: bool}
	 *
	 * @throws \OCA\OpenCatalogi\Exception\AttachmentReadingRefusedException 404 without a reading of that file, or 503.
	 *
	 * @spec openspec/changes/scanned-attachments-become-searchable/specs/search/spec.md#requirement-the-file-list-shows-whether-search-can-read-each-attachment-req-socr-004
	 */
	public function readAgain(object $publication, string $publicationId, int $fileId): array;
}//end interface
