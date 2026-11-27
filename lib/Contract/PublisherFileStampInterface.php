<?php

/**
 * Contract of PublisherFileStamp, for callers in other components.
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
 */

declare(strict_types=1);

namespace OCA\OpenCatalogi\Contract;

/**
 * Contract of PublisherFileStamp, for callers in other components.
 *
 * Callers outside Service/ depend on this interface instead of on the class.
 */
interface PublisherFileStampInterface {
	/**
	 * Stamp the publisher's labels onto a file uploaded to a publication, and
	 * store the publisher's RSIN on the publication. A failure is logged,
	 * never thrown: the upload stands.
	 *
	 * @param string $publicationId The publication's uuid.
	 * @param int    $fileId        The uploaded file.
	 * @param string $register      The publication register.
	 * @param string $schema        The publication schema.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/publications-name-their-responsible-organisation/tasks.md#task-2.2d
	 * @contract-shift internal-only - added for the upload path; only this app implements and calls it.
	 */
	public function stampUploaded(string $publicationId, int $fileId, string $register, string $schema): void;
}//end interface
