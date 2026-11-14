<?php

/**
 * Tests for MostViewedPublications: ranking across the readable catalogues (ANA-005).
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/capability-rows-close-gaps/tasks.md#task-23
 */

declare(strict_types=1);

namespace Unit\Service;

use OCA\OpenCatalogi\Contract\CatalogiServiceInterface;
use OCA\OpenCatalogi\Contract\UsageCounterServiceInterface;
use OCA\OpenCatalogi\Service\MostViewedPublications;
use OCA\OpenCatalogi\Service\UsageCounterService;
use OCP\App\IAppManager;
use OCP\IAppConfig;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

#[CoversClass(MostViewedPublications::class)]
#[UsesClass(UsageCounterService::class)]
final class MostViewedPublicationsTest extends TestCase {

	private UsageCounterServiceInterface&MockObject $counters;
	private CatalogiServiceInterface&MockObject $catalogs;
	private ContainerInterface&MockObject $container;
	private LoggerInterface&MockObject $logger;

	/** @var array<string, array<string, mixed>> objects the caller may read, by uuid */
	private array $readable = [];

	/** @var list<array<string, mixed>> every search, in order */
	private array $searches = [];

	/** @var list<string> catalogue slugs whose counters were read */
	private array $countedCatalogs = [];

	protected function setUp(): void {
		$this->counters = $this->createMock(UsageCounterServiceInterface::class);
		$pure = new UsageCounterService(
			$this->createMock(IAppConfig::class),
			$this->createMock(IAppManager::class),
			$this->createMock(ContainerInterface::class),
			$this->createMock(LoggerInterface::class)
		);
		$this->counters->method('aggregateCatalog')->willReturnCallback(
			static fn (array $rows, int $top = 10): array => $pure->aggregateCatalog(rows: $rows, top: $top)
		);
		$this->counters->method('getCountersForCatalog')->willReturnCallback(
			function (string $catalog, ?string $from = null, ?string $to = null): array {
				$this->countedCatalogs[] = $catalog . ':' . $from . ':' . $to;
				return [
					'woo' => [
						['publication' => 'p-a', 'catalog' => 'woo', 'kind' => 'view', 'count' => 3],
						['publication' => 'p-b', 'catalog' => 'woo', 'kind' => 'view', 'count' => 9],
						['publication' => 'p-b', 'catalog' => 'woo', 'kind' => 'download', 'count' => 2],
						['publication' => 'p-hidden', 'catalog' => 'woo', 'kind' => 'view', 'count' => 50],
					],
					'raad' => [
						['publication' => 'p-a', 'catalog' => 'raad', 'kind' => 'view', 'count' => 4],
						['publication' => 'p-c', 'catalog' => 'raad', 'kind' => 'view', 'count' => 1],
					],
					'closed' => [['publication' => 'p-z', 'catalog' => 'closed', 'kind' => 'view', 'count' => 99]],
				][$catalog] ?? [];
			}
		);

		$this->catalogs = $this->createMock(CatalogiServiceInterface::class);
		$this->catalogs->method('getCatalogSlugs')->willReturn(['woo', 'raad', 'closed', 'gone']);
		$this->catalogs->method('getCatalogBySlug')->willReturnCallback(
			static fn (string $slug): ?array => $slug === 'gone' ? null : ['id' => 'cat-' . $slug, '@self' => ['register' => 'r-cat', 'schema' => 's-cat']]
		);

		$this->readable = [
			'cat-woo' => ['id' => 'cat-woo'],
			'cat-raad' => ['id' => 'cat-raad'],
			'p-a' => ['title' => 'Besluit A'],
			'p-b' => ['title' => 'Besluit B'],
			'p-c' => ['title' => '', '@self' => ['name' => 'Naam C']],
		];
		$objects = new class($this) {
			public function __construct(private MostViewedPublicationsTest $test) {
			}

			public function searchObjects(array $query, bool $_rbac = true, bool $_multitenancy = true): array {
				return $this->test->search(query: $query, rbac: $_rbac, multitenancy: $_multitenancy);
			}
		};
		$this->container = $this->createMock(ContainerInterface::class);
		$this->container->method('get')->with('OCA\OpenRegister\Service\ObjectService')->willReturn($objects);
		$this->logger = $this->createMock(LoggerInterface::class);
	}

