<?php

/**
 * The shipped attachmentReading schema (REQ-SOCR-001).
 *
 * @category Test
 * @package  OCA\OpenCatalogi\Tests\Unit\Settings
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://www.OpenCatalogi.nl
 *
 * @spec openspec/changes/scanned-attachments-become-searchable/specs/search/spec.md#requirement-a-published-scan-is-read-by-ocr-so-search-finds-its-words-req-socr-001
 */

declare(strict_types=1);

namespace Unit\Settings;

use OCA\OpenCatalogi\BackgroundJob\AttachmentReadingJob;
use PHPUnit\Framework\TestCase;

/**
 * Reads the fragment as it ships.
 *
 * @coversNothing
 */
class AttachmentReadingSchemaTest extends TestCase {

	private const FRAGMENT = __DIR__ . '/../../../lib/Settings/register.d/attachment-reading.json';

	/**
	 * @return array<string, mixed>
	 */
	private function fragment(): array {
		return json_decode((string)file_get_contents(self::FRAGMENT), true, 512, JSON_THROW_ON_ERROR);
	}//end fragment()

	/**
	 * @return array<string, mixed>
	 */
	private function schema(): array {
		return $this->fragment()['components']['schemas']['attachmentReading'];
	}//end schema()

	public function testTheSchemaHasNoPublicReadRule(): void {
		$authorization = $this->schema()['authorization'];

		$this->assertSame(['authenticated'], $authorization['read']);
		foreach (['create', 'update', 'delete'] as $action) {
			$this->assertSame(['admin'], $authorization[$action], $action . ' must be admin only');
		}

		$this->assertStringNotContainsString('public', json_encode($authorization, JSON_THROW_ON_ERROR));
	}//end testTheSchemaHasNoPublicReadRule()

	public function testTheSchemaHoldsTheStateAndNoTextOfTheFile(): void {
		$properties = $this->schema()['properties'];

		$this->assertSame(['fileId', 'publication', 'state', 'reason', 'updatedAt'], array_keys($properties));
		$this->assertSame('integer', $properties['fileId']['type']);
		$this->assertSame('uuid', $properties['publication']['format']);
		$this->assertSame('publication', $properties['publication']['$ref']);
		$this->assertSame('date-time', $properties['updatedAt']['format']);
		$this->assertSame(['fileId', 'publication', 'state', 'updatedAt'], $this->schema()['required']);
	}//end testTheSchemaHoldsTheStateAndNoTextOfTheFile()

	public function testTheStatesAreExactlyTheOnesTheJobRecords(): void {
		$this->assertSame(
			[
				AttachmentReadingJob::STATE_TYPED,
				AttachmentReadingJob::STATE_OCR,
				AttachmentReadingJob::STATE_WAITING,
				AttachmentReadingJob::STATE_NONE,
				AttachmentReadingJob::STATE_UNAVAILABLE,
				AttachmentReadingJob::STATE_FAILED,
			],
			$this->schema()['properties']['state']['enum']
		);
	}//end testTheStatesAreExactlyTheOnesTheJobRecords()

	public function testEveryPropertyHasATitleAndADescription(): void {
		foreach ($this->schema()['properties'] as $name => $property) {
			$this->assertNotSame('', (string)($property['title'] ?? ''), $name . ' has a title');
			$this->assertNotSame('', (string)($property['description'] ?? ''), $name . ' has a description');
		}
	}//end testEveryPropertyHasATitleAndADescription()

	public function testTheSchemaArrivesWithItsSlugOnThePublicationRegister(): void {
		$fragment = $this->fragment();

		$this->assertSame('attachmentReading', $this->schema()['slug']);
		$this->assertNotEmpty($this->schema()['version']);
		$this->assertContains('attachmentReading', $fragment['components']['registers']['publication']['schemas']);
		$this->assertTrue($fragment['components']['registers']['publication']['configuration']['schemas']['attachmentReading']['magicMapping']);
	}//end testTheSchemaArrivesWithItsSlugOnThePublicationRegister()
}//end class
