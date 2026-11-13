<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * A document reference in the formatFiles shape (REQ-PBR-002, design D2).
 *
 * @spec openspec/changes/integration-publish-by-reference/specs/publication-document-references/spec.md#requirement-a-reference-is-published-like-a-file-pointing-at-its-source-req-pbr-002
 */

declare(strict_types=1);

namespace Unit\Service;

use OCA\OpenCatalogi\Service\DocumentReferenceMapper;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\OpenCatalogi\Service\DocumentReferenceMapper
 */
class DocumentReferenceMapperTest extends TestCase {

	private const URL = 'https://documenten.voorbeeldgemeente.nl/besluit-2026-001.pdf';

	public function testTheSourceUrlIsTheDownloadAndAccessUrl(): void {
		$entry = (new DocumentReferenceMapper())->asFileEntry(
			reference: [
				'id' => 'ref-1',
				'url' => self::URL,
				'title' => 'Besluit 2026-001',
				'format' => 'PDF',
				'sourceSystem' => 'DMS',
				'publicationDate' => '2026-01-01T00:00:00+00:00',
				'reachable' => false,
				'lastStatus' => 404,
				'lastCheckedAt' => '2026-11-13T03:00:00+00:00',
				'@self' => ['updated' => '2026-02-01T00:00:00+00:00'],
			]
		);

		$this->assertSame(
			[
				'id' => 'ref-1',
				'kind' => 'reference',
				'title' => 'Besluit 2026-001',
				'name' => 'Besluit 2026-001',
				'downloadUrl' => self::URL,
				'accessUrl' => self::URL,
				'extension' => 'pdf',
				'published' => '2026-01-01T00:00:00+00:00',
				'modified' => '2026-02-01T00:00:00+00:00',
				'sourceSystem' => 'DMS',
				'reachable' => false,
				'lastStatus' => 404,
				'lastCheckedAt' => '2026-11-13T03:00:00+00:00',
			],
			$entry
		);
	}//end testTheSourceUrlIsTheDownloadAndAccessUrl()

	public function testAReferenceWithoutATitleIsNamedByItsUrlAndTheIdComesFromSelf(): void {
		$entry = (new DocumentReferenceMapper())->asFileEntry(reference: ['url' => self::URL, 'format' => 'pdf', '@self' => ['id' => 'ref-2']]);

		$this->assertSame('ref-2', $entry['id']);
		$this->assertSame(self::URL, $entry['title']);
		$this->assertNull($entry['published']);
		$this->assertNull($entry['reachable']);
	}//end testAReferenceWithoutATitleIsNamedByItsUrlAndTheIdComesFromSelf()

	public function testOnlyAnHttpsReferenceWithAFormatIsListable(): void {
		$mapper = new DocumentReferenceMapper();

		$this->assertTrue($mapper->isListable(reference: ['url' => self::URL, 'format' => 'pdf']));
		$this->assertTrue($mapper->isListable(reference: ['url' => 'HTTPS://example.org/a.pdf', 'format' => 'pdf']));
		$this->assertFalse($mapper->isListable(reference: ['url' => 'http://example.org/a.pdf', 'format' => 'pdf']));
		$this->assertFalse($mapper->isListable(reference: ['url' => self::URL, 'format' => ' ']));
		$this->assertFalse($mapper->isListable(reference: ['format' => 'pdf']));
	}//end testOnlyAnHttpsReferenceWithAFormatIsListable()
}//end class
