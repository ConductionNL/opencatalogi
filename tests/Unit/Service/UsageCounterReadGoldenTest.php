<?php

/**
 * Golden test for the three counter reads of `UsageCounterService`.
 *
 * Calls getCountersForPublication(), getCountersForCatalog() and
 * getCountersForInstance() over a scripted OpenRegister and pins the search
 * each one asks for (query, RBAC and multitenancy flags) and the rows it
 * answers: normalised, undated rows dropped, the date window applied, a
 * non-array search answer read as no rows, and nothing searched when
 * OpenRegister, the register or the schema is missing.
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
 * @spec openspec/specs/publication-usage-analytics/spec.md
 *
 * @covers \OCA\OpenCatalogi\Service\UsageCounterService
 */

declare(strict_types=1);

namespace Unit\Service;

use OCA\OpenCatalogi\Service\UsageCounterService;
use OCA\OpenRegister\Service\ObjectService;
use OCP\App\IAppManager;
use OCP\IAppConfig;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Golden test for the UsageCounterService counter reads.
 */
class UsageCounterReadGoldenTest extends TestCase {

	/**
	 * Each read with the filter its search adds.
	 *
	 * @return array<string, array{0: string, 1: array<string, string>}>
	 */
	public static function reads(): array {
		return [
			'publication' => ['publication', ['publication' => 'pub-1']],
			'catalog' => ['catalog', ['catalog' => 'woo']],
			'instance' => ['instance', []],
		];

	}//end reads()

	/**
	 * Every read asks one unscoped search on the counter schema and filters its rows by date.
	 *
	 * @param string $read Which read.
	 * @param array<string, string> $filter The filter it adds to the search.
	 *
	 * @return void
	 */
	#[DataProvider('reads')]
	public function testReadSearchesAndFilters(string $read, array $filter): void {
		$entity = new class {
			/**
			 * The counter as an entity.
			 *
			 * @return array<string, mixed>
			 */
			public function jsonSerialize(): array {
				return ['date' => '2026-05-10', 'kind' => 'download', 'count' => 2];
			}
		};
		$rows = [
			['date' => '2026-04-30', 'kind' => 'view', 'count' => 1],
			['date' => '2026-05-01', 'kind' => 'view', 'count' => 3],
			['kind' => 'view', 'count' => 9],
			['date' => '', 'kind' => 'view', 'count' => 9],
			$entity,
			['date' => '2026-05-31', 'kind' => 'view', 'count' => 4],
			['date' => '2026-06-01', 'kind' => 'view', 'count' => 5],
			'not a counter',
		];
		$calls = [];
		$service = $this->service('reg', 'sch', $this->objectService($rows, $calls));

		$this->assertSame(
			[
				['date' => '2026-05-01', 'kind' => 'view', 'count' => 3],
				['date' => '2026-05-10', 'kind' => 'download', 'count' => 2],
				['date' => '2026-05-31', 'kind' => 'view', 'count' => 4],
			],
			$this->read($service, $read, '2026-05-01', '2026-05-31')
		);
		$this->assertCount(5, $this->read($service, $read, null, null));
		$this->assertSame(
			[['date' => '2026-06-01', 'kind' => 'view', 'count' => 5]],
			$this->read($service, $read, '2026-06-01', null)
		);
		$this->assertSame(
			[['date' => '2026-04-30', 'kind' => 'view', 'count' => 1]],
			$this->read($service, $read, null, '2026-04-30')
		);

		$query = ['@self' => ['register' => 'reg', 'schema' => 'sch']] + $filter;
		$this->assertSame(array_fill(0, 4, ['query' => $query, '_rbac' => false, '_multitenancy' => false]), $calls);

	}//end testReadSearchesAndFilters()

	/**
	 * A search that answers something other than a list reads as no rows.
	 *
	 * @param string $read Which read.
	 * @param array<string, string> $filter The filter it adds to the search.
	 *
	 * @return void
	 */
	#[DataProvider('reads')]
	public function testNonListAnswerIsNoRows(string $read, array $filter): void {
		$calls = [];
		$service = $this->service('reg', 'sch', $this->objectService(5, $calls));
		$this->assertSame([], $this->read($service, $read, null, null));
		$this->assertCount(1, $calls);

	}//end testNonListAnswerIsNoRows()

	/**
	 * Without OpenRegister, a register or a schema nothing is searched.
	 *
	 * @param string $read Which read.
	 * @param array<string, string> $filter The filter it adds to the search.
	 *
	 * @return void
	 */
	#[DataProvider('reads')]
	public function testUnconfiguredReadsNothing(string $read, array $filter): void {
		$calls = [];
		$objects = $this->objectService([['date' => '2026-05-01']], $calls);
		$this->assertSame([], $this->read($this->service('', 'sch', $objects), $read, null, null));
		$this->assertSame([], $this->read($this->service('reg', '', $objects), $read, null, null));
		$this->assertSame([], $this->read($this->service('reg', 'sch', null), $read, null, null));
		$this->assertSame([], $calls);

	}//end testUnconfiguredReadsNothing()

	/**
	 * Call one read.
	 *
	 * @param UsageCounterService $service The service.
	 * @param string $read Which read.
	 * @param string|null $from Window start.
	 * @param string|null $to Window end.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function read(UsageCounterService $service, string $read, ?string $from, ?string $to): array {
		return match ($read) {
			'publication' => $service->getCountersForPublication('pub-1', $from, $to),
			'catalog' => $service->getCountersForCatalog('woo', $from, $to),
			default => $service->getCountersForInstance($from, $to),
		};

	}//end read()

	/**
	 * A scripted ObjectService that records every search.
	 *
	 * @param array<int, mixed>|int $rows The search answer.
	 * @param array<int, mixed> $calls The searches, collected.
	 *
	 * @return ObjectService
	 */
	private function objectService(array|int $rows, array &$calls): ObjectService {
		$objects = $this->createMock(ObjectService::class);
		$objects->method('searchObjects')->willReturnCallback(
			function (...$args) use ($rows, &$calls) {
				$named = [];
				foreach ((new \ReflectionMethod(ObjectService::class, 'searchObjects'))->getParameters() as $i => $param) {
					$named[$param->getName()] = ($args[$param->getName()] ?? $args[$i] ?? null);
				}

				$calls[] = ['query' => $named['query'], '_rbac' => $named['_rbac'], '_multitenancy' => $named['_multitenancy']];
				return $rows;
			}
		);
		return $objects;
	}//end objectService()

	/**
	 * A UsageCounterService over the given configuration.
	 *
	 * @param string $register The configured register, or '' for none.
	 * @param string $schema The configured schema, or '' for none.
	 * @param ObjectService|null $objects The ObjectService, or null when OpenRegister is missing.
	 *
	 * @return UsageCounterService
	 */
	private function service(string $register, string $schema, ?ObjectService $objects): UsageCounterService {
		$config = $this->createMock(IAppConfig::class);
		$config->method('getValueString')->willReturnCallback(
			static fn (string $app, string $key, string $default = ''): string => match ($key) {
				'usageCounter_register' => $register,
				'usageCounter_schema' => $schema,
				default => $default,
			}
		);
		$appManager = $this->createMock(IAppManager::class);
		$appManager->method('getInstalledApps')->willReturn($objects === null ? [] : ['openregister']);
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturn($objects);

		return new UsageCounterService($config, $appManager, $container, $this->createMock(LoggerInterface::class));
	}//end service()
}//end class