	/**
	 * The object service's search, answering only readable objects.
	 *
	 * @param array<string, mixed> $query The query.
	 * @param bool $rbac Whether RBAC is on.
	 * @param bool $multitenancy Whether multitenancy is on.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function search(array $query, bool $rbac, bool $multitenancy): array {
		$this->searches[] = ['query' => $query, 'rbac' => $rbac, 'multitenancy' => $multitenancy];
		$uuid = $query['@self']['uuid'];
		if ($uuid === 'p-boom') {
			throw new RuntimeException('register down');
		}

		return isset($this->readable[$uuid]) === true ? [$this->readable[$uuid]] : [];
	}

	private function service(): MostViewedPublications {
		$config = $this->createMock(IAppConfig::class);
		$config->method('getValueString')->willReturnMap(
			[
				['opencatalogi', 'publication_register', '', false, 'r-pub'],
				['opencatalogi', 'publication_schema', '', false, 's-pub'],
			]
		);
		return new MostViewedPublications($this->counters, $this->catalogs, $this->container, $config, $this->logger);
	}

	public function testRanksTheReadablePublicationsOfEveryReadableCatalogueByViews(): void {
		$top = $this->service()->top(from: '2026-10-01', to: '2026-10-31', top: 10);

		$this->assertSame(
			[
				['id' => 'p-b', 'title' => 'Besluit B', 'catalog' => 'woo', 'views' => 9, 'downloads' => 2],
				['id' => 'p-a', 'title' => 'Besluit A', 'catalog' => 'woo', 'views' => 7, 'downloads' => 0],
				['id' => 'p-c', 'title' => 'Naam C', 'catalog' => 'raad', 'views' => 1, 'downloads' => 0],
			],
			$top
		);
		$this->assertSame(['woo:2026-10-01:2026-10-31', 'raad:2026-10-01:2026-10-31'], $this->countedCatalogs);
	}

	public function testEveryReadCheckRunsUnderTheCallersRbac(): void {
		$this->service()->top(from: null, to: null, top: 10);

		$this->assertNotSame([], $this->searches);
		foreach ($this->searches as $search) {
			$this->assertTrue($search['rbac']);
			$this->assertFalse($search['multitenancy']);
		}

		$where = array_map(static fn (array $search): array => $search['query']['@self'], $this->searches);
		$this->assertContains(['register' => 'r-pub', 'schema' => 's-pub', 'uuid' => 'p-b'], $where);
		$this->assertContains(['register' => 'r-cat', 'schema' => 's-cat', 'uuid' => 'cat-closed'], $where);
	}

	public function testStopsAtTheRequestedNumber(): void {
		$top = $this->service()->top(from: null, to: null, top: 1);

		$this->assertSame(['p-b'], array_column($top, 'id'));
	}

	public function testAFailedReadCheckLeavesThePublicationOutAndIsLogged(): void {
		$this->readable['p-boom'] = ['title' => 'never'];
		$this->counters = $this->createMock(UsageCounterServiceInterface::class);
		$this->counters->method('getCountersForCatalog')->willReturn([['publication' => 'p-boom', 'kind' => 'view', 'count' => 5]]);
		$this->counters->method('aggregateCatalog')->willReturn(['topViewed' => [['publication' => 'p-boom', 'views' => 5, 'downloads' => 0]]]);
		$this->logger->expects($this->once())->method('warning')->with('[MostViewedPublications] read check failed', ['error' => 'register down']);

		$this->assertSame([], $this->service()->top(from: null, to: null, top: 10));
	}

	public function testNoOpenRegisterGivesNothing(): void {
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willThrowException(new RuntimeException('not installed'));
		$this->container = $container;
		$this->counters->expects($this->never())->method('getCountersForCatalog');

		$this->assertSame([], $this->service()->top(from: null, to: null, top: 10));
	}
}
