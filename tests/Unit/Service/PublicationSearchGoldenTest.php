<?php

/**
 * Golden test for the scoped publication search behind `PublicationService::index()`, `uses()` and `used()`.
 *
 * Runs `index()` over a scripted OpenRegister ObjectService and compares the
 * response (status and body) and every call the service made to OpenRegister
 * with what was recorded: the parameter mapping to `_extend`, the virtual
 * facet requests taken out of the query, the fail-closed empty scope, the
 * register and schema narrowing and its refusals, and the stripped envelope.
 *
 * Re-record (only when behaviour is meant to change) with
 * `GOLDEN_RECORD=1 vendor/bin/phpunit tests/Unit/Service/PublicationSearchGoldenTest.php`.
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
 * @spec openspec/specs/publications/spec.md
 *
 * @covers \OCA\OpenCatalogi\Service\PublicationService
 * @uses \OCA\OpenCatalogi\Service\CallerScope
 */

declare(strict_types=1);

namespace Unit\Service;

use OCA\OpenCatalogi\Service\DirectoryService;
use OCA\OpenCatalogi\Service\DownloadService;
use OCA\OpenCatalogi\Service\PublicationQueryService;
use OCA\OpenCatalogi\Service\PublicationService;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService;
use OCP\App\IAppManager;
use OCP\IAppConfig;
use OCP\IRequest;
use OCP\IUserSession;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;

/**
 * Golden test for PublicationService::index() and the search it runs.
 */
class PublicationSearchGoldenTest extends TestCase {

	private const FIXTURE = __DIR__ . '/../../fixtures/publication-search-golden.json';

	/**
	 * The request, catalogs and OpenRegister page each case reads.
	 *
	 * @return array<string, array{0: array<string, mixed>}>
	 */
	public static function cases(): array {
		$page = [
			'results' => [
				['@self' => ['id' => 'p1', 'owner' => 'admin', 'organisation' => 'org', 'files' => [['published' => '2025'], ['name' => 'draft']]], 'title' => 'One'],
				['@self' => ['id' => 'p2', 'files' => 'none'], 'title' => 'Two'],
				['title' => 'No self'],
			],
			'facets' => ['@self' => ['register' => ['buckets' => []]]],
			'total' => 3,
		];

		return [
			'plain request' => [[
				'params' => ['id' => 'x', '_route' => 'r', '_search' => 'woo', '_limit' => 10],
				'catalogs' => [['registers' => [1, 2], 'schemas' => [10, 11]]],
				'page' => $page,
			]],
			'parameters mapped to _extend' => [[
				'params' => ['extend' => ['@self.schema'], 'fields' => 'title', 'facets' => 'x', 'order' => ['title' => 'asc'], 'page' => 2, 'limit' => 5],
				'catalogs' => [['registers' => [1], 'schemas' => [10]]],
				'page' => ['results' => [], 'total' => 0],
			]],
			'narrowed to one register and schema' => [[
				'params' => ['@self' => ['register' => 2, 'schema' => '11', 'owner' => 'me'], 'register' => 9, '_facets' => ['@self' => ['register' => ['type' => 'terms']]]],
				'catalogs' => [['registers' => [1, 2], 'schemas' => [10, 11]]],
				'page' => $page,
			]],
			'narrowed with lists' => [[
				'params' => ['@self' => ['register' => [1, 2], 'schema' => [10]]],
				'catalogs' => [['registers' => [1, 2], 'schemas' => [10, 11]], ['registers' => [2], 'schemas' => [11]]],
				'page' => $page,
			]],
			'register outside the scope' => [[
				'params' => ['@self' => ['register' => 7, 'schema' => 99]],
				'catalogs' => [['registers' => [1], 'schemas' => [10]]],
			]],
			'schema outside the scope' => [[
				'params' => ['@self' => ['register' => 1, 'schema' => [10, 99]]],
				'catalogs' => [['registers' => [1], 'schemas' => [10]]],
			]],
			'empty schema scope fails closed' => [[
				'params' => ['_search' => 'x'],
				'catalogs' => [['registers' => [1], 'schemas' => []]],
			]],
			'empty register scope fails closed' => [[
				'params' => [],
				'catalogs' => [['schemas' => [10]]],
			]],
			'directory facet requested' => [[
				'params' => ['_facets' => ['@self' => ['directory' => ['type' => 'terms'], 'schema' => ['type' => 'terms']]]],
				'catalogs' => [['registers' => [1], 'schemas' => [10]]],
				'page' => $page,
			]],
			'catalogs facet requested' => [[
				'params' => ['_facets' => ['@self' => ['catalogs' => ['type' => 'terms']]]],
				'catalogs' => [['registers' => [1], 'schemas' => [10]]],
				'page' => $page,
			]],
			'one catalog by id' => [[
				'catalogId' => 'cat-1',
				'params' => ['_search' => 'x'],
				'catalogs' => [['registers' => [1, 2], 'schemas' => [10, 11]]],
				'catalog' => ['registers' => [3], 'schemas' => [30, 31]],
				'page' => $page,
			]],
			'related ids' => [[
				'ids' => ['uuid-1', 'uuid-2'],
				'params' => ['_search' => 'x', '_ids' => ['caller']],
				'catalogs' => [['registers' => [1], 'schemas' => [10]]],
				'page' => $page,
			]],
			'no related ids' => [[
				'ids' => [],
				'params' => ['_ids' => ['caller']],
				'catalogs' => [['registers' => [1], 'schemas' => [10]]],
				'page' => $page,
			]],
			'custom parameters win' => [[
				'customParams' => ['_limit' => 5, 'limit' => 7, '@self' => ['schema' => 10]],
				'params' => ['_search' => 'ignored'],
				'catalogs' => [['registers' => [1], 'schemas' => [10, 11]]],
				'page' => ['results' => [], 'facets' => [], 'total' => 0],
			]],
		];

	}//end cases()

