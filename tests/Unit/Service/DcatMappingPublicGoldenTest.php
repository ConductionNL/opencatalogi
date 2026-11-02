<?php

/**
 * Golden test for the public DCAT mapping helpers.
 *
 * Runs `resolveMapping()`, `resolveHvd()`, `buildPublisher()` and
 * `mapDistribution()` of DcatMappingService over inputs that reach every
 * branch: missing, malformed and opted-out annotations, each publisher name
 * and URI source with empty values, and file entries with and without URLs,
 * media types, sizes, titles and licences. It also maps datasets whose HVD
 * category comes from a property, a non-scalar property or the catalog
 * default. Each result is compared, key order included, with what was
 * recorded.
 *
 * Re-record (only when behaviour is meant to change) with
 * `GOLDEN_RECORD=1 vendor/bin/phpunit tests/Unit/Service/DcatMappingPublicGoldenTest.php`.
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
 * @covers \OCA\OpenCatalogi\Service\DcatDistributionMapper
 * @uses \OCA\OpenCatalogi\Service\DcatVocabularyService
 * @uses \OCA\OpenCatalogi\Service\DcatDatasetSource
 * @uses \OCA\OpenCatalogi\Service\DcatMappingProfile
 */

declare(strict_types=1);

namespace Unit\Service;

use OCA\OpenCatalogi\Service\DcatDatasetSource;
use OCA\OpenCatalogi\Service\DcatMappingProfile;
use OCA\OpenCatalogi\Service\DcatMappingService;
use OCA\OpenCatalogi\Service\DcatVocabularyService;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Golden test for the public DcatMappingService helpers.
 */
class DcatMappingPublicGoldenTest extends TestCase {

	private const FIXTURE = __DIR__ . '/../../fixtures/dcat/mapping-helpers-golden.json';

	/**
	 * The schemas each annotation case resolves.
	 *
	 * @return array<string, array{0: array<string, mixed>|null}>
	 */
	public static function schemas(): array {
		return [
			'no schema' => [null],
			'empty schema' => [[]],
			'no annotation' => [['title' => 'x']],
			'opted out' => [['x-dcat' => false]],
			'annotation true' => [['x-dcat' => true]],
			'annotation string' => [['x-dcat' => 'mapping']],
			'annotation empty' => [['x-dcat' => []]],
			'mapping not a list' => [['x-dcat' => ['mapping' => 'title']]],
			'mapping empty' => [['x-dcat' => ['mapping' => []]]],
			'mapping null' => [['x-dcat' => ['mapping' => null]]],
			'mapping set' => [['x-dcat' => ['mapping' => ['dct:title' => 'naam']]]],
			'hvd not a list' => [['x-dcat' => ['hvd' => 'statistics']]],
			'hvd empty' => [['x-dcat' => ['hvd' => []]]],
			'hvd null' => [['x-dcat' => ['hvd' => null]]],
			'hvd set' => [['x-dcat' => ['hvd' => ['categoryProperty' => 'hvd'], 'mapping' => ['dct:title' => 'naam']]]],
			'hvd with legislation' => [['x-dcat' => ['hvd' => ['legislation' => 'https://eli/x']]]],
		];

	}//end schemas()

	/**
	 * The organisations and defaults each publisher case builds from.
	 *
	 * @return array<string, array{0: array<string, mixed>|null, 1: array<string, mixed>}>
	 */
	public static function publishers(): array {
		return [
			'nothing' => [null, []],
			'empty organisation' => [[], []],
			'empty strings' => [['title' => '', 'oin' => ''], ['publisherName' => '', 'publisherUri' => '']],
			'title and oin' => [['title' => 'Gemeente A', 'name' => 'ignored', 'oin' => '000001', 'tooiUri' => 'ignored'], []],
			'name and tooi' => [['name' => 'Gemeente B', 'tooiUri' => 'https://tooi/b', 'uri' => 'ignored'], []],
			'uri only' => [['uri' => 'https://org/c'], []],
			'name only' => [['name' => 'Gemeente D'], []],
			'defaults only' => [null, ['publisherName' => 'Default org', 'publisherUri' => 'https://default/org']],
			'default name only' => [null, ['publisherName' => 'Default org']],
			'default uri only' => [[], ['publisherUri' => 'https://default/org']],
			'organisation beats defaults' => [['title' => 'Org'], ['publisherName' => 'Default', 'publisherUri' => 'https://default']],
			'numeric values' => [['title' => 42, 'oin' => 7], []],
			'empty title falls to nothing' => [['title' => ''], ['publisherName' => 'Default']],
			'null title uses name' => [['title' => null, 'name' => 'Named'], []],
		];

	}//end publishers()

