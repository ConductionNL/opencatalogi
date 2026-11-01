<?php

/**
 * Golden test for DcatService::buildInstanceDocument.
 *
 * Every world is recorded once and replayed from the fixture: the document
 * and the catalog search asked. Build-time dates are recorded by their shape.
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
use OCA\OpenCatalogi\Service\StandardsVersionService;
use OCA\OpenRegister\Service\ObjectService;
use OCP\App\IAppManager;
use OCP\IAppConfig;
use OCP\IURLGenerator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Golden test for DcatService::buildInstanceDocument.
 *
 * @covers \OCA\OpenCatalogi\Service\DcatService
 * @uses \OCA\OpenCatalogi\Service\DcatMappingService
 * @uses \OCA\OpenCatalogi\Service\DcatVocabularyService
 */
class DcatInstanceDocumentGoldenTest extends TestCase {

	private const FIXTURE = __DIR__ . '/fixtures/dcat-instance-document-golden.json';

	/**
	 * The searches asked.
	 *
	 * @var list<mixed>
	 */
	private array $searched = [];

	/**
	 * The recorded worlds: config values and the catalog rows.
	 *
	 * @return array<string, array{0: array<string, mixed>}>
	 */
	public static function worlds(): array {
		$scope = ['catalog_register' => '7', 'catalog_schema' => '8'];
		$org = ['name' => 'Gemeente Zuiddrecht', 'website' => 'https://zuiddrecht.nl', 'email' => 'info@zuiddrecht.nl'];

		return [
			'not configured' => [['config' => [], 'rows' => [['slug' => 'a']]]],
			'no catalogs' => [['config' => $scope, 'rows' => []]],
			'defaults only' => [['config' => $scope, 'rows' => [['slug' => 'open', 'title' => 'Open'], ['slug' => 'zonder-titel'], ['title' => 'Geen slug'], ['slug' => '', 'title' => 'Leeg'], ['slug' => 12]]]],
			'first catalog organisation' => [['config' => $scope, 'rows' => [['slug' => 'a', 'organisation' => $org], ['slug' => 'b', 'organisation' => ['name' => 'Tweede']]]]],
			'organization spelling' => [['config' => $scope, 'rows' => [['slug' => 'a', 'organization' => $org]]]],
			'organisation not an array' => [['config' => $scope, 'rows' => [['slug' => 'a', 'organisation' => 'uuid-1']]]],
			'only a later catalog has a slug' => [['config' => $scope, 'rows' => [['title' => 'x', 'organisation' => $org], ['slug' => 'b']]]],
			'configured instance' => [[
				'config' => $scope + [
					'dcat_publisher_name' => 'Uitgever',
					'dcat_publisher_uri' => 'https://uitgever.nl',
					'dcat_instance_title' => 'Zuiddrecht data',
					'dcat_instance_homepage' => 'https://data.zuiddrecht.nl',
					'dcat_default_license' => '',
					'dcat_contact_point' => 'Team data',
				],
				'rows' => [['slug' => 'a', 'title' => 'A']],
			],
			],
			'publisher name only' => [['config' => $scope + ['dcat_publisher_name' => 'Alleen naam', 'dcat_default_license' => 'https://lic'], 'rows' => [['slug' => 'a']]]],
			'catalog publisher fields ignored' => [['config' => $scope, 'rows' => [['slug' => 'a', 'dcatPublisherName' => 'Catalogus', 'dcatLicense' => 'https://cat-lic', 'dcatContactPoint' => 'Cat']]]],
			'entity rows' => [['config' => $scope, 'rows' => [['slug' => 'e', 'title' => 'Entity']], 'entities' => true]],
		];

	}//end worlds()

	/**
	 * Build the instance document and compare with the recording.
	 *
	 * @param array<string, mixed> $world The world.
	 *
	 * @return void
	 */
	#[DataProvider('worlds')]
	public function testDocumentMatchesTheRecording(array $world): void {
		$this->searched = [];
		$document = $this->service(world: $world)->buildInstanceDocument();

		$now = time();
		foreach ($document['@graph'] as $index => $node) {
			if (isset($node['dct:modified']) === true && abs(strtotime((string)$node['dct:modified']) - $now) <= 5) {
				$document['@graph'][$index]['dct:modified'] = '<now>';
			}
		}

		if (abs(strtotime(substr((string)$document['_meta']['lastModified'], 0, -4) . ' UTC') - $now) <= 5) {
			$document['_meta']['lastModified'] = '<now>';
		}

		$actual = json_decode((string)json_encode(['document' => $document, 'searched' => $this->searched]), true);

		$recorded = [];
		if (file_exists(self::FIXTURE) === true) {
			$recorded = json_decode((string)file_get_contents(self::FIXTURE), true);
		}

		$name = (string)$this->dataName();
		if (getenv('GOLDEN_RECORD') === '1') {
			$recorded[$name] = $actual;
			file_put_contents(self::FIXTURE, json_encode($recorded, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
		}

		$this->assertArrayHasKey($name, $recorded, 'No recording for ' . $name);
		$this->assertSame($recorded[$name], $actual);

	}//end testDocumentMatchesTheRecording()

	/**
	 * Build the service around the world's config and catalog rows.
	 *
	 * @param array<string, mixed> $world The world.
	 *
	 * @return DcatService
	 */
	private function service(array $world): DcatService {
		$appManager = $this->createMock(IAppManager::class);
		$appManager->method('getInstalledApps')->willReturn(['openregister']);
		$urlGenerator = $this->createMock(IURLGenerator::class);
		$urlGenerator->method('getBaseUrl')->willReturn('https://host/');
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(
			static fn ($app, $key, $default = '') => ($world['config'][$key] ?? $default)
		);

		$objectService = $this->createMock(ObjectService::class);
		$objectService->method('searchObjectsPaginated')->willReturnCallback(
			function (...$args) use ($world): array {
				$this->searched[] = $args;
				$rows = $world['rows'];
				if (($world['entities'] ?? false) === true) {
					$rows = array_map(static fn (array $row): object => new class($row) implements \JsonSerializable {
						/**
						 * @param array<string, mixed> $row The row.
						 */
						public function __construct(private array $row) {
						}

						/**
						 * @return array<string, mixed>
						 */
						public function jsonSerialize(): array {
							return $this->row;
						}
					}, $rows);
				}

				return ['results' => $rows];
			}
		);
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturn($objectService);

		return new DcatService(
			$container,
			$appManager,
			new DcatMappingService(new DcatVocabularyService()),
			new DcatSerializer(),
			$urlGenerator,
			$appConfig,
			$this->createMock(LoggerInterface::class),
			$this->createMock(StandardsVersionService::class)
		);

	}//end service()
}//end class
