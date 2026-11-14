<?php

/**
 * What a stored file is, read from its own bytes.
 *
 * @category Service
 * @package  OCA\OpenCatalogi\Service
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
 * @spec openspec/changes/published-file-carries-its-facts/specs/woo-compliance/spec.md#requirement-a-files-type-comes-from-its-bytes-req-pff-001
 */

namespace OCA\OpenCatalogi\Service;

use OCP\Files\File;
use OCP\Files\IMimeTypeDetector;
use OCP\Files\IRootFolder;
use Throwable;

/**
 * Detects a file's MIME type from its first bytes, never from its name, and
 * maps it to the DiWoo format list.
 *
 * @spec openspec/changes/published-file-carries-its-facts/specs/woo-compliance/spec.md#requirement-a-files-type-comes-from-its-bytes-req-pff-001
 */
class FileFacts {

	/**
	 * How many bytes are read for detection.
	 *
	 * @var integer
	 */
	public const SNIFF_BYTES = 8192;

	/**
	 * MIME type to the code of the EU file-type authority the DiWoo format list
	 * admits. A type not listed here has no DiWoo format.
	 *
	 * @var array<string, string>
	 */
	public const DIWOO_FORMATS = [
		'application/pdf' => 'PDF',
		'application/msword' => 'DOC',
		'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => 'DOCX',
		'application/vnd.ms-excel' => 'XLS',
		'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' => 'XLSX',
		'application/vnd.ms-powerpoint' => 'PPT',
		'application/vnd.openxmlformats-officedocument.presentationml.presentation' => 'PPTX',
		'application/vnd.openxmlformats-officedocument.presentationml.slideshow' => 'PPSX',
		'application/vnd.oasis.opendocument.text' => 'ODT',
		'application/vnd.oasis.opendocument.spreadsheet' => 'ODS',
		'application/vnd.oasis.opendocument.presentation' => 'ODP',
		'application/rtf' => 'RTF',
		'text/rtf' => 'RTF',
		'text/csv' => 'CSV',
		'text/plain' => 'TXT',
		'text/html' => 'HTML',
		'application/zip' => 'ZIP',
		'application/x-7z-compressed' => '7z',
	];

	/**
	 * Constructor.
	 *
	 * @param IMimeTypeDetector $detector   Nextcloud's content-based detector.
	 * @param IRootFolder       $rootFolder Resolves a stored file by its id.
	 */
	public function __construct(
		private readonly IMimeTypeDetector $detector,
		private readonly IRootFolder $rootFolder,
	) {
	}//end __construct()

	/**
	 * The MIME type of a file's first 8 KB, or null when they cannot be read.
	 *
	 * @param File $file The stored file.
	 *
	 * @return string|null
	 *
	 * @spec openspec/changes/published-file-carries-its-facts/specs/woo-compliance/spec.md#requirement-a-files-type-comes-from-its-bytes-req-pff-001
	 */
	public function mimeTypeOf(File $file): ?string {
		try {
			$handle = $file->fopen('r');
			if (is_resource($handle) === false) {
				return null;
			}

			try {
				$bytes = fread($handle, self::SNIFF_BYTES);
			} finally {
				fclose($handle);
			}
		} catch (Throwable) {
			return null;
		}

		if (is_string($bytes) === false || $bytes === '') {
			return null;
		}

		$type = strtolower(trim($this->detector->detectString($bytes)));
		if ($type === '') {
			return null;
		}

		return $type;
	}//end mimeTypeOf()

	/**
	 * The MIME type of the stored file with this id, or null when there is no
	 * such file or its bytes cannot be read.
	 *
	 * @param mixed $fileId The Nextcloud file id.
	 *
	 * @return string|null
	 *
	 * @spec openspec/changes/published-file-carries-its-facts/specs/woo-compliance/spec.md#requirement-a-files-type-comes-from-its-bytes-req-pff-001
	 */
	public function mimeTypeOfFileId(mixed $fileId): ?string {
		if (is_int($fileId) === false && (is_string($fileId) === false || ctype_digit($fileId) === false)) {
			return null;
		}

		try {
			$node = $this->rootFolder->getFirstNodeById((int)$fileId);
		} catch (Throwable) {
			return null;
		}

		if ($node instanceof File === false) {
			return null;
		}

		return $this->mimeTypeOf(file: $node);
	}//end mimeTypeOfFileId()

	/**
	 * The MIME type of a file as OpenRegister lists it (its Nextcloud `id`), from
	 * the stored bytes; the name and the listed type are not read.
	 *
	 * @param array<string, mixed> $file The file metadata.
	 *
	 * @return string|null
	 *
	 * @spec openspec/changes/published-file-carries-its-facts/specs/woo-compliance/spec.md#requirement-a-files-type-comes-from-its-bytes-req-pff-001
	 */
	public function mimeTypeOfListedFile(array $file): ?string {
		return $this->mimeTypeOfFileId(fileId: ($file['id'] ?? null));
	}//end mimeTypeOfListedFile()

	/**
	 * The DiWoo format code of a MIME type, or null when the list has none.
	 *
	 * @param string|null $mimeType The MIME type.
	 *
	 * @return string|null
	 *
	 * @spec openspec/changes/published-file-carries-its-facts/specs/woo-compliance/spec.md#requirement-a-files-type-comes-from-its-bytes-req-pff-001
	 */
	public static function diwooFormat(?string $mimeType): ?string {
		if ($mimeType === null) {
			return null;
		}

		$type = strtolower(trim(explode(';', $mimeType)[0]));
		return (self::DIWOO_FORMATS[$type] ?? null);
	}//end diwooFormat()
}//end class
