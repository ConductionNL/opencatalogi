<?php

/**
 * Characterization tests for DirectoryService::getDirectory().
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

use OCA\OpenCatalogi\Service\DirectoryService;
use OCP\App\IAppManager;
use OCP\IAppConfig;
use OCP\IURLGenerator;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use ReflectionClass;
use RuntimeException;

class DirectoryGetDirectoryTest extends TestCase {
	private const OWN = 'https://me.example.org/apps/opencatalogi/api/directory';

	private DirectoryService $service;

	/** @var object Fake ObjectService that records each query. */
	private object $objects;

	/** @var array<string, string> */
	private array $store = [
		'listing_schema' => 'ls',
		'listing_register' => 'lr',
		'catalog_register' => 'cr',
	];

	protected function setUp(): void {
		$urlGenerator = $this->createMock(IURLGenerator::class);
		$urlGenerator->method('linkToRoute')->willReturnArgument(0);
		$urlGenerator->method('getAbsoluteURL')->willReturnCallback(
			static fn (string $route): string => match ($route) {
				'opencatalogi.directory.index' => self::OWN,
				default => 'https://me.example.org/'.$route,
			}
		);
		$urlGenerator->method('getBaseUrl')->willReturn('https://me.example.org/');

		$config = $this->createMock(IAppConfig::class);
		$config->method('getValueString')->willReturnCallback(
			fn (string $app, string $key, string $default = ''): string => ($this->store[$key] ?? $default)
		);

		$appManager = $this->createMock(IAppManager::class);
		$appManager->method('getInstalledApps')->willReturn(['openregister']);
		$appManager->method('getAppInfo')->willReturn(['version' => '9.9.9']);

		$this->objects = new class {
			/** @var array<int, array<string, mixed>> */
			public array $queries = [];

			/** @var array<string, mixed> Results or a Throwable per schema. */
			public array $answers = [];

			public function searchObjects(array $query): array {
				$this->queries[] = $query;
				$answer = $this->answers[$query['@self']['schema']] ?? [];
				if ($answer instanceof \Throwable) {
					throw $answer;
				}

				return $answer;
			}
		};

		$schemaMapper = new class {
			public function findMultiple(array $ids): array {
				return array_map(static fn ($id): array => ['id' => $id, 'title' => 'Schema '.$id], $ids);
			}
		};

		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			fn (string $id): object => str_ends_with($id, 'SchemaMapper') ? $schemaMapper : $this->objects
		);

		$ref = new ReflectionClass(DirectoryService::class);
		$this->service = $ref->newInstanceWithoutConstructor();
		$props = [
			'appName' => 'opencatalogi',
			'urlGenerator' => $urlGenerator,
			'config' => $config,
			'container' => $container,
			'appManager' => $appManager,
		];
		foreach ($props as $name => $value) {
			$ref->getProperty($name)->setValue($this->service, $value);
		}
	}

	public function testFiltersAndPaginationReachBothQueriesExceptSchemaAndRegister(): void {
		$this->service->getDirectory([
			'filters' => ['title' => 'x', 'schema' => 'no', 'register' => 'no'],
			'limit' => '5',
			'offset' => '10',
		]);

		$this->assertSame(
			[
				['@self' => ['schema' => 'ls', 'register' => 'lr'], 'title' => 'x', '_limit' => 5, '_offset' => 10],
				['@self' => ['schema' => 'catalog', 'register' => 'cr'], 'title' => 'x', '_limit' => 5, '_offset' => 10],
			],
			$this->objects->queries
		);
	}

	public function testWithoutParametersOnlyTheScopeIsQueried(): void {
		$this->service->getDirectory();

		$this->assertSame(
			[
				['@self' => ['schema' => 'ls', 'register' => 'lr']],
				['@self' => ['schema' => 'catalog', 'register' => 'cr']],
			],
			$this->objects->queries
		);
	}

	public function testListingsFromOtherDirectoriesAreLeftOutAndInternalFieldsDropped(): void {
		$this->objects->answers['ls'] = [
			['id' => 'mine', 'directory' => self::OWN, 'status' => 'x', 'lastSync' => 'y', 'title' => 'Mine'],
			['object' => ['id' => 'nested', 'directory' => '', 'image' => 'i', 'default' => true], 'extra' => 1],
			['id' => 'peer', 'directory' => 'https://peer.example.org/api/directory'],
		];

		$result = $this->service->getDirectory();

		$this->assertSame(
			[
				'results' => [
					['id' => 'mine', 'directory' => self::OWN, 'title' => 'Mine'],
					['object' => ['id' => 'nested', 'directory' => ''], 'extra' => 1],
				],
				'total' => 2,
			],
			$result
		);
	}

	public function testIncludeFederatedKeepsListingsFromOtherDirectories(): void {
		$this->objects->answers['ls'] = [
			['id' => 'peer', 'directory' => 'https://peer.example.org/api/directory'],
		];

		$result = $this->service->getDirectory(['include-federated' => '1']);

		$this->assertSame([['id' => 'peer', 'directory' => 'https://peer.example.org/api/directory']], $result['results']);
	}

	public function testCatalogsBecomeListingsWithExpandedSchemasAfterTheListings(): void {
		$this->objects->answers['ls'] = [['id' => 'l1', 'directory' => self::OWN]];
		$this->objects->answers['catalog'] = [
			[
				'id' => 'c1',
				'title' => 'Catalog',
				'slug' => 'cat',
				'hasDcat' => 'true',
				'schemas' => [3],
				'status' => 'stable',
				'published' => '2026-01-01',
			],
		];

		$result = $this->service->getDirectory();

		$this->assertSame(2, $result['total']);
		$this->assertSame(['id' => 'l1', 'directory' => self::OWN], $result['results'][0]);
		$this->assertSame(
			[
				'id' => 'c1',
				'catalog' => 'c1',
				'catalogusId' => 'c1',
				'title' => 'Catalog',
				'summary' => 'Local catalog',
				'status' => 'stable',
				'description' => null,
				'organization' => null,
				'schemas' => [['id' => 3, 'title' => 'Schema 3']],
				'directory' => self::OWN,
				'search' => 'https://me.example.org/opencatalogi.search.index',
				'publications' => 'https://me.example.org/opencatalogi.federation.publications',
				'version' => '9.9.9',
				'published' => '2026-01-01',
				'dcatEndpoint' => 'https://me.example.org/apps/opencatalogi/api/catalogs/cat/dcat',
			],
			$result['results'][1]
		);
	}

	public function testAFailingQueryLeavesOutOnlyItsOwnPart(): void {
		$this->objects->answers['ls'] = new RuntimeException('down');
		$this->objects->answers['catalog'] = [['id' => 'c1', 'published' => 'p']];

		$result = $this->service->getDirectory();

		$this->assertSame(1, $result['total']);
		$this->assertSame('c1', $result['results'][0]['id']);

		$this->objects->answers['ls'] = [['id' => 'l1']];
		$this->objects->answers['catalog'] = new RuntimeException('down');

		$this->assertSame(['results' => [['id' => 'l1']], 'total' => 1], $this->service->getDirectory());
	}

	public function testAnUnconfiguredPartIsNotQueried(): void {
		$this->store = ['catalog_register' => 'cr', 'catalog_schema' => ''];

		$this->assertSame(['results' => [], 'total' => 0], $this->service->getDirectory());
		$this->assertSame([], $this->objects->queries);
	}
}
