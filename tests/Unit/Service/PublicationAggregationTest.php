<?php

/**
 * Characterization tests for PublicationService::getAggregatedPublications().
 *
 * Pins the full response envelope of every path (federated, federation failure,
 * local fast path, local ultra-fast path) so the method can be split safely.
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
use OCA\OpenCatalogi\Service\DownloadService;
use OCA\OpenCatalogi\Service\PublicationQueryService;
use OCA\OpenCatalogi\Service\PublicationService;
use OCA\OpenRegister\Service\ObjectService;
use OCP\App\IAppManager;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IAppConfig;
use OCP\IRequest;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use RuntimeException;

class PublicationAggregationTest extends TestCase {
	private IAppConfig|MockObject $config;
	private ContainerInterface|MockObject $container;
	private IAppManager|MockObject $appManager;
	private DirectoryService|MockObject $directoryService;
	private ObjectService|MockObject $objectService;

	/** @var array<int, array|null> customParams passed to each index() call */
	private array $indexCalls = [];

	protected function setUp(): void {
		$this->config = $this->createMock(IAppConfig::class);
		$this->container = $this->createMock(ContainerInterface::class);
		$this->appManager = $this->createMock(IAppManager::class);
		$this->directoryService = $this->createMock(DirectoryService::class);
		$this->objectService = $this->createMock(ObjectService::class);

		$this->appManager->method('getInstalledApps')->willReturn(['openregister']);

		$queryService = $this->createMock(PublicationQueryService::class);
		$queryService->method('applySchemaScopeReadRuleGuard')->willReturnArgument(0);
		$this->container->method('get')->willReturnCallback(
			function (string $id) use ($queryService) {
				if ($id === PublicationQueryService::class) {
					return $queryService;
				}

				return $this->objectService;
			}
		);
	}

	/**
	 * Build the service with index() replaced by a fixture-driven stub.
	 *
	 * @param callable $indexData Maps the customParams of an index() call to its data.
	 *
	 * @return PublicationService|MockObject
	 */
	private function serviceWithIndex(callable $indexData): PublicationService {
		$userSession = $this->createMock(IUserSession::class);
		$userSession->method('getUser')->willReturn(null);

		$service = $this->getMockBuilder(PublicationService::class)
			->setConstructorArgs([
				$this->config,
				$this->createMock(IRequest::class),
				$this->container,
				$this->appManager,
				$this->directoryService,
				$this->createMock(DownloadService::class),
				$userSession,
			])
			->onlyMethods(['index'])
			->getMock();

		$service->method('index')->willReturnCallback(
			function ($catalogId, ?array $customParams) use ($indexData) {
				$this->indexCalls[] = $customParams;
				return new JSONResponse($indexData($customParams));
			}
		);

		return $service;
	}

	/**
	 * Configure the two catalog keys so getLocalCatalogs() and the ultra-fast path search.
	 *
	 * @return void
	 */
	private function configureCatalogScope(): void {
		$this->config->method('getValueString')->willReturnCallback(
			static function (string $app, string $key, string $default = ''): string {
				return match ($key) {
					'catalog_register' => 'cat-reg',
					'catalog_schema' => 'cat-sch',
					default => $default,
				};
			}
		);
	}

	/**
	 * Replace the time-dependent values of a response with fixed markers.
	 *
	 * @param array $response The response envelope.
	 *
	 * @return array The envelope with execution_time_ms and timing values masked.
	 */
	private function normalise(array $response): array {
		if (isset($response['_performance']['execution_time_ms']) === true) {
			$response['_performance']['execution_time_ms'] = 'T';
		}

		if (isset($response['_performance']['timings']) === true) {
			$response['_performance']['timings'] = array_map(static fn () => 'T', $response['_performance']['timings']);
		}

		return $response;
	}

	/**
	 * Catalog entity stub returned by ObjectService::searchObjects().
	 *
	 * @param array $data The serialised catalog.
	 *
	 * @return \OCA\OpenRegister\Db\ObjectEntity
	 */
	private function catalogEntity(array $data): \OCA\OpenRegister\Db\ObjectEntity {
		$obj = $this->getMockBuilder(\OCA\OpenRegister\Db\ObjectEntity::class)
			->disableOriginalConstructor()
			->onlyMethods(['jsonSerialize'])
			->getMock();
		$obj->method('jsonSerialize')->willReturn($data);
		return $obj;
	}

	public function testFederatedMergeDeduplicatesOrdersAndPaginates(): void {
		$this->configureCatalogScope();
		$this->objectService->method('searchObjects')->willReturn([
			$this->catalogEntity(['id' => 'c1', 'slug' => 'cat-one', 'title' => 'Catalog one']),
		]);
		$this->directoryService->method('getUniqueDirectories')->willReturn(['https://peer.example.org/api']);

		$service = $this->serviceWithIndex(
			static function (?array $params): array {
				if ($params['_limit'] === 4) {
					return [
						'results' => [
							['id' => 'a', 'title' => 'Bravo'],
							['id' => 'b', 'title' => 'Delta', '@self' => 'not-an-array'],
							['id' => 'c', 'title' => 'Alpha'],
						],
						'total' => 3,
						'facets' => ['facets' => ['type' => ['buckets' => []]]],
						'facetable' => ['type' => ['type' => 'terms']],
					];
				}

				return [
					'results' => [['id' => 'a', 'title' => 'Bravo'], ['id' => 'b', 'title' => 'Delta']],
					'total' => 3,
					'facets' => ['facets' => ['type' => ['buckets' => []]]],
				];
			}
		);

		$fedQuery = null;
		$this->directoryService->method('getPublications')->willReturnCallback(
			static function (array $config) use (&$fedQuery): array {
				$fedQuery = $config;
				return [
					'results' => [
						['id' => 'a', 'title' => 'Bravo (remote copy)'],
						['uuid' => 'r1', 'title' => 'Charlie'],
					],
					'total' => 2,
					'facets' => ['remote' => true],
				];
			}
		);

		$query = [
			'_limit' => '2',
			'_page' => '2',
			'_order' => ['title' => 'ASC'],
			'_facets' => ['@self' => ['directory' => ['type' => 'terms'], 'other' => 1]],
			'_facets[directory]' => 'x',
			'_facets[@self][catalogs]' => 'y',
			'offset' => '1',
			'q' => 'search',
		];
		$result = $service->getAggregatedPublications($query, ['q' => 'search'], 'https://host/api');

		$this->assertSame(
			[
				[
					'_limit' => 2,
					'_page' => 2,
					'_order' => ['title' => 'ASC'],
					'_facets' => ['@self' => ['directory' => ['type' => 'terms'], 'other' => 1]],
					'_facets[directory]' => 'x',
					'_facets[@self][catalogs]' => 'y',
					'offset' => '1',
					'q' => 'search',
					'_offset' => 1,
				],
				[
					'_limit' => 4,
					'_page' => 1,
					'_order' => ['title' => 'ASC'],
					'_facets' => ['@self' => ['directory' => ['type' => 'terms'], 'other' => 1]],
					'_facets[directory]' => 'x',
					'_facets[@self][catalogs]' => 'y',
					'offset' => '1',
					'q' => 'search',
				],
			],
			$this->indexCalls
		);
		$this->assertSame(
			['query_params' => ['_order' => ['title' => 'ASC'], '_facets' => ['@self' => ['other' => 1]], 'q' => 'search']],
			$fedQuery
		);

		$catalogs = [[
			'id' => 'c1',
			'slug' => 'cat-one',
			'title' => 'Catalog one',
			'summary' => 'Local catalog instance',
			'description' => null,
			'organization' => null,
			'listed' => false,
		]];
		$normalised = $this->normalise($result);
		$this->assertSame($this->expectedPageTwo($catalogs), $normalised['results']);
		$this->assertSame(5, $normalised['total']);
		$this->assertSame(2, $normalised['limit']);
		$this->assertSame(2, $normalised['offset']);
		$this->assertSame(2, $normalised['page']);
		$this->assertSame(3.0, $normalised['pages']);
		$this->assertSame('https://host/api?q=search&_page=3', $normalised['next']);
		$this->assertSame('https://host/api?q=search&_page=1', $normalised['prev']);
		$this->assertSame(['type' => ['buckets' => []], '@self' => ['directory' => [
			'type' => 'terms',
			'buckets' => [
				['key' => 'local', 'label' => 'local', 'results' => 1],
				['key' => 'peer.example.org', 'label' => 'peer.example.org', 'results' => 1],
			],
		]]], $normalised['facets']);
		$this->assertSame(['type' => ['type' => 'terms']], array_diff_key($normalised['facetable'], ['@self' => 1]));
		$this->assertSame(['directory', 'catalogs'], array_keys($normalised['facetable']['@self']));
		$this->assertSame(
			[
				'execution_time_ms' => 'T',
				'fast_path' => false,
				'federation' => true,
				'cached_catalogs' => true,
				'cached_filters' => false,
			],
			$normalised['_performance']
		);
		$this->assertSame(
			['results', 'total', 'limit', 'offset', 'page', 'pages', 'next', 'prev', 'facets', 'facetable', '_performance'],
			array_keys($normalised)
		);
	}

	/**
	 * Expected page two of the merged, ordered set: Charlie (remote) and Delta (local).
	 *
	 * @param array $catalogs The local catalogs stamped on local results.
	 *
	 * @return array
	 */
	private function expectedPageTwo(array $catalogs): array {
		return [
			['uuid' => 'r1', 'title' => 'Charlie'],
			['id' => 'b', 'title' => 'Delta', '@self' => ['directory' => 'local', 'catalogs' => $catalogs]],
		];
	}

	public function testFederationFailureReturnsTheLocalFirstPage(): void {
		$this->config->method('getValueString')->willReturn('');
		$this->directoryService->method('getUniqueDirectories')->willReturn(['https://peer.example.org']);
		$this->directoryService->method('getPublications')->willThrowException(new RuntimeException('down'));

		$service = $this->serviceWithIndex(
			static fn (?array $params): array => [
				'results' => [['id' => 'x', '@self' => ['id' => 'x']]],
				'total' => 1,
			]
		);

		$result = $this->normalise($service->getAggregatedPublications(['_facetable' => 'true'], [], '/api'));

		$this->assertSame([['id' => 'x', '@self' => ['id' => 'x', 'directory' => 'local']]], $result['results']);
		$this->assertSame(1, $result['total']);
		$this->assertSame(20, $result['limit']);
		$this->assertSame(0, $result['offset']);
		$this->assertSame(1, $result['page']);
		$this->assertSame(1, $result['pages']);
		$this->assertArrayNotHasKey('next', $result);
		$this->assertArrayNotHasKey('prev', $result);
		$this->assertArrayNotHasKey('facets', $result);
		$this->assertSame(['@self'], array_keys($result['facetable']));
		$this->assertSame(
			[
				'execution_time_ms' => 'T',
				'fast_path' => false,
				'federation' => false,
				'error' => 'Federation failed, returned local only',
				'cached_catalogs' => false,
				'cached_filters' => false,
			],
			$result['_performance']
		);
		$this->assertCount(2, $this->indexCalls);
	}

	public function testFederatedPathWithoutFacetsBuildsVirtualFacetsAndLimitZero(): void {
		$this->config->method('getValueString')->willReturn('');
		$this->directoryService->method('getUniqueDirectories')->willReturn(['peer']);
		$this->directoryService->method('getPublications')->willReturn([]);

		$service = $this->serviceWithIndex(static fn (?array $params): array => ['results' => [], 'total' => 7]);

		$result = $this->normalise($service->getAggregatedPublications(
			['_limit' => 0, '_facets' => ['@self' => ['catalogs' => []]]],
			[],
			'/api'
		));

		$this->assertSame([], $result['results']);
		$this->assertSame(7, $result['total']);
		$this->assertSame(1, $result['pages']);
		$this->assertSame(0, $result['offset']);
		$this->assertSame(
			['@self' => ['catalogs' => ['type' => 'terms', 'buckets' => [['key' => 'default', 'label' => 'Default Catalog', 'results' => 1]]]]],
			$result['facets']
		);
		$this->assertArrayNotHasKey('facetable', $result);
		$this->assertTrue($result['_performance']['federation']);
	}

	public function testNoDirectoriesWithIncludeCatalogsUsesTheFastPath(): void {
		$this->configureCatalogScope();
		$this->objectService->method('searchObjects')->willReturn([$this->catalogEntity(['id' => 'c1'])]);
		$this->directoryService->method('getUniqueDirectories')->willReturn([]);
		$this->directoryService->expects($this->never())->method('getPublications');

		$service = $this->serviceWithIndex(
			static fn (?array $params): array => [
				'results' => [['id' => 'p1']],
				'total' => 45,
				'facets' => ['facets' => ['k' => 1]],
				'facetable' => ['k' => ['type' => 'terms']],
			]
		);

		$result = $this->normalise($service->getAggregatedPublications(
			['_include_catalogs' => 'true', '_page' => 2, 'limit' => 20],
			['a' => 'b'],
			'/api'
		));

		$this->assertSame(
			[
				'results' => [['id' => 'p1', '@self' => ['catalogs' => [[
					'id' => 'c1',
					'slug' => '',
					'title' => 'Local Catalog',
					'summary' => 'Local catalog instance',
					'description' => null,
					'organization' => null,
					'listed' => false,
				]]]]],
				'total' => 45,
				'limit' => 20,
				'offset' => 20,
				'page' => 2,
				'pages' => 3.0,
				'next' => '/api?a=b&_page=3',
				'prev' => '/api?a=b&_page=1',
				'facets' => ['k' => 1],
				'facetable' => ['k' => ['type' => 'terms']],
				'_performance' => [
					'execution_time_ms' => 'T',
					'fast_path' => true,
					'ultra_fast_path' => false,
					'include_catalogs' => true,
					'cached_catalogs' => true,
					'timings' => ['setup' => 'T', 'query' => 'T', 'processing' => 'T'],
				],
			],
			$result
		);
	}

	public function testDirectoryLookupFailureWithDirectoryFacetsUsesTheFastPath(): void {
		$this->config->method('getValueString')->willReturn('');
		$this->directoryService->method('getUniqueDirectories')->willThrowException(new RuntimeException('db'));

		$service = $this->serviceWithIndex(static fn (?array $params): array => ['results' => [['id' => 'p1']], 'total' => 1]);

		$result = $this->normalise($service->getAggregatedPublications(
			['_facets' => ['@self' => ['directory' => []]]],
			[],
			'/api'
		));

		$this->assertSame([['id' => 'p1', '@self' => ['directory' => 'local']]], $result['results']);
		$this->assertSame(['@self' => []], $result['facets']);
		$this->assertArrayNotHasKey('facetable', $result);
		$this->assertFalse($result['_performance']['include_catalogs']);
		$this->assertFalse($result['_performance']['cached_catalogs']);
	}

	public function testAggregateOffWithoutExtrasUsesTheUltraFastPath(): void {
		$this->configureCatalogScope();
		$this->objectService->method('searchObjects')->willReturn([
			$this->catalogEntity(['registers' => [5], 'schemas' => [9]]),
		]);
		$this->objectService->method('searchObjectsPaginated')->willReturn([
			'results' => [['id' => 'u1', '@self' => ['id' => 'u1', 'owner' => 'admin']]],
			'total' => 3,
			'facets' => ['facets' => ['f' => 1]],
			'facetable' => ['f' => []],
		]);
		$this->directoryService->expects($this->never())->method('getUniqueDirectories');

		$service = $this->serviceWithIndex(static fn (?array $params): array => []);

		$result = $this->normalise($service->getAggregatedPublications(
			['_aggregate' => '0', '_limit' => 1, '_page' => 2],
			[],
			'/api'
		));

		$this->assertSame([], $this->indexCalls);
		$this->assertSame('u1', $result['results'][0]['id']);
		$this->assertArrayNotHasKey('owner', $result['results'][0]['@self']);
		$this->assertSame(3, $result['total']);
		$this->assertSame(1, $result['offset']);
		$this->assertSame(3.0, $result['pages']);
		$this->assertSame('/api?_page=3', $result['next']);
		$this->assertSame('/api?_page=1', $result['prev']);
		$this->assertSame(['f' => 1], $result['facets']);
		$this->assertSame(['f' => []], $result['facetable']);
		$this->assertSame(
			[
				'execution_time_ms' => 'T',
				'fast_path' => true,
				'ultra_fast_path' => true,
				'include_catalogs' => false,
				'cached_catalogs' => false,
				'bypassed_middleware' => true,
				'skipped_filtering' => false,
				'processed_virtual_facets' => false,
				'cached_catalog_filters' => true,
				'timings' => ['setup' => 'T', 'objectservice' => 'T', 'response' => 'T', 'catalog_context' => 'T'],
			],
			$result['_performance']
		);
	}

	public function testFastPathWithoutExtrasFallsToUltraFastWhichFailsClosedOnAnUnconfiguredScope(): void {
		$this->config->method('getValueString')->willReturn('');

		$service = $this->serviceWithIndex(static fn (?array $params): array => ['results' => [], 'total' => 0]);

		$fast = $service->getAggregatedPublications(['_aggregate' => 'false', '_ultra_fast' => 'false'], [], '/api');
		$this->assertSame(
			['results' => [], 'total' => 0, 'limit' => 20, 'offset' => 0, 'page' => 1, 'pages' => 1],
			$fast
		);
		$this->assertCount(0, $this->indexCalls);

		$ultra = $service->getAggregatedPublications(['_aggregate' => 'false', '_limit' => '5'], [], '/api');
		$this->assertSame(
			['results' => [], 'total' => 0, 'limit' => 5, 'offset' => 0, 'page' => 1, 'pages' => 1],
			$ultra
		);
	}
}
