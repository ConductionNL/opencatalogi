<?php

/**
 * Golden test for CatalogiService::index.
 *
 * Every world hands index() a request, a catalogue scope, a read-rule guard,
 * a session and a page of search results, and records the query and flags
 * the search ran with and the response it returned.
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

use OCA\OpenCatalogi\Service\CatalogiService;
use OCA\OpenCatalogi\Service\PublicationQueryService;
use OCA\OpenRegister\Service\ObjectService;
use OCP\App\IAppManager;
use OCP\IAppConfig;
use OCP\ICache;
use OCP\ICacheFactory;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Golden test for CatalogiService::index.
 *
 * @covers \OCA\OpenCatalogi\Service\CatalogiService
 * @uses   \OCA\OpenCatalogi\Service\CallerScope
 * @uses   \OCA\OpenCatalogi\Service\CatalogScopeResolver
 */
class CatalogIndexGoldenTest extends TestCase {

	private const FIXTURE = __DIR__ . '/fixtures/catalog-index-golden.json';

	/**
	 * The @self block every result row starts with.
	 */
	private const SELF = [
		'id' => 'row-1',
		'register' => 21,
		'schema' => 28,
		'schemaVersion' => '1.0',
		'relations' => ['a'],
		'locked' => null,
		'owner' => 'alice',
		'folder' => 9,
		'application' => 'app',
		'validation' => [],
		'retention' => [],
		'size' => 12,
		'deleted' => null,
		'published' => '2026-01-01',
		'uri' => 'https://x/row-1',
	];

	/**
	 * The search calls the world made.
	 *
	 * @var list<array<string, mixed>>
	 */
	private array $searches = [];

	/**
	 * The recorded worlds.
	 *
	 * @return array<string, array{0: array<string, mixed>}>
	 */
	public static function worlds(): array {
		$rows = [
			['as' => 'entity', 'row' => ['title' => 'Een', '@self' => self::SELF]],
			['as' => 'array', 'row' => ['title' => 'Twee', '@self' => self::SELF]],
			['as' => 'entity', 'row' => ['title' => 'Drie', '@self' => 'not-an-array']],
			['as' => 'array', 'row' => ['title' => 'Vier']],
		];

		return [
			'no params, anonymous' => [['params' => [], 'session' => 'anonymous', 'rows' => $rows]],
			'no params, signed in' => [['params' => [], 'session' => 'user', 'rows' => $rows]],
			'no session service' => [['params' => [], 'session' => 'none', 'rows' => $rows]],
			'limit, offset, page' => [['params' => ['limit' => '5', 'offset' => '15', 'page' => '2', 'q' => 'x'], 'session' => 'anonymous', 'rows' => []]],
			'underscored paging' => [['params' => ['_limit' => '7', '_offset' => '3', '_page' => '9'], 'session' => 'anonymous', 'rows' => []]],
			'page without offset' => [['params' => ['page' => '3', 'limit' => '10'], 'session' => 'anonymous', 'rows' => []]],
			'underscored page only' => [['params' => ['_page' => '4'], 'session' => 'anonymous', 'rows' => []]],
			'queries as string' => [['params' => ['queries' => 'title'], 'session' => 'anonymous', 'rows' => []]],
			'queries as list' => [['params' => ['_queries' => ['a', 'b']], 'session' => 'anonymous', 'rows' => []]],
			'order and extend' => [['params' => ['order' => ['title' => 'asc'], '_extend' => 'x', 'fields' => 'a', '_unset' => 'b', '_search' => 's'], 'session' => 'anonymous', 'rows' => []]],
			'id and route stripped' => [['params' => ['id' => 4, '_route' => 'r', 'kind' => 'k'], 'session' => 'anonymous', 'rows' => []]],
			'forged scope and identity, anonymous' => [[
				'params' => [
					'@self' => ['register' => 99, 'schema' => 56, 'owner' => 'admin', 'organisation' => 'o'],
					'register' => 99,
					'schema' => 56,
					'_schemas' => [56],
					'_register' => 99,
					'_registers' => [99],
					'userId' => 'admin',
					'requestedBy' => 'admin',
					'owner' => 'admin',
				],
				'session' => 'anonymous',
				'rows' => $rows,
			],
			],
			'forged identity, signed in as bob' => [[
				'params' => ['userId' => 'admin', 'requestedBy' => 'admin', 'owner' => 'admin'],
				'session' => 'user',
				'rows' => $rows,
			],
			],
			'scalar @self filter' => [['params' => ['@self' => 'flat'], 'session' => 'anonymous', 'rows' => []]],
			'several registers and schemas' => [['params' => [], 'session' => 'anonymous', 'rows' => [], 'catalogs' => [['registers' => [21, 22], 'schemas' => [28, 29]], ['registers' => [22], 'schemas' => [30]]]]],
			'one register, several schemas' => [['params' => [], 'session' => 'anonymous', 'rows' => [], 'catalogs' => [['registers' => [21], 'schemas' => [28, 29]]]]],
			'catalog by slug' => [['params' => [], 'session' => 'anonymous', 'rows' => [], 'catalogId' => 'my-catalog']],
			'catalog by uuid' => [['params' => [], 'session' => 'anonymous', 'rows' => [], 'catalogId' => '0b8e2f8a-6f43-4d5e-9e8c-3a1b2c3d4e5f']],
			'catalog by int id' => [['params' => [], 'session' => 'anonymous', 'rows' => [], 'catalogId' => 12]],
			'guard drops every schema' => [['params' => [], 'session' => 'anonymous', 'rows' => $rows, 'guard' => 'none']],
			'guard drops one schema' => [['params' => [], 'session' => 'anonymous', 'rows' => [], 'guard' => 'drop-28', 'catalogs' => [['registers' => [21], 'schemas' => [28, 29]]]]],
			'catalog without registers' => [['params' => [], 'session' => 'anonymous', 'rows' => $rows, 'catalogs' => [['schemas' => [28]]]]],
			'catalog without schemas' => [['params' => [], 'session' => 'anonymous', 'rows' => $rows, 'catalogs' => [['registers' => [21], 'schemas' => 'x']]]],
			'unconfigured catalog register' => [['params' => [], 'session' => 'anonymous', 'rows' => $rows, 'unconfigured' => true]],
		];

	}//end worlds()

