<?php

/**
 * Golden test for DcatService::buildCatalogDocument over several pages.
 *
 * Every world is recorded once and replayed from the fixture: the document,
 * the violations collected (after the ones passed in), the search asked and
 * the files looked up.
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

use OCA\OpenCatalogi\Service\DcatMappingService;
use OCA\OpenCatalogi\Service\DcatSerializer;
use OCA\OpenCatalogi\Service\DcatService;
use OCA\OpenCatalogi\Service\DcatVocabularyService;
use OCA\OpenCatalogi\Service\QualityService;
use OCA\OpenCatalogi\Service\StandardsVersionService;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Service\FileService;
use OCA\OpenRegister\Service\ObjectService;
use OCP\App\IAppManager;
use OCP\IAppConfig;
use OCP\IURLGenerator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Golden test for DcatService::buildCatalogDocument.
 *
 * @covers \OCA\OpenCatalogi\Service\DcatService
 * @uses \OCA\OpenCatalogi\Service\DcatMappingService
 * @uses \OCA\OpenCatalogi\Service\DcatVocabularyService
 * @uses \OCA\OpenCatalogi\Service\DcatDatasetSource
 * @uses \OCA\OpenCatalogi\Service\DcatMappingProfile
 * @uses \OCA\OpenCatalogi\Service\CallerScope
 */
class DcatCatalogPagesGoldenTest extends TestCase {

	private const FIXTURE = __DIR__ . '/fixtures/dcat-catalog-pages-golden.json';

	/**
	 * What the doubles were asked.
	 *
	 * @var array<string, list<mixed>>
	 */
	private array $log = [];

	/**
	 * The recorded worlds: the catalog, the page, and the search answer.
	 *
	 * @return array<string, array{0: array<string, mixed>}>
	 */
	public static function worlds(): array {
		$catalog = ['id' => 'c1', 'title' => 'Open', 'registers' => ['20'], 'schemas' => [28, 29, 30]];
		$rows = [
			['@self' => ['uuid' => 'u1', 'schema' => 28, 'updated' => '2026-02-03T04:05:06+00:00'], 'id' => 'u1', 'title' => 'Een', 'category' => 'onzin'],
			['@self' => ['uuid' => 'u2', 'schema' => 30], 'id' => 'u2', 'title' => 'Uitgezonderd', 'category' => 'onzin'],
			['@self' => ['uuid' => 'u3', 'schema' => 29], 'id' => 'u3', 'title' => 'Twee', 'publicationDate' => '2025-12-01', 'category' => 'ook onzin'],
			['@self' => ['uuid' => 'u4', 'schema' => 28, 'updated' => '2026-05-01T00:00:00+00:00'], 'title' => 'Zonder id', 'tags' => ['a', 'b'], 'license' => 'x'],
			['@self' => ['schema' => 28], 'id' => 'only-id', 'title' => 'Alleen id', 'publicationDate' => 'not a date'],
		];

		return [
			'violations in order, more pages' => [['catalog' => $catalog, 'page' => 3, 'rows' => $rows, 'next' => 'y']],
			'last page, dqv on' => [['catalog' => $catalog + ['dqvExposure' => true], 'page' => 1, 'rows' => $rows, 'next' => null]],
			'page below one' => [['catalog' => $catalog, 'page' => -4, 'rows' => array_slice($rows, 0, 1), 'next' => null]],
			'every row opted out' => [['catalog' => $catalog, 'page' => 1, 'rows' => [$rows[1]], 'next' => null]],
			'no rows' => [['catalog' => $catalog, 'page' => 2, 'rows' => [], 'next' => null]],
			'no schemas' => [['catalog' => ['registers' => ['20']], 'page' => 1, 'rows' => $rows, 'next' => 'y']],
			'no registers, slug as etag' => [['catalog' => ['schemas' => [28]], 'page' => 1, 'rows' => $rows, 'next' => 'y']],
			'schema mapping and file lookup id' => [[
				'catalog' => ['registers' => ['20'], 'schemas' => [31, 28]],
				'page' => 1,
				'rows' => [
					['@self' => ['uuid' => 'uuid-x', 'schema' => 31], 'id' => 'pid', 'title' => 'Titel', 'summary' => 'Samenvatting'],
					['@self' => ['uuid' => 'uuid-y', 'schema' => 28], 'id' => 'pid-2', 'title' => 'Titel twee', 'summary' => 'Niet gebruikt'],
				],
				'next' => null,
			]],
			'search rows as entities' => [['catalog' => $catalog, 'page' => 1, 'entities' => true, 'rows' => array_slice($rows, 0, 2), 'next' => null]],
		];

	}//end worlds()

