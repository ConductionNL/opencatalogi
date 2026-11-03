<?php

/**
 * Golden test for QualityService scoring, roll-up and DQV measurements.
 *
 * Every case scores a set of DCAT dataset nodes under a weight configuration and
 * compares each score, the roll-up and the DQV measurements to the recording in
 * fixtures/quality-scoring-golden.json.
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
 */

declare(strict_types=1);

namespace Unit\Service;

use OCA\OpenCatalogi\Service\QualityService;
use OCP\IAppConfig;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Golden test for the dataset quality score.
 *
 * @covers \OCA\OpenCatalogi\Service\QualityService
 */
class QualityScoringGoldenTest extends TestCase {

	private const FIXTURE = __DIR__ . '/fixtures/quality-scoring-golden.json';

	/**
	 * The dataset nodes every case scores.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private static function nodes(): array {
		$id = static fn (string $iri): array => ['@id' => $iri];

		return [
			'empty' => [],
			'complete' => [
				'@id' => 'https://x/1', 'dct:title' => 'T', 'dcat:keyword' => ['a'], 'dcat:theme' => $id('https://t'),
				'dct:license' => $id('https://l'), 'dct:publisher' => 'P', 'dct:modified' => '2026', 'dct:identifier' => 'i',
				'dcat:distribution' => [['dcat:downloadURL' => $id('https://d/file.csv'), 'dct:format' => $id('https://f/CSV'), 'dcat:mediaType' => 'text/csv']],
			],
			'literal theme, issued, landing page' => [
				'@id' => 'https://x/2', 'dct:title' => '', 'dcat:keyword' => [], 'dcat:theme' => 'economie', 'dct:issued' => '2025',
				'dcat:landingPage' => $id('https://lp'), 'dct:license' => null,
			],
			'single distribution node' => [
				'@id' => 'https://x/3', 'dcat:distribution' => ['@type' => 'dcat:Distribution', 'dcat:accessURL' => $id('https://a'), 'dct:format' => 'PDF'],
			],
			'distribution by download URL only' => [
				'@id' => 'https://x/4', 'dcat:distribution' => ['dcat:downloadURL' => $id('https://d/x.JSON')],
			],
			'distribution not a list' => ['@id' => 'https://x/5', 'dcat:distribution' => 'nope'],
			'two distributions, best wins' => [
				'@id' => 'https://x/6', 'dcat:distribution' => [
					['dct:format' => [], 'dcat:mediaType' => ''],
					['dct:format' => 'XLSX', 'dcat:mediaType' => 'application/vnd.ms-excel', 'dcat:accessURL' => ['@id' => '']],
					['dcat:mediaType' => 'application/geo+json'],
				],
			],
			'empty format and media type' => ['@id' => 'https://x/9', 'dcat:distribution' => [['dct:format' => [], 'dcat:mediaType' => '']]],
			'media type only' => ['@id' => 'https://x/7', 'dcat:distribution' => [['dcat:mediaType' => 'text/html']]],
			'open format in a quoted value' => ['@id' => 'https://x/8', 'dcat:distribution' => [['dct:format' => 'ttl']]],
		];

	}//end nodes()

	/**
	 * The recorded cases: the weight configuration and how many worst entries to list.
	 *
	 * @return array<string, array{0: array<string, int>, 1: int}>
	 */
	public static function cases(): array {
		return [
			'default weights' => [[], 5],
			'custom weights' => [['findability' => 50, 'accessibility' => 0, 'interoperability' => 10, 'reusability' => 30, 'contextuality' => 10], 3],
			'all weights zero' => [['findability' => 0, 'accessibility' => 0, 'interoperability' => 0, 'reusability' => 0, 'contextuality' => 0], 0],
			'negative worst' => [[], -2],
		];

	}//end cases()

	/**
	 * Replay a case and compare it to the recording.
	 *
	 * @param array<string, int> $weights The stored weights.
	 * @param int                $worst   How many worst entries the roll-up lists.
	 *
	 * @return void
	 */
	#[DataProvider('cases')]
	public function testScoresMatchTheRecording(array $weights, int $worst): void {
		$name = $this->dataName();
		$config = $this->createMock(IAppConfig::class);
		$config->method('getValueInt')->willReturnCallback(
			static fn (string $app, string $key, int $default = 0): int => ($weights[substr($key, strlen('quality_weight_'))] ?? $default)
		);
		$service = new QualityService($config);

		$scores = [];
		$measurements = [];
		foreach (self::nodes() as $label => $node) {
			$scores[$label] = $service->scoreDataset(node: $node);
			$measurements[$label] = $service->dqvMeasurements(score: $scores[$label]);
		}

		$actual = [
			'weights' => $service->weights(),
			'scores' => $scores,
			'measurements' => $measurements,
			'rollup' => $service->rollup(nodes: array_values(self::nodes()), worst: $worst),
			'rollup of nothing' => $service->rollup(nodes: []),
			'measurements of text scores' => $service->dqvMeasurements(score: ['dimensions' => ['findability' => '40'], 'total' => '7.9']),
		];

		$recorded = [];
		if (file_exists(self::FIXTURE) === true) {
			$recorded = json_decode((string)file_get_contents(self::FIXTURE), true);
		}

		if (getenv('GOLDEN_RECORD') === '1') {
			$recorded[$name] = $actual;
			file_put_contents(self::FIXTURE, json_encode($recorded, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
		}

		$this->assertArrayHasKey($name, $recorded, 'No recording for ' . $name);
		$this->assertSame($recorded[$name], json_decode((string)json_encode($actual), true));

	}//end testScoresMatchTheRecording()
}//end class