	/**
	 * Run index() in the world and compare with the recording.
	 *
	 * @param array<string, mixed> $world The world.
	 *
	 * @return void
	 */
	#[DataProvider('worlds')]
	public function testIndexMatchesTheRecording(array $world): void {
		$this->searches = [];
		$response = $this->service(world: $world)->index(catalogId: ($world['catalogId'] ?? null));

		$actual = [
			'status' => $response->getStatus(),
			'data' => $response->getData(),
			'searches' => $this->searches,
		];

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

	}//end testIndexMatchesTheRecording()

	/**
	 * A forged identity in the request never widens the anonymous envelope.
	 *
	 * The session has no user, while getParams() and getParam() both claim to
	 * be `admin`. The rows keep the stripped @self, and the forged keys only
	 * reach the search as plain filters next to the server's own scope.
	 *
	 * @return void
	 */
	public function testAForgedIdentityKeepsTheAnonymousEnvelope(): void {
		$this->searches = [];
		$world = [
			'params' => ['userId' => 'admin', 'requestedBy' => 'admin', 'owner' => 'admin', '@self' => ['owner' => 'admin', 'register' => 99]],
			'session' => 'anonymous',
			'rows' => [['as' => 'entity', 'row' => ['@self' => self::SELF]]],
		];

		$data = $this->service(world: $world)->index()->getData();

		$this->assertSame(['id' => 'row-1', 'register' => 21, 'schema' => 28, 'published' => '2026-01-01', 'uri' => 'https://x/row-1'], $data['results'][0]['@self']);
		$this->assertSame(21, $this->searches[1]['query']['@self']['register']);
		$this->assertSame(28, $this->searches[1]['query']['@self']['schema']);
		$this->assertTrue($this->searches[1]['rbac']);

	}//end testAForgedIdentityKeepsTheAnonymousEnvelope()

