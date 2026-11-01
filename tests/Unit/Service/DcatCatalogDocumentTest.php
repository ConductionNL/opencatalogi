<?php

/**
 * Characterization test for DcatService::buildCatalogDocument().
 *
 * Compares the whole document of a busy page (an opted-out schema, attachments,
 * a failing attachment lookup, HVD, DQV, paging both ways) with a snapshot taken
 * from the implementation before it was split.
 *
 * @category Test
 * @package  OCA\OpenCatalogi\Tests
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
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
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

class DcatCatalogDocumentTest extends TestCase {
	private const SNAPSHOT = __DIR__ . '/../../fixtures/dcat/catalog-document-page-2.json';

	/**
	 * Build the service around fixed OpenRegister answers.
	 *
	 * @param array $searched Receives the search query.
	 *
	 * @return DcatService
	 */
	private function service(array &$searched): DcatService {
		$appManager = $this->createMock(IAppManager::class);
		$appManager->method('getInstalledApps')->willReturn(['openregister']);
		$urlGenerator = $this->createMock(IURLGenerator::class);
		$urlGenerator->method('getBaseUrl')->willReturn('https://host');
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(
			static fn ($app, $key, $default = '') => match ($key) {
				'dcat_default_publisher_name' => 'Gemeente Voorbeeld',
				'dcat_default_license' => 'http://creativecommons.org/publicdomain/zero/1.0/deed.nl',
				default => $default,
			}
		);

		$objectService = $this->createMock(ObjectService::class);
		$objectService->method('searchObjectsPaginated')->willReturnCallback(
			static function (...$args) use (&$searched): array {
				$searched = $args;
				return [
					'results' => [
						['@self' => ['uuid' => 'u1', 'schema' => 28, 'updated' => '2026-02-03T04:05:06+00:00'], 'id' => 'u1', 'title' => 'Besluit een', 'summary' => 'Kort'],
						['@self' => ['uuid' => 'u2', 'schema' => 30], 'id' => 'u2', 'title' => 'Uitgezonderd'],
						['@self' => ['uuid' => 'u3', 'schema' => 29], 'id' => 'u3', 'title' => 'Besluit drie', 'publicationDate' => '2025-12-01'],
						['@self' => ['uuid' => 'u4', 'schema' => 99], 'title' => 'Zonder id'],
					],
					'next' => 'yes',
				];
			}
		);
		$fileService = $this->createMock(FileService::class);
		$fileService->method('getFiles')->willReturnCallback(
			static function ($object) {
				if ($object === 'u3') {
					throw new RuntimeException('files down');
				}

				return ['f-' . $object];
			}
		);
		$fileService->method('formatFiles')->willReturnCallback(
			static fn (array $files) => ['results' => [[
				'title' => $files[0] . '.pdf',
				'downloadUrl' => 'https://host/download/' . $files[0],
				'type' => 'application/pdf',
				'size' => 42,
				'published' => '2026-01-01T00:00:00+00:00',
			]]]
		);
		$schemaMapper = $this->createMock(SchemaMapper::class);
		$schemaMapper->method('find')->willReturnCallback(
			function (int $id) {
				if ($id === 29) {
					throw new RuntimeException('no schema');
				}

				$schema = $this->getMockBuilder(\OCA\OpenRegister\Db\Schema::class)
					->disableOriginalConstructor()
					->onlyMethods(['jsonSerialize'])
					->getMock();
				$schema->method('jsonSerialize')->willReturn(match ($id) {
					30 => ['x-dcat' => false],
					default => ['x-dcat' => ['hvd' => ['category' => 'http://data.europa.eu/bna/c_ac64a52d']]],
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
	}

	public function testABusyPageMatchesTheSnapshot(): void {
		$searched = [];
		$violations = [['earlier' => true]];
		$document = $this->service($searched)->buildCatalogDocument(
			catalog: [
				'id' => 'c1',
				'title' => 'WOO',
				'description' => 'Besluiten',
				'registers' => ['20'],
				'schemas' => '[28, 29, 30, "x"]',
				'dcatHvdCategory' => 'http://data.europa.eu/bna/c_dd313021',
				'dqvExposure' => 'true',
			],
			catalogSlug: 'woo',
			page: 2,
			violations: $violations
		);

		$actual = ['document' => $document, 'violations' => $violations, 'searched' => $searched];
		if (getenv('DCAT_SNAPSHOT_WRITE') === '1') {
			file_put_contents(self::SNAPSHOT, json_encode($actual, (JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)) . "\n");
		}

		$this->assertSame(
			json_decode((string)file_get_contents(self::SNAPSHOT), true),
			json_decode((string)json_encode($actual), true)
		);
	}

	public function testWithoutOpenRegisterTheDocumentIsRefused(): void {
		$appManager = $this->createMock(IAppManager::class);
		$appManager->method('getInstalledApps')->willReturn([]);
		$service = new DcatService(
			$this->createMock(ContainerInterface::class),
			$appManager,
			new DcatMappingService(new DcatVocabularyService()),
			new DcatSerializer(),
			$this->createMock(IURLGenerator::class),
			$this->createMock(IAppConfig::class),
			$this->createMock(LoggerInterface::class),
			$this->createMock(StandardsVersionService::class)
		);

		$this->expectException(RuntimeException::class);
		$service->buildCatalogDocument(catalog: ['schemas' => []], catalogSlug: 'leeg');
	}
}
