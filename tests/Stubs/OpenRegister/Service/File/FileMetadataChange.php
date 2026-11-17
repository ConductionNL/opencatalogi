<?php

/**
 * Stub for OCA\OpenRegister\Service\File\FileMetadataChange.
 *
 * @category Test
 * @package  OCA\OpenCatalogi\Tests\Stubs
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\File;

/**
 * Mirrors the real value object: the file and the description, category and labels to write (null skips).
 */
final class FileMetadataChange {

	/**
	 * Constructor.
	 *
	 * @param int                $fileId      The Nextcloud filecache fileid.
	 * @param string|null        $description New description; null skips.
	 * @param string|null        $category    New category; null skips.
	 * @param array<string>|null $labels      New labels; null skips.
	 */
	public function __construct(
		public readonly int $fileId,
		public readonly ?string $description = null,
		public readonly ?string $category = null,
		public readonly ?array $labels = null,
	) {
	}//end __construct()
}//end class