	/**
	 * The full envelope follows the session user, not a claim in the request.
	 *
	 * The session answers only for `bob`; the request claims `admin`. Bob is
	 * signed in, so he gets the full @self that OpenRegister already let him read.
	 *
	 * @return void
	 */
	public function testTheSessionUserDecidesTheFullEnvelope(): void {
		$this->searches = [];
		$world = [
			'params' => ['userId' => 'admin', 'requestedBy' => 'admin', 'owner' => 'admin'],
			'session' => 'user',
			'rows' => [['as' => 'array', 'row' => ['@self' => self::SELF]]],
		];

		$data = $this->service(world: $world)->index()->getData();

		$this->assertSame(self::SELF, $data['results'][0]['@self']);
		$this->assertSame('admin', $this->searches[1]['query']['userId']);

	}//end testTheSessionUserDecidesTheFullEnvelope()

	/**
	 * Build the service around the world.
	 *
	 * @param array<string, mixed> $world The world.
	 *
	 * @return CatalogiService
	 */
	private function service(array $world): CatalogiService {
		$request = $this->createMock(IRequest::class);
		$request->method('getParams')->willReturn($world['params']);
		$request->method('getParam')->willReturnCallback(static fn (string $key, $default = null) => ($world['params'][$key] ?? $default));

		$register = 'register-1';
		if (($world['unconfigured'] ?? false) === true) {
			$register = '';
		}

		$config = $this->createMock(IAppConfig::class);
		$config->method('getValueString')->willReturnMap([
			['opencatalogi', 'catalog_schema', '', 'schema-1'],
			['opencatalogi', 'catalog_register', '', $register],
		]);

		$catalogs = array_map(
			static fn (array $row): object => self::entity(row: $row),
			($world['catalogs'] ?? [['registers' => [21], 'schemas' => [28]]])
		);
		$rows = array_map(
			static fn (array $spec): mixed => $spec['as'] === 'entity' ? self::entity(row: $spec['row']) : $spec['row'],
			$world['rows']
		);

		$objectService = $this->createMock(ObjectService::class);
		$objectService->method('searchObjects')->willReturnCallback(
			function (array $query, bool $_rbac, bool $_multitenancy) use ($catalogs): array {
				$this->searches[] = ['kind' => 'catalogs', 'query' => $query, 'rbac' => $_rbac, 'multitenancy' => $_multitenancy];
				return $catalogs;
			}
		);
		$objectService->method('searchObjectsPaginated')->willReturnCallback(
			function (array $query, bool $_rbac, bool $_multitenancy) use ($rows): array {
				$this->searches[] = ['kind' => 'page', 'query' => $query, 'rbac' => $_rbac, 'multitenancy' => $_multitenancy];
				return ['results' => $rows, 'total' => count($rows), 'page' => 1];
			}
		);

		$guard = ($world['guard'] ?? 'pass');
		$queryService = $this->createMock(PublicationQueryService::class);
		$queryService->method('applySchemaScopeReadRuleGuard')->willReturnCallback(
			static fn (array $schemaIds): array => match ($guard) {
				'none' => [],
				'drop-28' => array_values(array_filter($schemaIds, static fn ($id): bool => $id !== 28)),
				default => $schemaIds,
			}
		);

		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			static fn (string $id) => $id === PublicationQueryService::class ? $queryService : $objectService
		);

		$appManager = $this->createMock(IAppManager::class);
		$appManager->method('getInstalledApps')->willReturn(['openregister']);

		$cacheFactory = $this->createMock(ICacheFactory::class);
		$cacheFactory->method('createDistributed')->willReturn($this->createMock(ICache::class));

		$session = null;
		if ($world['session'] !== 'none') {
			$session = $this->createMock(IUserSession::class);
			$user = null;
			if ($world['session'] === 'user') {
				$user = $this->createMock(IUser::class);
				$user->method('getUID')->willReturn('bob');
			}

			$session->method('getUser')->willReturn($user);
		}

		return new CatalogiService(
			$config,
			$request,
			$container,
			$appManager,
			$cacheFactory,
			$this->createMock(LoggerInterface::class),
			$session,
		);

	}//end service()

	/**
	 * Wrap a row in an object that serialises to it.
	 *
	 * @param array<string, mixed> $row The row.
	 *
	 * @return object The entity.
	 */
	private static function entity(array $row): object {
		return new class($row) implements \JsonSerializable {
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
		};

	}//end entity()
}//end class
