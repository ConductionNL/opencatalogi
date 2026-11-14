<?php

/**
 * A FileFacts whose stored bytes always match the file's extension.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/changes/published-file-carries-its-facts/specs/woo-compliance/spec.md#requirement-a-files-type-comes-from-its-bytes-req-pff-001
 */

namespace OCA\OpenCatalogi\Tests\Unit\Service\Support;

use OCA\OpenCatalogi\Service\FileFacts;
use OCP\Files\IMimeTypeDetector;
use OCP\Files\IRootFolder;
use PHPUnit\Framework\TestCase;

/**
 * For the sitemap tests that predate byte detection: each file's bytes are
 * what its extension says, so their recorded output is unchanged. Tests of
 * the detection itself use the real FileFacts over OCP doubles.
 */
final class FileFactsByExtension extends FileFacts {
	/**
	 * Build one over unused OCP doubles.
	 *
	 * @param TestCase $test The test that needs it.
	 *
	 * @return self
	 */
	public static function make(TestCase $test): self {
		$mock = new \ReflectionMethod($test, 'createMock');
		return new self($mock->invoke($test, IMimeTypeDetector::class), $mock->invoke($test, IRootFolder::class));
	}//end make()

	/**
	 * The MIME type the extension names.
	 *
	 * @param array<string, mixed> $file The file metadata.
	 *
	 * @return string|null
	 */
	public function mimeTypeOfListedFile(array $file): ?string {
		$code = strtoupper(trim((string)($file['extension'] ?? '')));
		$type = array_search($code, self::DIWOO_FORMATS, true);
		if ($type === false) {
			return 'application/x-' . strtolower($code);
		}

		return $type;
	}//end mimeTypeOfListedFile()
}//end class
