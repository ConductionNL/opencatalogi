<?php

/**
 * Golden test for the DCAT dataset mapping.
 *
 * Runs `DcatMappingService::mapDataset()` over a set of publications that
 * together reach every branch of the mapping: list and scalar properties, the
 * three theme outcomes, HVD from a property, from the catalog default and with
 * custom legislation, the dct:modified fallbacks, every publisher source, the
 * contact point and the distributions. It compares the full dataset node,
 * including key order, and the collected violations with what was recorded.
 *
 * Re-record (only when behaviour is meant to change) with
 * `GOLDEN_RECORD=1 vendor/bin/phpunit tests/Unit/Service/DcatDatasetGoldenTest.php`.
 *
 * @category Test
 * @package  OCA\OpenCatalogi\Tests
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://www.OpenCatalogi.nl
 *
 * @spec openspec/specs/dcat-ap-harvest/spec.md
 *
 * @covers \OCA\OpenCatalogi\Service\DcatMappingService
 * @uses \OCA\OpenCatalogi\Service\DcatVocabularyService
 */

declare(strict_types=1);

namespace Unit\Service;

use OCA\OpenCatalogi\Service\DcatMappingService;
use OCA\OpenCatalogi\Service\DcatVocabularyService;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Golden test for DcatMappingService::mapDataset().
 */
class DcatDatasetGoldenTest extends TestCase {

	private const FIXTURE = __DIR__ . '/../../fixtures/dcat/dataset-mapping-golden.json';

	/**
	 * The publications and catalog settings each case maps.
	 *
	 * @return array<string, array{0: array<string, mixed>}>
	 */
	public static function cases(): array {
		$files = [
			['downloadUrl' => 'https://host/d/1/download', 'accessUrl' => 'https://host/d/1', 'extension' => 'pdf', 'title' => 'doc.pdf', 'size' => '1234'],
			['accessUrl' => 'https://host/d/2', 'extension' => 'CSV', 'name' => 'data.csv', 'mimetype' => 'text/csv'],
			['extension' => 'pdf', 'title' => 'no url'],
			['downloadUrl' => 'https://host/d/3/download', 'extension' => 'xyz', 'size' => 'big'],
		];

		return [
			'annotated list and scalars' => [[
				'publication' => [
					'id' => 'uuid-1',
					'naam' => 'Annual report',
					'meta' => ['summary' => 'Nested summary', 'empty' => ''],
					'tags' => ['finance', 7, ['nested'], '', true, null],
					'scalarTags' => 'not-a-list',
					'@self' => ['uuid' => 'uuid-1', 'updated' => '2025-01-02T10:00:00+00:00'],
				],
				'mapping' => [
					'dct:title' => 'naam',
					'dct:description' => 'meta.summary',
					'dct:alternative' => 'meta.empty',
					'dcat:keyword' => 'tags[]',
					'dct:subject' => 'scalarTags[]',
					'dct:missing' => 'absent',
				],
			]],
			'theme on object' => [[
				'publication' => [
					'id' => 'uuid-2',
					'category' => 'Finance',
					'tooiThemaUri' => 'https://identifier.overheid.nl/tooi/def/thes/c_123',
					'publicationDate' => '2024-05-06',
					'@self' => ['uuid' => 'uuid-2'],
				],
				'mapping' => ['dcat:theme' => 'category'],
			]],
			'theme resolved through the vocabulary' => [[
				'publication' => ['id' => 'uuid-3', 'category' => 'transport', 'themeUri' => 'not-a-uri', '@self' => []],
				'mapping' => ['dcat:theme' => 'category', 'dct:title' => 'id'],
			]],
			'theme violation' => [[
				'publication' => ['category' => ['an', 'array'], '@self' => []],
				'mapping' => ['dcat:theme' => 'category'],
			]],
			'hvd from property with custom legislation' => [[
				'publication' => ['id' => 'uuid-5', 'hvdCategory' => 'Mobility', '@self' => ['updated' => 1700000000]],
				'hvd' => ['categoryProperty' => 'hvdCategory', 'legislation' => 'https://law/custom'],
			]],
			'hvd from catalog default' => [[
				'publication' => ['id' => 'uuid-6', 'hvdCategory' => '', '@self' => []],
				'hvd' => ['categoryProperty' => 'hvdCategory'],
				'catalogHvdDefault' => 'statistiek',
			]],
			'hvd unresolved' => [[
				'publication' => ['id' => 'uuid-7', '@self' => []],
				'hvd' => ['categoryProperty' => 'hvdCategory'],
				'catalogHvdDefault' => 'no such category',
			]],
			'mapping overrides a mandatory key' => [[
				'publication' => ['id' => 'uuid-8', 'title' => 'T', 'code' => 'X-8', '@self' => []],
				'mapping' => ['dct:identifier' => 'code', 'dct:title' => 'title', 'dcat:landingPage' => 'title'],
			]],
			'publisher object and contact point' => [[
				'publication' => [
					'id' => 'uuid-9',
					'publisher' => ['name' => 'Gemeente A', 'tooiUri' => 'https://tooi/a'],
					'contactEmail' => 'mailto:info@a.nl',
					'license' => 'https://lic/pub',
					'@self' => [],
				],
				'defaults' => ['license' => 'https://lic/default', 'contactPoint' => 'cat@x.nl'],
				'files' => $files,
			]],
			'publisher string' => [[
				'publication' => ['id' => 'uuid-10', 'organisation' => 'Gemeente B', '@self' => []],
				'defaults' => ['organisation' => ['title' => 'Fallback']],
			]],
			'publisher empty object falls back to defaults' => [[
				'publication' => ['id' => 'uuid-11', 'publisher' => [], '@self' => []],
				'defaults' => ['publisherName' => 'Default name', 'publisherUri' => 'https://pub/default', 'contactPoint' => 'cat@x.nl', 'license' => 'https://lic/default'],
				'files' => $files,
			]],
			'nothing resolves' => [[
				'publication' => ['publisher' => '', '@self' => []],
				'mapping' => [],
				'datasetIri' => 'https://host/api/cat/none',
			]],
		];

	}//end cases()

