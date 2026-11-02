<?php

/**
 * Golden test for the OpenCatalogi metrics families.
 *
 * Runs `OpenCatalogiMetricsProvider::metrics()` over scripted OpenRegister
 * doubles and compares every family (name, type, help, points in order) and
 * the order of the object searches it sent with what was recorded. A refactor
 * of the provider must leave both unchanged.
 *
 * Re-record (only when behaviour is meant to change) with
 * `GOLDEN_RECORD=1 vendor/bin/phpunit tests/Unit/Observability/OpenCatalogiMetricsGoldenTest.php`.
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
 * @spec openspec/specs/adopt-apphost/spec.md
 *
 * @covers \OCA\OpenCatalogi\Observability\OpenCatalogiMetricsProvider
 */

declare(strict_types=1);

namespace Unit\Observability;

use OCA\OpenCatalogi\Observability\OpenCatalogiMetricsProvider;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\NullLogger;

/**
 * A scripted OpenRegister object service that records every search.
 */
class GoldenMetricsObjectService {

	/** @var array<int, string> Every searched "register:schema" pair, in order. */
	public array $calls = [];

	/**
	 * @param array<string, array<int, array<string, mixed>>> $byPair Objects per "register:schema".
	 */
	public function __construct(
		private array $byPair,
	) {
	}

	/**
	 * @param array<string, mixed> $query The search query.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function searchObjects(array $query = [], bool $_rbac = true, bool $_multitenancy = true): array {
		$key = ($query['@self']['register'] ?? '') . ':' . ($query['@self']['schema'] ?? '');
		$this->calls[] = $key;
		return ($this->byPair[$key] ?? []);
	}
}

/**
 * A mapper double whose findAll() returns fixed rows.
 */
class GoldenMetricsMapper {

	/**
	 * @param array<int, array<string, mixed>> $rows The rows.
	 */
	public function __construct(
		private array $rows,
	) {
	}

	/**
	 * @return array<int, array<string, mixed>>
	 */
	public function findAll(): array {
		return $this->rows;
	}
}

/**
 * Golden test for OpenCatalogiMetricsProvider::metrics().
 */
class OpenCatalogiMetricsGoldenTest extends TestCase {

	private const FIXTURE = __DIR__ . '/../../fixtures/metrics-provider-golden.json';

	/**
	 * The OpenRegister contents each case reads.
	 *
	 * @return array<string, array{0: array<string, mixed>}>
	 */
	public static function cases(): array {
		return [
			'empty openregister' => [[
				'schemas' => [],
				'registers' => [],
				'objects' => [],
			]],
			'every family filled' => [[
				'schemas' => [
					['id' => 11, 'title' => 'Publication'],
					['id' => 12, 'title' => 'usageCounter'],
					['id' => 13, 'title' => 'Catalog'],
					['id' => 14, 'title' => 'Listing'],
					['id' => 15, 'title' => 'Directory'],
				],
				'registers' => [
					['id' => 1, 'schemas' => [11, 12, 13]],
					['id' => 2, 'schemas' => [14, 15, 11]],
				],
				'objects' => [
					'1:11' => [
						['status' => 'published', 'catalog' => 'woo'],
						['status' => 'published', 'catalog' => 'woo'],
						['status' => 'concept', 'catalog' => 'open'],
						['catalog' => 'woo'],
					],
					'2:11' => [
						['status' => 'published', 'catalog' => 'open'],
						['status' => 'concept', 'catalog' => 'open'],
					],
					'1:12' => [
						['kind' => 'view', 'catalog' => 'woo', 'count' => 5],
						['kind' => 'view', 'catalog' => 'open', 'count' => '3'],
						['kind' => 'view', 'catalog' => 'woo', 'count' => 4],
						['kind' => 'download', 'count' => 2],
						['kind' => 'share', 'catalog' => 'woo', 'count' => 9],
						['catalog' => 'woo', 'count' => 1],
					],
					'1:13' => [['title' => 'A'], ['title' => 'B'], ['title' => 'C']],
					'2:14' => [
						['status' => 'active'],
						['status' => 'inactive'],
						['status' => 'active'],
						[],
					],
					'2:15' => [['url' => 'x']],
				],
			]],
			'only usage downloads' => [[
				'schemas' => [['id' => 12, 'title' => 'UsageCounter']],
				'registers' => [['id' => 3, 'schemas' => [12]]],
				'objects' => [
					'3:12' => [['kind' => 'download', 'catalog' => 'c', 'count' => 7]],
				],
			]],
		];

	}//end cases()

	/**
	 * Collect the metrics for one case and compare them with the recording.
	 *
	 * @param array<string, mixed> $case The case input.
	 *
	 * @return void
	 */
	#[DataProvider('cases')]
	public function testMetricsMatchTheRecording(array $case): void {
		$name = $this->dataName();
		$actual = $this->collect(case: $case);

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

	}//end testMetricsMatchTheRecording()

	/**
	 * Run the provider for one case.
	 *
	 * @param array<string, mixed> $case The case input.
	 *
	 * @return array{families: array<int, array<string, mixed>>, searches: array<int, string>}
	 */
	private function collect(array $case): array {
		$objectService = new GoldenMetricsObjectService($case['objects']);
		$services = [
			'OCA\OpenRegister\Db\SchemaMapper' => new GoldenMetricsMapper($case['schemas']),
			'OCA\OpenRegister\Db\RegisterMapper' => new GoldenMetricsMapper($case['registers']),
			'OCA\OpenRegister\Service\ObjectService' => $objectService,
		];

		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			static function (string $id) use ($services) {
				if (isset($services[$id]) === true) {
					return $services[$id];
				}

				throw new \RuntimeException('unexpected container id: ' . $id);
			}
		);

		$families = [];
		foreach ((new OpenCatalogiMetricsProvider($container, new NullLogger()))->metrics() as $sample) {
			$families[] = [
				'class' => get_class($sample),
				'name' => $sample->name,
				'type' => $sample->type,
				'help' => $sample->help,
				'samples' => $sample->samples,
			];
		}

		return ['families' => $families, 'searches' => $objectService->calls];
	}//end collect()
}//end class
