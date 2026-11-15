<?php

/**
 * Golden test for three PublicationQueryService steps: the catalog index query,
 * the object location lookup and the listed-and-published catalog enumeration.
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
 * @spec openspec/specs/search/spec.md
 */

declare(strict_types=1);

namespace Unit\Service;

use OCA\OpenCatalogi\Service\PublicationQueryService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Every world of the three steps gives the recorded answer, OpenRegister calls and log lines.
 *
 * @covers \OCA\OpenCatalogi\Service\PublicationQueryService
 * @uses \OCA\OpenCatalogi\Service\CallerScope
 * @uses \OCA\OpenCatalogi\Service\CatalogScopeIds
 */
class PublicationQuerySplitGoldenTest extends TestCase {

	private const FIXTURE = __DIR__ . '/fixtures/publication-query-split-golden.json';

	/**
	 * The calls and log lines of the current world.
	 *
	 * @var array<int, mixed>
	 */
	private array $log = [];

	/**
	 * An ObjectService fake: buildSearchQuery() answers its input, find() and
	 * searchObjects() answer from the world and record their arguments.
	 *
	 * @param array<string, mixed> $world The world.
	 *
	 * @return object
	 */
	private function objectService(array $world): object {
		$log = &$this->log;
		return new class($world, $log) {
			/**
			 * @param array<string, mixed> $world The world.
			 * @param array<int, mixed> $log The calls, by reference.
			 */
			public function __construct(
				private array $world,
				private array &$log,
			) {
			}

			/**
			 * The request parameters as the query, unchanged.
			 *
			 * @param array $params The request parameters.
			 *
			 * @return array
			 */
			public function buildSearchQuery(array $params): array {
				return $params;
			}

			/**
			 * Answer a lookup in one register and schema from the world.
			 *
			 * @return object|null
			 */
			public function find(string $id, array $_extend = [], bool $files = false, int|string|null $register = null, int|string|null $schema = null, bool $_rbac = true, bool $_multitenancy = true): ?object {
				$this->log[] = ['find' => [$id, $_extend, $files, $register, $schema, $_rbac, $_multitenancy]];
				$answer = ($this->world['find'][$register . '/' . $schema] ?? null);
				if ($answer === 'missing') {
					throw new DoesNotExistException('missing');
				}

				if ($answer === 'fails') {
					throw new \RuntimeException('store down');
				}

				return $answer === 'found' ? new \stdClass() : null;
			}

			/**
			 * Answer the catalog enumeration from the world.
			 *
			 * @return array
			 */
			public function searchObjects(array $query = [], bool $_rbac = true, bool $_multitenancy = true): array {
				$this->log[] = ['searchObjects' => [$query, $_rbac, $_multitenancy]];
				if (($this->world['catalogs'] ?? null) === 'fails') {
					throw new \RuntimeException('catalog store down');
				}

				return array_map(
					static fn (mixed $row): mixed => match (true) {
						$row === 'broken entity' => new class {
							/**
							 * A row that cannot serialise.
							 *
							 * @return array
							 */
							public function jsonSerialize(): array {
								throw new \RuntimeException('cannot serialise');
							}
						},
						is_array($row) === true && isset($row['entity']) === true => new class($row['entity']) {
							/**
							 * @param array $data The catalog.
							 */
							public function __construct(
								private array $data,
							) {
							}

							/**
							 * The catalog.
							 *
							 * @return array
							 */
							public function jsonSerialize(): array {
								return $this->data;
							}
						},
						default => $row,
					},
					(array)($this->world['catalogs'] ?? [])
				);
			}
		};
	}//end objectService()

	/**
	 * The service around the world's container, config and logger.
	 *
	 * @param array<string, mixed> $world The world.
	 *
	 * @return PublicationQueryService
	 */
	private function service(array $world): PublicationQueryService {
		$objectService = $this->objectService(world: $world);
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			static function (string $id) use ($world, $objectService): object {
				if (($world['noObjectService'] ?? false) === true) {
					throw new \RuntimeException('not installed');
				}

				return $objectService;
			}
		);
		$config = $this->createMock(IAppConfig::class);
		$config->method('getValueString')->willReturnCallback(
			static fn (string $app, string $key, string $default = ''): string => (string)($world['config'][$key] ?? $default)
		);
		$logger = $this->createMock(LoggerInterface::class);
		foreach (['warning', 'error'] as $level) {
			$logger->method($level)->willReturnCallback(
				function (string $message, array $context = []) use ($level): void {
					$this->log[] = [$level => [$message, $context]];
				}
			);
		}

