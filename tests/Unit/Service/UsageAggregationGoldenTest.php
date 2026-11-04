<?php

/**
 * Golden test for the usage aggregation and crawler check of `UsageCounterService`.
 *
 * Runs aggregateSeries(), aggregateCatalog(), aggregateInstanceSeries() and
 * isCrawler() over inputs that reach every branch: entity and array rows,
 * undated rows, unknown kinds, negative and non-numeric counts, dates out of
 * order, top-N limits, valid and invalid day windows, and the shipped and
 * configured crawler lists. Each answer is compared, key order included, with
 * what was recorded.
 *
 * Re-record (only when behaviour is meant to change) with
 * `GOLDEN_RECORD=1 vendor/bin/phpunit tests/Unit/Service/UsageAggregationGoldenTest.php`.
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
 * Golden test for UsageCounterService aggregation.
 */
class UsageAggregationGoldenTest extends TestCase {

	private const FIXTURE = __DIR__ . '/../../fixtures/usage/aggregation-golden.json';

	/**
	 * The counter row sets each aggregation case reads.
	 *
	 * @return array<string, array{0: array<int, mixed>}>
	 */
	public static function rowSets(): array {
		return [
			'none' => [[]],
			'one view' => [[['date' => '2026-05-01', 'kind' => 'view', 'count' => 3, 'publication' => 'p1']]],
			'mixed' => [[
				['date' => '2026-05-03', 'kind' => 'view', 'count' => 2, 'publication' => 'p2'],
				['date' => '2026-05-01', 'kind' => 'download', 'count' => 5, 'publication' => 'p1'],
				['date' => '2026-05-03', 'kind' => 'download', 'count' => '4', 'publication' => 'p1'],
				['date' => '2026-05-02', 'kind' => 'view', 'count' => 7, 'publication' => 'p3'],
				['date' => '2026-05-01', 'kind' => 'view', 'count' => 1, 'publication' => 'p2'],
				['date' => '2026-04-30', 'kind' => 'share', 'count' => 9, 'publication' => 'p4'],
				['date' => '', 'kind' => 'view', 'count' => 9, 'publication' => 'p5'],
				['kind' => 'view', 'count' => 9],
				['date' => '2026-05-04', 'kind' => 'view', 'count' => -3, 'publication' => ''],
				['date' => '2026-05-04', 'kind' => 'download', 'count' => 'many', 'publication' => 'p2'],
				['date' => '2026-05-05', 'count' => 6, 'publication' => 'p6'],
				'not a counter',
				new class {
					/**
					 * The counter as an entity.
					 *
					 * @return array<string, mixed>
					 */
					public function jsonSerialize(): array {
						return ['date' => '2026-04-29', 'kind' => 'view', 'count' => 8, 'publication' => 'p3'];
					}
				},
				(object)['date' => '2026-05-06', 'kind' => 'download', 'count' => 2, 'publication' => 'p7'],
			]],
			'ties' => [[
				['date' => '2026-05-01', 'kind' => 'view', 'count' => 2, 'publication' => 'b'],
				['date' => '2026-05-01', 'kind' => 'view', 'count' => 2, 'publication' => 'a'],
				['date' => '2026-05-01', 'kind' => 'download', 'count' => 1, 'publication' => 'c'],
				['date' => '2026-05-01', 'kind' => 'download', 'count' => 1, 'publication' => 'a'],
			]],
			'only unknown kinds' => [[
				['date' => '2026-05-02', 'kind' => 'like', 'count' => 3, 'publication' => 'p1'],
				['date' => '2026-05-01', 'kind' => 'VIEW', 'count' => 3, 'publication' => 'p2'],
			]],
		];

	}//end rowSets()

	/**
	 * The windows each instance series case asks for, both given as valid days or not.
	 *
	 * @return array<string, array{0: string|null, 1: string}>
	 */
	public static function windows(): array {
		return [
			'inside' => ['2026-05-01', '2026-05-03'],
			'single day' => ['2026-05-03', '2026-05-03'],
			'before all' => ['2026-01-01', '2026-01-31'],
			'from missing' => [null, '2026-05-30'],
			'from not a day' => ['yesterday', '2026-05-30'],
			'from overflows' => ['2026-02-30', '2026-03-31'],
			'from short form' => ['2026-5-1', '2026-05-30'],
			'to overflows' => ['2026-05-01', '2026-02-30'],
			'to not a day' => ['2026-05-01', 'later'],
		];

	}//end windows()

