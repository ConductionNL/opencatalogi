<?php

/**
 * Unit tests for SitemapDocumentFields: the stored DiWoo fields of a publication,
 * with the legacy fallback only for a record created before the fields existed.
 *
 * @category Test
 * @package  OCA\OpenCatalogi\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://www.OpenCatalogi.nl
 */

declare(strict_types=1);

namespace Unit\Service;

use OCA\OpenCatalogi\Service\InstanceSettings;
use OCA\OpenCatalogi\Service\SitemapDocumentFields;
use OCA\OpenCatalogi\Service\TooiVocabularyService;
use OCP\IAppConfig;
use OCP\IURLGenerator;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\OpenCatalogi\Service\SitemapDocumentFields
 * @uses \OCA\OpenCatalogi\Service\TooiVocabularyService
 * @uses \OCA\OpenCatalogi\Service\InstanceSettings
 */
class SitemapDocumentFieldsTest extends TestCase {

	/**
	 * The fields reader, with the DiWoo fields introduced at a moment ('' for not yet).
	 *
	 * @param string $introducedAt The recorded moment.
	 *
	 * @return SitemapDocumentFields The reader.
	 */
	private function fields(string $introducedAt): SitemapDocumentFields {
		$config = $this->createMock(IAppConfig::class);
		$config->method('getValueString')->willReturnCallback(
			static fn (string $app, string $key, string $default = ''): string => ($key === 'diwoo_fields_introduced_at' ? $introducedAt : $default)
		);

		return new SitemapDocumentFields(new TooiVocabularyService(), new InstanceSettings($this->createMock(IURLGenerator::class), $config));
	}//end fields()

	/**
	 * REQ-DWP-004: stored values win; a new record without them gets no fallback.
	 *
	 * @return void
	 */
	public function testStoredValuesAreReadAndANewRecordGetsNoFallback(): void {
		$fields = $this->fields(introducedAt: '2026-10-01T00:00:00+00:00');
		$stored = $fields->storedElements(['creationDate' => '2026-09-30', 'language' => 'en', 'validUntil' => '2030-12-31', '@self' => ['created' => '2026-11-02']]);

		$this->assertSame('2026-09-30', $stored['diwoo:creatiedatum']);
		$this->assertSame(['#text' => 'Engels', '@resource' => 'http://publications.europa.eu/resource/authority/language/ENG'], $stored['diwoo:language']);
		$this->assertSame(['diwoo:einddatum' => '2030-12-31T00:00:00+00:00'], $stored['diwoo:geldigheid']);

		$this->assertSame(
			['diwoo:creatiedatum' => null, 'diwoo:language' => null, 'diwoo:geldigheid' => null],
			$fields->storedElements(['@self' => ['created' => '2026-11-02']])
		);
	}//end testStoredValuesAreReadAndANewRecordGetsNoFallback()

	/**
	 * REQ-DWP-004: a record from before the fields, or any record while the moment is unset, falls back.
	 *
	 * @return void
	 */
	public function testALegacyRecordFallsBack(): void {
		foreach (['2026-10-01T00:00:00+00:00', ''] as $introducedAt) {
			$stored = $this->fields(introducedAt: $introducedAt)->storedElements(['@self' => ['created' => '2024-01-15 10:00:00']]);
			$this->assertSame('2024-01-15', $stored['diwoo:creatiedatum'], $introducedAt);
			$this->assertSame('Nederlands', $stored['diwoo:language']['#text'], $introducedAt);
		}
	}//end testALegacyRecordFallsBack()

	/**
	 * REQ-DWP-002: a file's own type wins, an unknown type is false, none stored is null.
	 *
	 * @return void
	 */
	public function testTheDocumentTypeOfAFile(): void {
		$fields = $this->fields(introducedAt: '');
		$publication = ['documentsoort' => 'besluit', 'fileDocumentsoorten' => ['f-2' => 'beslisnota']];

		$this->assertSame('besluit', $fields->documentTypeElement($publication, ['id' => 'f-1'])['diwoo:documentsoort']['#text']);
		$this->assertSame('beslisnota', $fields->documentTypeElement($publication, ['id' => 'f-2'])['diwoo:documentsoort']['#text']);
		$this->assertFalse($fields->documentTypeElement(['documentsoort' => 'memo'], ['id' => 'f-1']));
		$this->assertNull($fields->documentTypeElement([], ['id' => 'f-1']));
	}//end testTheDocumentTypeOfAFile()

	/**
	 * The responsible organisation needs a name and a TOOI URI; the stored gaps name the creation date and an unknown type.
	 *
	 * @return void
	 */
	public function testResponsibleAndStoredGaps(): void {
		$fields = $this->fields(introducedAt: '');
		$publisher = ['#text' => 'Gemeente', '@resource' => 'https://identifier.overheid.nl/tooi/id/gemeente/gm0855'];

		$this->assertSame($publisher, $fields->responsible($publisher));
		$this->assertNull($fields->responsible(['#text' => 'Gemeente']));
		$this->assertNull($fields->responsible(['#text' => '', '@resource' => 'x']));

		$this->assertSame(
			[['creatiedatum', 'publication has no creation date'], ['documentsoort', 'document type resolves to no member of the documentsoorten waardelijst']],
			$fields->storedGaps([], ['diwoo:classificatiecollectie' => ['diwoo:documentsoorten' => false]])
		);
		$this->assertSame(
			['diwoo:classificatiecollectie' => ['diwoo:informatiecategorieen' => []]],
			$fields->withoutUnresolvedType(['diwoo:classificatiecollectie' => ['diwoo:informatiecategorieen' => [], 'diwoo:documentsoorten' => false]])
		);
	}//end testResponsibleAndStoredGaps()
}//end class