		return new PublicationQueryService(container: $container, config: $config, logger: $logger);
	}//end service()

	/**
	 * The worlds of the catalog index query.
	 *
	 * @return array<string, array{0: array, 1: array}>
	 */
	private static function queryWorlds(): array {
		$params = ['_search' => 'wet', 'catalogSlug' => 'woo', 'fq' => 'x', 'schema' => '99', 'register' => '98', '_schema' => '97'];
		return [
			'no scope' => [[], $params],
			'one schema, one register' => [['schemas' => [3], 'registers' => [1]], $params],
			'one schema, two registers' => [['schemas' => ['3'], 'registers' => [1, 2]], $params],
			'two schemas, one register' => [['schemas' => [3, 4], 'registers' => [1]], $params],
			'two schemas, no register' => [['schemas' => [3, 4]], $params],
			'duplicates and words' => [['schemas' => ['19', 19, 'abc', -1], 'registers' => ['1', 1, 'x']], $params],
			'order as json, one register' => [['schemas' => [3], 'registers' => [1]], ['_order' => '{"name":"asc","_created":"desc"}']],
			'order as json, two registers' => [['schemas' => [3], 'registers' => [1, 2]], ['_order' => '{"name":"asc","@self.created":"desc","_updated":"asc"}']],
			'order only on fields some schemas lack' => [['schemas' => [3], 'registers' => [1, 2]], ['_order' => ['name' => 'asc', 'naam' => 'desc']]],
			'order not json' => [['schemas' => [3], 'registers' => [1, 2]], ['_order' => 'name']],
			'order json scalar' => [['schemas' => [3], 'registers' => [1, 2]], ['_order' => '5']],
			'empty order, two registers' => [['schemas' => [3], 'registers' => [1, 2]], ['_order' => []]],
			'registers only' => [['registers' => [1, 2]], ['_order' => ['_uuid' => 'asc', '@self.owner' => 'asc']]],
		];
	}//end queryWorlds()

	/**
	 * The worlds of the object location lookup: allowed registers, allowed schemas, and the store.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private static function locationWorlds(): array {
		return [
			'no registers' => ['registers' => [], 'schemas' => [3]],
			'no schemas' => ['registers' => [1], 'schemas' => null],
			'no object service' => ['registers' => [1], 'schemas' => [3], 'noObjectService' => true],
			'found in the first pair' => ['registers' => [1], 'schemas' => [3], 'find' => ['1/3' => 'found']],
			'found in a later pair' => ['registers' => ['1', 2], 'schemas' => [3, '4'], 'find' => ['1/3' => 'missing', '1/4' => 'fails', '2/3' => null, '2/4' => 'found']],
			'words are skipped' => ['registers' => ['abc', 2], 'schemas' => ['x', 4, '4.5'], 'find' => ['2/4' => 'found']],
			'nowhere' => ['registers' => [1, 2], 'schemas' => [3], 'find' => ['1/3' => 'missing', '2/3' => 'fails']],
		];
	}//end locationWorlds()

	/**
	 * The worlds of the listed-and-published catalog enumeration.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private static function catalogWorlds(): array {
		$config = ['catalog_register' => '5', 'catalog_schema' => '6'];
		return [
			'no object service' => ['catalogiObjects' => null, 'config' => $config],
			'no catalog register' => ['config' => ['catalog_schema' => '6']],
			'no catalog schema' => ['config' => ['catalog_register' => '5']],
			'search fails' => ['config' => $config, 'catalogs' => 'fails'],
			'rows of every kind' => ['config' => $config, 'catalogs' => [
				['slug' => 'open', 'published' => '2001-01-01T00:00:00+00:00'],
				['slug' => 'future', 'published' => '2999-01-01T00:00:00+00:00'],
				['slug' => 'never'],
				['slug' => 'garbled', 'published' => 'gisteren'],
				'broken entity',
				['entity' => ['slug' => 'entity', 'published' => '2002-02-02T00:00:00+00:00']],
			]],
		];
	}//end catalogWorlds()

	/**
	 * Every world of the three steps matches its recording.
	 *
	 * @return void
	 */
	public function testTheThreeStepsMatchTheRecording(): void {
		$actual = [];
		foreach (self::queryWorlds() as $name => [$catalog, $params]) {
			$this->log = [];
			$service = $this->service(world: []);
			$actual['query: ' . $name] = ['answer' => $service->buildCatalogSearchQuery($catalog, $params, $this->objectService(world: [])), 'log' => $this->log];
		}

		foreach (self::locationWorlds() as $name => $world) {
			$this->log = [];
			$answer = $this->service(world: $world)->findObjectLocation('uuid-1', $world['registers'], $world['schemas']);
			$actual['location: ' . $name] = ['answer' => $answer, 'log' => $this->log];
		}

		$method = new \ReflectionMethod(PublicationQueryService::class, 'listListedPublishedCatalogs');
		foreach (self::catalogWorlds() as $name => $world) {
			$this->log = [];
			$objects = array_key_exists('catalogiObjects', $world) === true ? null : $this->objectService(world: $world);
			$catalogi = new class($objects) {
				/**
				 * @param object|null $objects The ObjectService it hands out.
				 */
				public function __construct(
					private ?object $objects,
				) {
				}

				/**
				 * The ObjectService, or null.
				 *
				 * @return object|null
				 */
				public function getObjectService(): ?object {
					return $this->objects;
				}
			};
			$actual['catalogs: ' . $name] = ['answer' => $method->invoke($this->service(world: $world), $catalogi), 'log' => $this->log];
		}

		$actual = json_decode((string)json_encode($actual), true);
		if (getenv('GOLDEN_RECORD') === '1') {
			file_put_contents(self::FIXTURE, json_encode($actual, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
		}

		$recorded = json_decode((string)file_get_contents(self::FIXTURE), true);
		$this->assertSame(array_keys($recorded), array_keys($actual));
		foreach ($recorded as $name => $expected) {
			$this->assertSame($expected, $actual[$name], $name);
		}
	}//end testTheThreeStepsMatchTheRecording()
}//end class
