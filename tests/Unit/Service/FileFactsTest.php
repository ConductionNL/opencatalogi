<?php

/**
 * Unit tests for FileFacts.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/changes/published-file-carries-its-facts/specs/woo-compliance/spec.md#requirement-a-files-type-comes-from-its-bytes-req-pff-001
 */

namespace OCA\OpenCatalogi\Tests\Unit\Service;

use OCA\OpenCatalogi\Service\FileFacts;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\IMimeTypeDetector;
use OCP\Files\IRootFolder;
use OCP\Files\NotPermittedException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * The type comes from the first 8 KB of the stored bytes, never from the name.
 */
#[CoversClass(FileFacts::class)]
class FileFactsTest extends TestCase {
	/**
	 * A PDF stored as besluit.docx is detected as a PDF.
	 *
	 * @return void
	 */
	public function testAPdfNamedDocxIsDetectedAsPdf(): void {
		$detector = $this->createMock(IMimeTypeDetector::class);
		$detector->expects($this->once())->method('detectString')->with('%PDF-1.7')->willReturn('application/pdf');
		$file = $this->storedFile(bytes: '%PDF-1.7');
		$file->expects($this->never())->method('getName');
		$file->expects($this->never())->method('getMimetype');

		$facts = new FileFacts($detector, $this->createMock(IRootFolder::class));

		$this->assertSame('application/pdf', $facts->mimeTypeOf(file: $file));
		$this->assertSame('PDF', FileFacts::diwooFormat(mimeType: 'application/pdf'));
	}

	/**
	 * Bytes that cannot be read, or are empty, give null without asking the detector.
	 *
	 * @return void
	 */
	public function testUnreadableBytesGiveNull(): void {
		$detector = $this->createMock(IMimeTypeDetector::class);
		$detector->expects($this->never())->method('detectString');
		$facts = new FileFacts($detector, $this->createMock(IRootFolder::class));

		$throwing = $this->createMock(File::class);
		$throwing->method('fopen')->willThrowException(new NotPermittedException('locked'));
		$closed = $this->createMock(File::class);
		$closed->method('fopen')->willReturn(false);

		$this->assertNull($facts->mimeTypeOf(file: $throwing));
		$this->assertNull($facts->mimeTypeOf(file: $closed));
		$this->assertNull($facts->mimeTypeOf(file: $this->storedFile(bytes: '')));
	}

	/**
	 * Only the first 8 KB reach the detector.
	 *
	 * @return void
	 */
	public function testOnlyTheFirst8KbAreRead(): void {
		$bytes = str_repeat('a', FileFacts::SNIFF_BYTES) . 'TAIL';
		$detector = $this->createMock(IMimeTypeDetector::class);
		$detector->expects($this->once())->method('detectString')
			->with($this->callback(static fn (string $read): bool => strlen($read) === FileFacts::SNIFF_BYTES && str_contains($read, 'TAIL') === false))
			->willReturn('text/plain');

		$facts = new FileFacts($detector, $this->createMock(IRootFolder::class));

		$this->assertSame('text/plain', $facts->mimeTypeOf(file: $this->storedFile(bytes: $bytes)));
	}

	/**
	 * A listed file resolves through its Nextcloud id; no id, a folder or no node give null.
	 *
	 * @return void
	 */
	public function testAListedFileResolvesByItsId(): void {
		$detector = $this->createMock(IMimeTypeDetector::class);
		$detector->method('detectString')->willReturn('APPLICATION/PDF');
		$root = $this->createMock(IRootFolder::class);
		$root->method('getFirstNodeById')->willReturnMap(
			[
				[42, $this->storedFile(bytes: '%PDF')],
				[43, $this->createMock(Folder::class)],
				[44, null],
			]
		);
		$facts = new FileFacts($detector, $root);

		$this->assertSame('application/pdf', $facts->mimeTypeOfListedFile(file: ['id' => '42', 'extension' => 'docx']));
		$this->assertNull($facts->mimeTypeOfListedFile(file: ['id' => 43]));
		$this->assertNull($facts->mimeTypeOfListedFile(file: ['id' => 44]));
		$this->assertNull($facts->mimeTypeOfListedFile(file: ['extension' => 'pdf']));
		$this->assertNull($facts->mimeTypeOfListedFile(file: ['id' => '4x']));
	}

	/**
	 * The DiWoo format list maps by MIME type and ignores parameters; an unlisted type has none.
	 *
	 * @return void
	 */
	public function testTheDiwooFormatFollowsTheMimeType(): void {
		$this->assertSame('DOCX', FileFacts::diwooFormat(mimeType: 'application/vnd.openxmlformats-officedocument.wordprocessingml.document'));
		$this->assertSame('TXT', FileFacts::diwooFormat(mimeType: 'text/plain; charset=utf-8'));
		$this->assertNull(FileFacts::diwooFormat(mimeType: 'application/xml'));
		$this->assertNull(FileFacts::diwooFormat(mimeType: null));
	}

	/**
	 * A stored file whose content stream holds these bytes.
	 *
	 * @param string $bytes The bytes.
	 *
	 * @return File&\PHPUnit\Framework\MockObject\MockObject
	 */
	private function storedFile(string $bytes): File {
		$file = $this->createMock(File::class);
		$file->method('fopen')->willReturnCallback(
			static function () use ($bytes) {
				$handle = fopen('php://memory', 'r+');
				fwrite($handle, $bytes);
				rewind($handle);
				return $handle;
			}
		);
		return $file;
	}
}//end class