	/**
	 * The file entries and licences each distribution case maps.
	 *
	 * @return array<string, array{0: array<string, mixed>, 1: string|null}>
	 */
	public static function distributions(): array {
		return [
			'no url' => [['extension' => 'pdf'], 'https://licence'],
			'empty download url' => [['downloadUrl' => '', 'extension' => 'pdf'], null],
			'empty download falls to access' => [['downloadUrl' => null, 'accessUrl' => 'https://h/a'], null],
			'url not a string' => [['downloadUrl' => 12], null],
			'full entry' => [['downloadUrl' => 'https://h/d', 'accessUrl' => 'https://h/a', 'extension' => 'PDF', 'title' => 'Doc', 'name' => 'ignored', 'size' => '1234'], 'https://licence'],
			'access only' => [['accessUrl' => 'https://h/a', 'extension' => 'csv', 'name' => 'data.csv'], ''],
			'mimetype wins' => [['downloadUrl' => 'https://h/d', 'extension' => 'pdf', 'mimetype' => 'text/x-custom'], null],
			'mimeType key' => [['downloadUrl' => 'https://h/d', 'extension' => 'xyz', 'mimeType' => 'application/x-y'], null],
			'empty mimetype' => [['downloadUrl' => 'https://h/d', 'extension' => 'json', 'mimetype' => ''], null],
			'unknown extension' => [['downloadUrl' => 'https://h/d', 'extension' => 'xyz', 'size' => 'big'], null],
			'no extension' => [['downloadUrl' => 'https://h/d', 'size' => 0, 'title' => ''], 'https://licence'],
			'float size' => [['downloadUrl' => 'https://h/d', 'extension' => 'ods', 'size' => '12.7', 'title' => null, 'name' => 'Sheet'], null],
			'numeric title' => [['downloadUrl' => 'https://h/d', 'extension' => 'odt', 'title' => 0], null],
			'size null' => [['downloadUrl' => 'https://h/d', 'extension' => 'zip', 'size' => null], null],
		];

	}//end distributions()

	/**
	 * The publications and HVD settings each dataset case maps.
	 *
	 * @return array<string, array{0: array<string, mixed>}>
	 */
	public static function datasets(): array {
		return [
			'hvd from property' => [['publication' => ['hvd' => 'statistics'], 'hvd' => ['categoryProperty' => 'hvd']]],
			'hvd property not scalar' => [['publication' => ['hvd' => ['statistics']], 'hvd' => ['categoryProperty' => 'hvd'], 'catalogHvdDefault' => 'mobility']],
			'hvd property empty' => [['publication' => ['hvd' => ''], 'hvd' => ['categoryProperty' => 'hvd'], 'catalogHvdDefault' => 'mobility']],
			'hvd property missing, empty default' => [['publication' => [], 'hvd' => ['categoryProperty' => 'hvd'], 'catalogHvdDefault' => '']],
			'hvd without category property' => [['publication' => ['hvd' => 'statistics'], 'hvd' => ['legislation' => 'https://eli/x'], 'catalogHvdDefault' => 'earth-observation']],
			'hvd property beats default' => [['publication' => ['hvd' => 'meteorological'], 'hvd' => ['categoryProperty' => 'hvd'], 'catalogHvdDefault' => 'mobility']],
			'hvd custom legislation' => [['publication' => [], 'hvd' => ['legislation' => 'https://eli/x'], 'catalogHvdDefault' => 'geospatial']],
			'catalog default only' => [['publication' => [], 'catalogHvdDefault' => 'geospatial']],
			'unknown category' => [['publication' => ['hvd' => 'nonsense'], 'hvd' => ['categoryProperty' => 'hvd']]],
			'nested category property' => [['publication' => ['meta' => ['hvd' => 'companies']], 'hvd' => ['categoryProperty' => 'meta.hvd']]],
			'numeric category' => [['publication' => ['hvd' => 3], 'hvd' => ['categoryProperty' => 'hvd'], 'catalogHvdDefault' => 'mobility']],
			'publisher string' => [['publication' => ['publisher' => 'Gemeente X']]],
			'publisher empty string' => [['publication' => ['publisher' => ''], 'defaults' => ['publisherName' => 'Default']]],
			'publisher array without name' => [['publication' => ['organisation' => ['x' => 1]], 'defaults' => ['organisation' => ['title' => 'Catalog org']]]],
			'publisher number' => [['publication' => ['publisher' => 5], 'defaults' => ['publisherUri' => 'https://d']]],
			'theme on object not a url' => [['publication' => ['tooiThemaUri' => 'onderwijs', 'category' => 'Onderwijs']]],
			'theme uri on object' => [['publication' => ['themeUri' => 'http://x/theme', 'category' => 'whatever']]],
			'theme not scalar' => [['publication' => ['category' => ['a']]]],
			'theme uri not a string' => [['publication' => ['tooiThemaUri' => ['http://x/theme'], 'category' => 'Onderwijs']]],
			'empty lists left out' => [['publication' => ['tags' => [], 'category' => [], 'title' => '']]],
			'empty list on a plain path' => [['publication' => ['extra' => [], 'more' => ['a', 'b']], 'mapping' => ['dct:alternative' => 'extra', 'dct:relation' => 'more']]],
			'theme through a list path' => [['publication' => ['themes' => ['Onderwijs', 3, '']], 'mapping' => ['dcat:theme' => 'themes[]']]],
			'theme list path with a scalar' => [['publication' => ['themes' => 'Onderwijs'], 'mapping' => ['dcat:theme' => 'themes[]']]],
			'hvd block without category property' => [['publication' => ['' => 'statistics'], 'hvd' => ['legislation' => 'https://eli/x']]],
		];

	}//end datasets()