	/**
	 * Replay a world and compare it to the recording.
	 *
	 * @param array<string, mixed> $world The world.
	 *
	 * @return void
	 */
	#[DataProvider('worlds')]
	public function testDocumentMatchesTheRecording(array $world): void {
		$this->log = ['searched' => [], 'files' => []];
		$violations = [['earlier' => true]];
		$document = $this->service(world: $world)->buildCatalogDocument(
			catalog: $world['catalog'],
			catalogSlug: 'open',
			page: $world['page'],
			violations: $violations
		);
		// Without a modified date, lastModified is the moment of the build.
		$now = time();
		$at = strtotime(substr((string)$document['_meta']['lastModified'], 0, -4) . ' UTC');
		if ($at !== false && abs($at - $now) <= 5 && $at > strtotime('2026-06-01 UTC')) {
			$document['_meta']['lastModified'] = '<now>';
		}

		$actual = ['document' => $document, 'violations' => $violations, 'log' => $this->log];

		$recorded = [];
		if (file_exists(self::FIXTURE) === true) {
			$recorded = json_decode((string)file_get_contents(self::FIXTURE), true);
		}

		$name = (string)$this->dataName();
		if (getenv('GOLDEN_RECORD') === '1') {
			$recorded[$name] = json_decode((string)json_encode($actual), true);
			file_put_contents(self::FIXTURE, json_encode($recorded, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
		}

		$this->assertArrayHasKey($name, $recorded, 'No recording for ' . $name);
		$this->assertSame($recorded[$name], json_decode((string)json_encode($actual), true));

	}//end testDocumentMatchesTheRecording()

	/**
	 * Build the service around the world's OpenRegister answers.
	 *
	 * @param array<string, mixed> $world The world.
	 *
	 * @return DcatService
	 */
	private function service(array $world): DcatService {
		$appManager = $this->createMock(IAppManager::class);
		$appManager->method('getInstalledApps')->willReturn(['openregister']);
		$urlGenerator = $this->createMock(IURLGenerator::class);
		$urlGenerator->method('getBaseUrl')->willReturn('https://host');
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(static fn ($app, $key, $default = '') => $default);

		$objectService = $this->createMock(ObjectService::class);
		$objectService->method('searchObjectsPaginated')->willReturnCallback(
			function (...$args) use ($world): array {
				$this->log['searched'][] = $args;
				$rows = $world['rows'];
				if (($world['entities'] ?? false) === true) {
					$rows = array_map(static fn (array $row): object => new class($row) implements \JsonSerializable {
						public function __construct(private array $row) {
						}

						public function jsonSerialize(): array {
							return $this->row;
						}
					}, $rows);
					return ['next' => $world['next'], 'results' => $rows];
				}

				return ['results' => $rows, 'next' => $world['next']];
			}
		);
		$fileService = $this->createMock(FileService::class);
		$fileService->method('getFiles')->willReturnCallback(
			function ($object) {
				$this->log['files'][] = $object;
				if ($object === 'u3') {
					throw new RuntimeException('files down');
				}

				return ['f-' . $object];
			}
		);
		$fileService->method('formatFiles')->willReturnCallback(
			static fn (array $files) => ['results' => [['title' => $files[0] . '.pdf', 'downloadUrl' => 'https://host/d/' . $files[0], 'type' => 'application/pdf', 'size' => 1]]]
		);
		$schemaMapper = $this->createMock(SchemaMapper::class);
		$schemaMapper->method('find')->willReturnCallback(
			function (int $id) {
				$schema = $this->getMockBuilder(\OCA\OpenRegister\Db\Schema::class)
					->disableOriginalConstructor()
					->onlyMethods(['jsonSerialize'])
					->getMock();
				$schema->method('jsonSerialize')->willReturn(match ($id) {
					30 => ['x-dcat' => false],
					31 => ['x-dcat' => ['mapping' => ['dct:title' => 'summary']]],
					default => [],
				});
				return $schema;
			}
		);
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			static fn (string $id) => match ($id) {
				'OCA\\OpenRegister\\Service\\ObjectService' => $objectService,
				'OCA\\OpenRegister\\Service\\FileService' => $fileService,
				'OCA\\OpenRegister\\Db\\SchemaMapper' => $schemaMapper,
			}
		);

		$quality = $this->createMock(QualityService::class);
		$quality->method('scoreDataset')->willReturnCallback(static fn (array $node) => ['score' => count($node)]);
		$quality->method('dqvMeasurements')->willReturnCallback(static fn (array $score) => [['dqv:value' => $score['score']]]);

		return new DcatService(
			$container,
			$appManager,
			new DcatMappingService(new DcatVocabularyService()),
			new DcatSerializer(),
			$urlGenerator,
			$appConfig,
			$this->createMock(LoggerInterface::class),
			$this->createMock(StandardsVersionService::class),
			$quality
		);

	}//end service()
}//end class
