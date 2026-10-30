<?php

/**
 * Golden test for the public full-text search assembly.
 *
 * Runs `PublicationQueryService::assemblePublicSearchResults()` over scripted
 * OpenRegister doubles and compares everything the service does with what it
 * did when the transcript was recorded: the envelope, every query and find it
 * sent to OpenRegister, the anonymous-scope depth of each read, and every log
 * line. A refactor of the assembly must leave all of it unchanged.
 *
 * Re-record (only when behaviour is meant to change) with
 * `GOLDEN_RECORD=1 vendor/bin/phpunit tests/Unit/Service/PublicSearchGoldenTest.php`.
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
 * @spec openspec/specs/search/spec.md
 *
 * @covers \OCA\OpenCatalogi\Service\PublicationQueryService
 * @uses \OCA\OpenCatalogi\Service\CallerScope
 * @uses \OCA\OpenCatalogi\Service\CatalogScopeIds
 * @uses \OCA\OpenCatalogi\Service\SearchRangeGuard
 */

declare(strict_types=1);

namespace Unit\Service;

use OCA\OpenCatalogi\Service\PublicationQueryService;
use OCP\IAppConfig;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\AbstractLogger;

/**
 * A row OpenRegister hands back as an entity rather than an array.
 */
class GoldenSearchEntity implements \JsonSerializable {

	/**
	 * @param array<string, mixed> $data The serialised row.
	 */
	public function __construct(private array $data) {
	}

	public function jsonSerialize(): array {
		return $this->data;
	}
}

/**
 * A scripted OpenRegister ObjectService that records every call.
 */
class GoldenSearchObjectService {

	/** @var array<int, array<string, mixed>> Every call, in order. */
	public array $calls = [];

	private int $depth = 0;

	/**
	 * @param array<string, mixed> $script Main result, refinements, slug scan, finds.
	 */
	public function __construct(private array $script) {
	}

	public function runAsAnonymous(callable $operation): mixed {
		$this->depth++;
		try {
			return $operation();
		} finally {
			$this->depth--;
		}
	}

	public function buildSearchQuery(array $requestParams): array {
		$this->calls[] = ['buildSearchQuery' => $requestParams, 'depth' => $this->depth];
		return $requestParams;
	}

	public function searchObjectsPaginated(array $query, bool $_rbac = true, bool $_multitenancy = true): array {
		$this->calls[] = ['search' => $query, 'rbac' => $_rbac, 'multitenancy' => $_multitenancy, 'depth' => $this->depth];

		if (isset($query['_relations_contains']) === true) {
			$answer = ($this->script['refinements'][$query['_relations_contains']] ?? ['results' => []]);
			if ($answer === 'throw') {
				throw new \RuntimeException('refinement down');
			}
			return $answer;
		}

		if (($query['_limit'] ?? null) === 500) {
			return ['results' => ($this->script['slugScan'] ?? [])];
		}

		return $this->script['main'];
	}

	public function find(string $id, array $_extend = [], bool $files = false, ?int $register = null, ?int $schema = null, bool $_rbac = true, bool $_multitenancy = true): mixed {
		$this->calls[] = ['find' => $id, 'register' => $register, 'schema' => $schema, 'depth' => $this->depth];
		$answer = ($this->script['finds'][$id] ?? 'throw');
		if ($answer === 'throw') {
			throw new \RuntimeException('not found');
		}
		return $answer;
	}
}

/**
 * Logger that keeps every record.
 */
class GoldenSearchLogger extends AbstractLogger {

	/** @var array<int, array<string, mixed>> */
	public array $records = [];

	public function log($level, string|\Stringable $message, array $context = []): void {
		$this->records[] = ['level' => $level, 'message' => (string)$message, 'context' => $context];
	}
}

class PublicSearchGoldenTest extends TestCase {

	private const FIXTURE = __DIR__ . '/../../fixtures/public-search-golden.json';