	/**
	 * Map one case and compare it with the recorded result.
	 *
	 * @param array<string, mixed> $case The case input.
	 *
	 * @return void
	 */
	#[DataProvider('cases')]
	public function testMapDatasetMatchesTheRecording(array $case): void {
		$name = $this->dataName();
		$actual = $this->map(case: $case);

		$recorded = [];
		if (is_file(self::FIXTURE) === true) {
			$recorded = json_decode((string)file_get_contents(self::FIXTURE), true);
		}

		if (getenv('GOLDEN_RECORD') === '1') {
			$recorded[$name] = $actual;
			file_put_contents(self::FIXTURE, json_encode($recorded, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n");
		}

		$this->assertArrayHasKey($name, $recorded, 'No recording for this case.');
		$this->assertSame(
			json_encode($recorded[$name], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES),
			json_encode($actual, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
		);

	}//end testMapDatasetMatchesTheRecording()

	/**
	 * Run the mapping for one case.
	 *
	 * @param array<string, mixed> $case The case input.
	 *
	 * @return array{dataset: array<string, mixed>, violations: array<int, array<string, mixed>>}
	 */
	private function map(array $case): array {
		$service = new DcatMappingService(new DcatVocabularyService());
		$violations = [['iri' => 'earlier', 'axis' => 'kept', 'reason' => 'collected before this dataset']];
		$dataset = $service->mapDataset(
			publication: $case['publication'],
			mapping: ($case['mapping'] ?? DcatMappingService::DEFAULT_MAPPING),
			files: ($case['files'] ?? []),
			datasetIri: ($case['datasetIri'] ?? 'https://host/api/cat/dataset'),
			defaults: ($case['defaults'] ?? []),
			hvd: ($case['hvd'] ?? null),
			catalogHvdDefault: ($case['catalogHvdDefault'] ?? null),
			violations: $violations
		);

		return ['dataset' => $dataset, 'violations' => $violations];

	}//end map()
}//end class