	/**
	 * The user agents each crawler case checks, under each crawler configuration.
	 *
	 * @return array<string, array{0: string|null, 1: string}>
	 */
	public static function agents(): array {
		$agents = [
			'null' => null,
			'empty' => '',
			'spaces' => '   ',
			'browser' => 'Mozilla/5.0 (X11; Linux x86_64) Firefox/120.0',
			'googlebot' => 'Mozilla/5.0 (compatible; Googlebot/2.1)',
			'curl' => 'curl/8.1',
			'upper headless' => 'HeadlessChrome/119',
			'configured' => 'Archiver-Spider 1.0',
			'configured padded' => 'my-MONITOR agent',
		];
		$configs = ['shipped' => '', 'blank' => '  ', 'extra' => ' Spider, ,MONITOR ,bot'];
		$cases = [];
		foreach ($configs as $configName => $config) {
			foreach ($agents as $agentName => $agent) {
				$cases[$configName . ', ' . $agentName] = [$agent, $config];
			}
		}

		return $cases;
	}//end agents()

	/**
	 * aggregateSeries() and aggregateCatalog() match the recording.
	 *
	 * @param array<int, mixed> $rows The counter rows.
	 *
	 * @return void
	 */
	#[DataProvider('rowSets')]
	public function testAggregationMatchesTheRecording(array $rows): void {
		$service = $this->service(objects: null, crawlers: '');
		$this->compare(
			name: 'aggregate: ' . $this->dataName(),
			actual: [
				'series' => $service->aggregateSeries($rows),
				'catalog' => $service->aggregateCatalog($rows),
				'catalog top 1' => $service->aggregateCatalog($rows, 1),
				'catalog top 0' => $service->aggregateCatalog($rows, 0),
			]
		);

	}//end testAggregationMatchesTheRecording()

	/**
	 * aggregateInstanceSeries() matches the recording for each window.
	 *
	 * @param string|null $from The window start.
	 * @param string $to The window end.
	 *
	 * @return void
	 */
	#[DataProvider('windows')]
	public function testInstanceSeriesMatchesTheRecording(?string $from, string $to): void {
		$rows = self::rowSets()['mixed'][0];
		$service = $this->service(objects: $this->objectService(rows: $rows), crawlers: '');
		$result = $service->aggregateInstanceSeries($from, $to);

		$today = (new \DateTimeImmutable('today', new \DateTimeZone('UTC')))->format('Y-m-d');
		if ($result['to'] === $today) {
			$result['to'] = 'TODAY';
			if ($result['from'] === (new \DateTimeImmutable($today))->modify('-29 days')->format('Y-m-d')) {
				$result['from'] = 'TODAY-29';
			}
		}

		$this->compare(name: 'instance: ' . $this->dataName(), actual: $result);

	}//end testInstanceSeriesMatchesTheRecording()

	/**
	 * isCrawler() matches the recording.
	 *
	 * @param string|null $agent The user agent.
	 * @param string $crawlers The configured crawler list.
	 *
	 * @return void
	 */
	#[DataProvider('agents')]
	public function testCrawlerMatchesTheRecording(?string $agent, string $crawlers): void {
		$this->compare(
			name: 'crawler: ' . $this->dataName(),
			actual: $this->service(objects: null, crawlers: $crawlers)->isCrawler($agent)
		);

	}//end testCrawlerMatchesTheRecording()

	/**
	 * Compare one result with its recording, recording it under GOLDEN_RECORD=1.
	 *
	 * @param string $name The recording key.
	 * @param mixed $actual The result.
	 *
	 * @return void
	 */
	private function compare(string $name, mixed $actual): void {
		$recorded = [];
		if (is_file(self::FIXTURE) === true) {
			$recorded = json_decode((string)file_get_contents(self::FIXTURE), true);
		}

		if (getenv('GOLDEN_RECORD') === '1') {
			$recorded[$name] = $actual;
			ksort($recorded);
			file_put_contents(self::FIXTURE, json_encode($recorded, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n");
		}

		$this->assertArrayHasKey($name, $recorded, 'No recording for this case.');
		$this->assertSame(
			json_encode($recorded[$name], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES),
			json_encode($actual, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
		);

	}//end compare()

	/**
	 * A scripted ObjectService whose every search answers the given rows.
	 *
	 * @param array<int, mixed> $rows The search answer.
	 *
	 * @return ObjectService
	 */
	private function objectService(array $rows): ObjectService {
		$objects = $this->createMock(ObjectService::class);
		$objects->method('searchObjects')->willReturn($rows);
		return $objects;
	}//end objectService()

	/**
	 * A UsageCounterService with a counter register and schema and the given crawler list.
	 *
	 * @param ObjectService|null $objects The ObjectService, or null when OpenRegister is missing.
	 * @param string $crawlers The configured crawler list.
	 *
	 * @return UsageCounterService
	 */
	private function service(?ObjectService $objects, string $crawlers): UsageCounterService {
		$config = $this->createMock(IAppConfig::class);
		$config->method('getValueString')->willReturnCallback(
			static fn (string $app, string $key, string $default = ''): string => match ($key) {
				'usageCounter_register' => 'reg',
				'usageCounter_schema' => 'sch',
				'usage_counter_crawlers' => $crawlers,
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