	/**
	 * Schema id => [slug, authorization]. 99 throws on find.
	 */
	private const SCHEMAS = [
		1 => ['publication', ['read' => [['group' => 'public']]]],
		2 => ['document', ['read' => [['group' => 'public']]]],
		3 => ['besluit', ['read' => [['group' => 'public']]]],
		4 => ['document', []],
		5 => ['open', []],
		6 => ['document', ['read' => [['group' => 'public']]]],
	];

	/**
	 * @return array<string, array{0: array<string, mixed>, 1: array<string, mixed>, 2: array<string, mixed>}>
	 */
	public static function scenarios(): array {
		$published = '2020-01-01T00:00:00+00:00';
		$single = ['slug' => 'raad', 'listed' => true, 'published' => $published, 'registers' => [10], 'schemas' => [1, 2, 3, 5]];
		$multi = ['slug' => 'multi', 'listed' => true, 'published' => $published, 'registers' => [10, 11], 'schemas' => [1, 3]];

		$mainRows = [
			['@self' => ['id' => 'p-1', 'schema' => 1], 'title' => 'One', '_snippet' => 'x', 'snippet' => 'x', 'chunk' => 'c', 'chunk_id' => 1, 'chunkId' => 1, 'score' => 3, '_score' => 4],
			['@self' => ['id' => 'p-1', 'schema' => 1], 'title' => 'One again'],
			['@self' => ['id' => 'p-2'], 'title' => 'No schema'],
			['@self' => ['id' => 'p-3', 'schema' => 99], 'title' => 'Schema lookup throws'],
			['@self' => ['id' => 'p-4', 'schema' => 1], 'status' => 'archived'],
			new GoldenSearchEntity(['@self' => ['id' => 'p-5', 'schema' => ['id' => 3]], 'title' => 'Entity']),
			['id' => 'p-6', 'schema' => '3', 'title' => 'Top-level id'],
			['@self' => ['schema' => 3], 'title' => 'No id at all'],
			['@self' => ['id' => 'd-1', 'schema' => 2], 'publication' => ['id' => 'p-1']],
			['@self' => ['id' => 'd-2', 'schema' => 2], 'publication' => ['id' => 'p-archived', 'slug' => 'slug-a']],
			['@self' => ['id' => 'd-3', 'schema' => 2, 'relations' => ['publication' => 'p-missing', 'publication.slug' => 'slug-a']]],
			['@self' => ['id' => 'd-4', 'schema' => 2], '_relations' => ['publication.slug' => 'slug-a']],
			['@self' => ['id' => 'd-5', 'schema' => 2], '_relations' => ['publication.slug' => 'slug-archived']],
			['@self' => ['id' => 'd-6', 'schema' => 2]],
			['@self' => ['id' => 'd-7', 'schema' => 2]],
			['@self' => ['id' => 'd-8', 'schema' => 2]],
			['@self' => ['id' => 'd-9', 'schema' => 2]],
			['@self' => ['id' => 'd-1', 'schema' => 2], 'publication' => ['id' => 'p-1']],
			['@self' => ['schema' => 2], 'title' => 'Document without id'],
			['@self' => ['id' => 'b-1', 'schema' => 3], 'status' => 'concept'],
		];
		$mixedScript = [
			'main' => ['results' => $mainRows, 'total' => 40, 'facets' => ['type' => ['a' => 1]], 'facetable' => ['type' => true]],
			'finds' => [
				'p-1' => ['@self' => ['id' => 'p-1', 'slug' => 'one'], 'title' => 'One'],
				'p-archived' => new GoldenSearchEntity(['id' => 'p-archived', 'status' => 'archived']),
				'p-null' => null,
			],
			'slugScan' => [
				['@self' => ['id' => 'p-a1', 'slug' => 'slug-a'], 'title' => 'A first'],
				new GoldenSearchEntity(['id' => 'p-a2', 'slug' => 'slug-a', 'title' => 'A second']),
				['@self' => ['id' => 'p-x', 'slug' => 'slug-archived'], 'status' => 'archived'],
				['@self' => ['id' => 'p-y', 'slug' => 'other']],
			],
			'refinements' => [
				'd-6' => ['results' => [['@self' => ['id' => 'p-r1', 'slug' => 'r-one'], 'title' => 'Refined']]],
				'd-7' => ['results' => [new GoldenSearchEntity(['id' => 'p-r2', 'slug' => 'r-two']), ['id' => 'p-r3']]],
				'd-8' => ['results' => []],
				'd-9' => 'throw',
			],
		];

		return [
			'catalogue not found' => [['_catalog' => 'missing'], ['catalog' => null], ['main' => ['results' => [], 'total' => 0]]],
			'catalogue not yet published' => [['_catalog' => 'raad'], ['catalog' => ['published' => '2999-01-01T00:00:00+00:00'] + $single], ['main' => ['results' => [], 'total' => 0]]],
			'every schema lacks read rules' => [['_catalog' => 'raad'], ['catalog' => ['schemas' => [5, 99]] + $single], ['main' => ['results' => [], 'total' => 0]]],
			'mixed rows on one register' => [
				['_catalog' => 'raad', '_search' => 'x', '_limit' => '500', 'schema' => 9, 'register' => 9, 'fq' => 'a', 'catalogSlug' => 'b', '_schema' => 9, '_registers' => [9], '@self' => ['schema' => 9, 'owner' => 'me']],
				['catalog' => $single],
				$mixedScript,
			],
			'mixed rows without a publication schema' => [
				['_catalog' => 'raad', '_limit' => 100],
				['catalog' => ['schemas' => [2, 3]] + $single],
				$mixedScript,
			],
			'content search widens over two registers' => [
				['_catalogi' => ['multi', '', 7, 'missing'], '_content' => 'true', '_limit' => 20],
				['catalogs' => ['multi' => $multi], 'registers' => [10 => [1, 2, 4, 0, 99], 11 => [6, 2]]],
				['main' => ['results' => [['@self' => ['id' => 'd-6', 'schema' => 6]], ['@self' => ['id' => 'p-1', 'schema' => 1]]], 'total' => 2], 'refinements' => $mixedScript['refinements']],
			],
			'content search without register mappers' => [
				['_catalog' => 'raad', '_content' => '1'],
				['catalog' => $single, 'registers' => 'throw'],
				['main' => ['results' => [['@self' => ['id' => 'b-1', 'schema' => 3]]], 'total' => 1]],
			],
			'default scope unions listed catalogues' => [
				['_search' => 'x'],
				['catalogs' => ['raad' => $single, 'multi' => $multi]],
				['main' => ['results' => [], 'total' => 8]],
			],
			'empty first page by page number' => [
				['_catalog' => 'raad', '_page' => '1', '_offset' => '0'],
				['catalog' => $single],
				['main' => ['results' => [], 'total' => 8]],
			],
			'empty later page keeps the total' => [
				['_catalog' => 'raad', '_offset' => 40],
				['catalog' => $single],
				['main' => ['results' => [], 'total' => 31]],
			],
			'empty later page by page number' => [
				['_catalog' => 'raad', '_page' => 3],
				['catalog' => $single],
				['main' => ['results' => [], 'total' => 31]],
			],
			'drops floor the total at the rows shipped' => [
				['_catalog' => 'raad'],
				['catalog' => $single],
				['main' => ['results' => [['@self' => ['id' => 'b-1', 'schema' => 3]], ['@self' => ['id' => 'x', 'schema' => 3], 'status' => 'archived'], ['@self' => ['id' => 'y', 'schema' => 3], 'status' => 'archived']]]],
			],
		];
	}