	/**
	 * Run one case and compare it with the recording.
	 *
	 * @param array<string, mixed> $case The case input.
	 *
	 * @return void
	 */
	#[DataProvider('cases')]
	public function testIndexMatchesTheRecording(array $case): void {
		$name = $this->dataName();
		$actual = $this->search(case: $case);

		$recorded = [];
		if (is_file(self::FIXTURE) === true) {
			$recorded = json_decode((string)file_get_contents(self::FIXTURE), true);
		}

		if (getenv('GOLDEN_RECORD') === '1') {
			$recorded[$name] = $actual;
			file_put_contents(self::FIXTURE, json_encode($recorded, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
		}

		$this->assertArrayHasKey($name, $recorded, 'No recording for this case.');
		$this->assertSame(
			json_encode($recorded[$name], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES),
			json_encode($actual, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
		);

	}//end testIndexMatchesTheRecording()

	/**
	 * Build the service over scripted collaborators and call index().
	 *
	 * @param array<string, mixed> $case The case input.
	 *
	 * @return array<string, mixed> The status, body and OpenRegister calls.
	 */
	private function search(array $case): array {
		$calls = [];
		$entity = function (array $data): ObjectEntity {
			$object = $this->getMockBuilder(ObjectEntity::class)
				->disableOriginalConstructor()
				->onlyMethods(['jsonSerialize'])
				->getMock();
			$object->method('jsonSerialize')->willReturn($data);
			return $object;
		};

		$objectService = $this->createMock(ObjectService::class);
		$objectService->method('searchObjects')->willReturnCallback(
			static function (array $query = []) use (&$calls, $case, $entity) {
				$calls[] = ['searchObjects' => $query];
				return array_map($entity, $case['catalogs']);
			}
		);
		$objectService->method('find')->willReturnCallback(
			static function ($id) use (&$calls, $case, $entity) {
				$calls[] = ['find' => $id];
				$catalog = $entity($case['catalog'] ?? []);
				$catalog->setRegister('catalog-register');
				$catalog->setSchema('catalog-schema');
				return $catalog;
			}
		);
		$objectService->method('searchObjectsPaginated')->willReturnCallback(
			static function (array $query = [], bool $_rbac = true, bool $_multitenancy = true) use (&$calls, $case) {
				$calls[] = ['searchObjectsPaginated' => $query, 'rbac' => $_rbac, 'multitenancy' => $_multitenancy];
				return ($case['page'] ?? ['results' => [], 'total' => 0]);
			}
		);

		$queryService = $this->createMock(PublicationQueryService::class);
		$queryService->method('applySchemaScopeReadRuleGuard')->willReturnArgument(0);

		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			static function (string $id) use ($objectService, $queryService) {
				if ($id === PublicationQueryService::class) {
					return $queryService;
				}

				return $objectService;
			}
		);

		$config = $this->createMock(IAppConfig::class);
		$config->method('getValueString')->willReturnCallback(
			static fn (string $app, string $key, string $default = ''): string => match ($key) {
				'catalog_register' => 'catalog-register',
				'catalog_schema' => 'catalog-schema',
				default => $default,
			}
		);

		$request = $this->createMock(IRequest::class);
		$request->method('getParams')->willReturn($case['params']);
		$appManager = $this->createMock(IAppManager::class);
		$appManager->method('getInstalledApps')->willReturn(['openregister']);
		$directoryService = $this->createMock(DirectoryService::class);
		$directoryService->method('getUniqueDirectories')->willReturn(['https://dir-a.nl/apps/opencatalogi/api/directory', 'https://dir-b.nl']);
		$directoryService->method('getDirectory')->willReturn(['results' => []]);
		$userSession = $this->createMock(IUserSession::class);
		$userSession->method('getUser')->willReturn(null);

		$service = new PublicationService(
			$config,
			$request,
			$container,
			$appManager,
			$directoryService,
			$this->createMock(DownloadService::class),
			$userSession
		);

		if (array_key_exists('ids', $case) === true) {
			$search = new \ReflectionMethod(PublicationService::class, 'searchPublications');
			return [
				'body' => $search->invoke($service, null, $case['ids']),
				'calls' => $calls,
			];
		}

		$response = $service->index(catalogId: ($case['catalogId'] ?? null), customParams: ($case['customParams'] ?? null));

		return [
			'status' => $response->getStatus(),
			'body' => $response->getData(),
			'calls' => $calls,
		];

	}//end search()
}//end class
