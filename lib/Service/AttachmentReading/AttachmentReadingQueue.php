<?php

/**
 * Queues the reading of a published attachment.
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

use OCA\OpenCatalogi\BackgroundJob\AttachmentReadingJob;
use OCP\BackgroundJob\IJobList;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Adds an AttachmentReadingJob, so a publish never waits on reading.
 *
 * @spec openspec/changes/scanned-attachments-become-searchable/specs/search/spec.md#requirement-a-published-scan-is-read-by-ocr-so-search-finds-its-words-req-socr-001
 */
class AttachmentReadingQueue {

	/**
	 * Constructor.
	 *
	 * @param IJobList $jobList The background job list.
	 * @param LoggerInterface $logger Records a job that could not be queued.
	 */
	public function __construct(
		private readonly IJobList $jobList,
		private readonly LoggerInterface $logger,
	) {

	}//end __construct()

	/**
	 * Queue the reading of one published attachment.
	 *
	 * A failure to queue is logged and never thrown: the publish has already happened.
	 *
	 * @param int $fileId The Nextcloud file id that is published.
	 * @param string $publicationId The publication.
	 *
	 * @return bool Whether the job was queued.
	 *
	 * @spec openspec/changes/scanned-attachments-become-searchable/specs/search/spec.md#requirement-a-published-scan-is-read-by-ocr-so-search-finds-its-words-req-socr-001
	 */
	public function queue(int $fileId, string $publicationId): bool {
		return $this->add(fileId: $fileId, publicationId: $publicationId, force: false);

	}//end queue()

	/**
	 * Queue a fresh reading of an attachment that was read before ("Read again").
	 *
	 * @param int $fileId The Nextcloud file id that is published.
	 * @param string $publicationId The publication.
	 *
	 * @return bool Whether the job was queued.
	 *
	 * @spec openspec/changes/scanned-attachments-become-searchable/specs/search/spec.md#requirement-the-file-list-shows-whether-search-can-read-each-attachment-req-socr-004
	 */
	public function queueAgain(int $fileId, string $publicationId): bool {
		return $this->add(fileId: $fileId, publicationId: $publicationId, force: true);

	}//end queueAgain()

	/**
	 * Add the job.
	 *
	 * @param int $fileId The Nextcloud file id.
	 * @param string $publicationId The publication.
	 * @param bool $force Whether to read the file again.
	 *
	 * @return bool Whether the job was queued.
	 */
	private function add(int $fileId, string $publicationId, bool $force): bool {
		if ($fileId <= 0 || $publicationId === '') {
			return false;
		}

		try {
			$this->jobList->add(
				AttachmentReadingJob::class,
				['fileId' => $fileId, 'publication' => $publicationId, 'force' => $force]
			);
		} catch (Throwable $e) {
			$this->logger->warning(
				'[AttachmentReadingQueue] the reading job could not be queued',
				['fileId' => $fileId, 'exception' => $e->getMessage()]
			);
			return false;
		}

		return true;

	}//end add()
}//end class