	/**
	 * @param array<string, mixed> $params The request query.
	 * @param array<string, mixed> $world  Catalogues and registers.
	 * @param array<string, mixed> $script What the ObjectService double answers.
	 */
	#[DataProvider('scenarios')]
	public function testTheAssemblyMatchesTheRecordedTranscript(array $params, array $world, array $script): void {
		$name = (string)$this->dataName();
		$transcript = $this->assemble(params: $params, world: $world, script: $script);

		$recorded = [];
		if (is_file(self::FIXTURE) === true) {
			$recorded = json_decode((string)file_get_contents(self::FIXTURE), true);
		}

		if (getenv('GOLDEN_RECORD') === '1') {
			$recorded[$name] = $transcript;
			ksort($recorded);
			file_put_contents(self::FIXTURE, json_encode($recorded, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
			$this->markTestSkipped('Recorded.');
		}

		$this->assertArrayHasKey($name, $recorded, 'No recorded transcript for this scenario.');
		$this->assertSame($recorded[$name], $transcript);
	}

	/**
	 * @return array<string, mixed> Envelope, OpenRegister calls and log records, JSON-normalised.
	 */
	private function assemble(array $params, array $world, array $script): array {
		$store = new GoldenSearchObjectService($script);
		$logger = new GoldenSearchLogger();

		$catalogi = new class($world) {
			public function __construct(private array $world) {
			}

			public function getCatalogBySlug(string $slug): ?array {
				if (array_key_exists('catalog', $this->world) === true) {
					return $this->world['catalog'];
				}
				return ($this->world['catalogs'][$slug] ?? null);
			}

			public function getObjectService(): object {
				return new class(array_values($this->world['catalogs'] ?? [])) {
					public function __construct(private array $catalogs) {
					}

					public function searchObjects(array $query, bool $_rbac = true, bool $_multitenancy = true): array {
						return array_merge(
							[new GoldenSearchEntity(['slug' => 'draft', 'listed' => true, 'registers' => [12], 'schemas' => [3]])],
							$this->catalogs
						);
					}
				};
			}
		};

		$schemaFor = static function (int $id): object {
			if (isset(self::SCHEMAS[$id]) === false) {
				throw new \RuntimeException("schema $id missing");
			}
			return new class(self::SCHEMAS[$id]) {
				public function __construct(private array $schema) {
				}

				public function getSlug(): string {
					return $this->schema[0];
				}

				public function getAuthorization(): ?array {
					return $this->schema[1];
				}
			};
		};
		$schemaMapper = new class($schemaFor) {
			public function __construct(private \Closure $schemaFor) {
			}

			public function find(int|string $id): object {
				return ($this->schemaFor)((int)$id);
			}
		};
		$registerMapper = new class(is_array($world['registers'] ?? null) ? $world['registers'] : []) {
			public function __construct(private array $registers) {
			}

			public function find(int $id): object {
				if (isset($this->registers[$id]) === false) {
					throw new \RuntimeException("register $id missing");
				}
				return new class($this->registers[$id]) {
					public function __construct(private array $schemas) {
					}

					public function getSchemas(): array {
						return $this->schemas;
					}
				};
			}
		};

		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			static function (string $key) use ($catalogi, $schemaMapper, $registerMapper, $world) {
				if ($key === 'OCA\\OpenRegister\\Db\\RegisterMapper' && ($world['registers'] ?? null) === 'throw') {
					throw new \RuntimeException('no register mapper');
				}
				return match ($key) {
					'OCA\\OpenCatalogi\\Service\\CatalogiService' => $catalogi,
					'OCA\\OpenRegister\\Db\\SchemaMapper' => $schemaMapper,
					'OCA\\OpenRegister\\Db\\RegisterMapper' => $registerMapper,
					default => throw new \RuntimeException("Unmocked container key: $key"),
				};
			}
		);

		$config = $this->createMock(IAppConfig::class);
		$config->method('getValueString')->willReturnCallback(
			static fn (string $app, string $key): string => match ($key) {
				'catalog_register' => '20',
				'catalog_schema' => '21',
				default => '',
			}
		);

		$service = new PublicationQueryService(
			container: $container,
			config: $config,
			logger: $logger,
		);

		$envelope = $service->assemblePublicSearchResults(queryParams: $params, objectService: $store);

		return json_decode((string)json_encode([
			'envelope' => $envelope,
			'calls' => $store->calls,
			'logs' => $logger->records,
		]), true);
	}
}