	/**
	 * resolveMapping() and resolveHvd() match the recording.
	 *
	 * @param array<string, mixed>|null $schema The schema.
	 *
	 * @return void
	 */
	#[DataProvider('schemas')]
	public function testAnnotationMatchesTheRecording(?array $schema): void {
		$service = $this->service();
		$this->compare(
			name: 'annotation: ' . $this->dataName(),
			actual: ['mapping' => $service->resolveMapping($schema), 'hvd' => $service->resolveHvd($schema)]
		);

	}//end testAnnotationMatchesTheRecording()

	/**
	 * buildPublisher() matches the recording.
	 *
	 * @param array<string, mixed>|null $organisation The organisation.
	 * @param array<string, mixed>      $defaults     The catalog defaults.
	 *
	 * @return void
	 */
	#[DataProvider('publishers')]
	public function testPublisherMatchesTheRecording(?array $organisation, array $defaults): void {
		$this->compare(
			name: 'publisher: ' . $this->dataName(),
			actual: $this->service()->buildPublisher($organisation, $defaults)
		);

	}//end testPublisherMatchesTheRecording()

	/**
	 * mapDistribution() matches the recording.
	 *
	 * @param array<string, mixed> $file    The file entry.
	 * @param string|null          $licence The fallback licence.
	 *
	 * @return void
	 */
	#[DataProvider('distributions')]
	public function testDistributionMatchesTheRecording(array $file, ?string $licence): void {
		$this->compare(
			name: 'distribution: ' . $this->dataName(),
			actual: $this->service()->mapDistribution($file, $licence)
		);

	}//end testDistributionMatchesTheRecording()

	/**
	 * mapDataset() matches the recording for the HVD, publisher and theme edges.
	 *
	 * @param array<string, mixed> $case The case input.
	 *
	 * @return void
	 */
	#[DataProvider('datasets')]
	public function testDatasetMatchesTheRecording(array $case): void {
		$source = new DcatDatasetSource(publication: $case['publication'], datasetIri: 'https://host/api/cat/dataset', files: []);
		$profile = new DcatMappingProfile(
			mapping: ($case['mapping'] ?? DcatMappingService::DEFAULT_MAPPING),
			defaults: ($case['defaults'] ?? []),
			hvd: ($case['hvd'] ?? null),
			catalogHvdDefault: ($case['catalogHvdDefault'] ?? null)
		);
		$dataset = $this->service()->mapDataset(source: $source, profile: $profile);
		$this->compare(
			name: 'dataset: ' . $this->dataName(),
			actual: ['dataset' => $dataset, 'violations' => $source->violations]
		);

	}//end testDatasetMatchesTheRecording()

	/**
	 * A mapping service over the real vocabulary.
	 *
	 * @return DcatMappingService
	 */
	private function service(): DcatMappingService {
		return new DcatMappingService(new DcatVocabularyService());

	}//end service()

	/**
	 * Compare one result with its recording, recording it under GOLDEN_RECORD=1.
	 *
	 * @param string $name   The recording key.
	 * @param mixed  $actual The result.
	 *
	 * @return void
	 */
	private function compare(string $name, mixed $actual): void {
		$recorded = [];
		if (is_file(self::FIXTURE) === true) {
			$recorded = json_decode((string)file_get_contents(self::FIXTURE), true);
		}

		if (getenv('GOLDEN_RECORD') === '1') {
			$recorded[$name] = $actual;
			ksort($recorded);
			file_put_contents(self::FIXTURE, json_encode($recorded, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n");
		}

		$this->assertArrayHasKey($name, $recorded, 'No recording for this case.');
		$this->assertSame(
			json_encode($recorded[$name], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES),
			json_encode($actual, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
		);

	}//end compare()
}//end class
