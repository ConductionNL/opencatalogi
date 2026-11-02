<?php

/**
 * Golden test for the schema and register lookups behind the OpenCatalogi metrics.
 *
 * Runs `OpenCatalogiMetricsProvider::metrics()` over OpenRegister doubles with
 * awkward shapes: schemas without an id or title, entity and object rows,
 * registers without an id, with a non-list schema field, with nested schema
 * arrays and non-numeric schema ids, a search that throws, a search that
 * answers a non-list, mappers that throw, and a missing ObjectService. It
 * compares the families, the searches sent and the warnings logged with what
 * was recorded.
 *
 * Re-record (only when behaviour is meant to change) with
 * `GOLDEN_RECORD=1 vendor/bin/phpunit tests/Unit/Observability/OpenCatalogiMetricsLookupGoldenTest.php`.
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
 * @spec openspec/specs/opencatalogi-adopt-or-abstractions/spec.md
 *
 * @covers \OCA\OpenCatalogi\Observability\OpenCatalogiMetricsProvider
 */

declare(strict_types=1);

namespace Unit\Observability;

use OCA\OpenCatalogi\Observability\OpenCatalogiMetricsProvider;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\AbstractLogger;

/**
 * An object service double: answers per "register:schema", throws or answers a non-list on request.
 */
class LookupMetricsObjectService {

	/** @var array<int, array<string, mixed>> Every query sent, in order. */
	public array $queries = [];

	/**
	 * @param array<string, mixed> $byPair Answers per "register:schema"; 'throw' throws.
	 */
	public function __construct(
		private array $byPair,
	) {
	}

	/**
	 * @param array<string, mixed> $query The search query.
	 *
	 * @return mixed
	 */
	public function searchObjects(array $query = [], bool $_rbac = true, bool $_multitenancy = true): mixed {
		$this->queries[] = ['query' => $query, '_rbac' => $_rbac, '_multitenancy' => $_multitenancy];
		$answer = ($this->byPair[($query['@self']['register'] ?? '') . ':' . ($query['@self']['schema'] ?? '')] ?? []);
		if ($answer === 'throw') {
			throw new \RuntimeException('search down');
		}

		return $answer;
	}
}

/**
 * A mapper double whose findAll() returns fixed rows, or throws when given 'throw'.
 */
class LookupMetricsMapper {

	/**
	 * @param array<int, mixed>|string $rows The rows, or 'throw'.
	 */
	public function __construct(
		private array|string $rows,
	) {
	}

	/**
	 * @return array<int, mixed>
	 */
	public function findAll(): array {
		if ($this->rows === 'throw') {
			throw new \RuntimeException('mapper down');
		}

		return $this->rows;
	}
}

/**
 * A logger that keeps every line.
 */
class LookupMetricsLogger extends AbstractLogger {

	/** @var array<int, array<string, mixed>> The lines. */
	public array $lines = [];

	/**
	 * @param mixed              $level   The level.
	 * @param string|\Stringable $message The message.
	 * @param array<mixed>       $context The context.
	 *
	 * @return void
	 */
	public function log($level, string|\Stringable $message, array $context = []): void {
		$this->lines[] = ['level' => $level, 'message' => (string)$message, 'context' => $context];
	}
}

/**
 * Golden test for the OpenCatalogiMetricsProvider lookups.
 */
class OpenCatalogiMetricsLookupGoldenTest extends TestCase {

	private const FIXTURE = __DIR__ . '/../../fixtures/metrics-lookup-golden.json';

	/**
	 * The OpenRegister contents each case reads.
	 *
	 * @return array<string, array{0: array<string, mixed>}>
	 */
	public static function cases(): array {
		$entity = new class {
			/**
			 * @return array<string, mixed>
			 */
			public function jsonSerialize(): array {
				return ['id' => 21, 'title' => 'Listing entity'];
			}
		};
		$badEntity = new class {
			public string $id = '22';
			public string $title = 'listing plain';

			/**
			 * @return string
			 */
			public function jsonSerialize(): string {
				return 'not an array';
			}
		};

		$registerEntity = new class {
			/**
			 * @return array<string, mixed>
			 */
			public function jsonSerialize(): array {
				return ['id' => 6, 'schemas' => [21]];
			}
		};
		$publicationEntity = new class {
			/**
			 * @return array<string, mixed>
			 */
			public function jsonSerialize(): array {
				return ['status' => 'entity', 'catalog' => 'e'];
			}
		};

		return [
			'awkward shapes' => [[
				'schemas' => [
					['id' => 11, 'title' => 'PUBLICATIONS'],
					['title' => 'Publication without id'],
					['id' => 12, 'title' => ''],
					['id' => 13],
					['id' => '14', 'title' => 'sub-publication'],
					$entity,
					$badEntity,
					(object)['id' => 23, 'title' => 'usageCounter'],
					'not a schema',
					['id' => 0, 'title' => 'catalog zero'],
				],
				'registers' => [
					['schemas' => [11]],
					['id' => 1, 'schemas' => 'not a list'],
					['id' => 2, 'schemas' => [11, ['id' => 14], ['id' => 'x'], ['name' => 'no id'], '21', 'abc', 2.0, null]],
					['id' => '3', 'schemas' => [['id' => '22'], 23, 11, 0]],
					['id' => 4],
					(object)['id' => 5, 'schemas' => [14]],
					$registerEntity,
					['id' => 7, 'schemas' => 11],
				],
				'objects' => [
					'2:11' => [['status' => 'published', 'catalog' => 'a'], 'x', $publicationEntity],
					'7:11' => [['status' => 'not listed']],
					'6:21' => [['status' => 'from entity register']],
					'3:11' => 'throw',
					'2:14' => 'not a list',
					'5:14' => ['k' => ['status' => 'concept']],
					'2:21' => [['status' => 'active']],
					'3:22' => [['status' => 'inactive']],
					'3:23' => [['kind' => 'view', 'catalog' => 'a', 'count' => 2]],
					'3:0' => [['title' => 'zero']],
				],
			]],
			'schema mapper fails' => [[
				'schemas' => 'throw',
				'registers' => [['id' => 1, 'schemas' => [11]]],
				'objects' => ['1:11' => [['status' => 'x']]],
			]],
			'register mapper fails' => [[
				'schemas' => [['id' => 11, 'title' => 'Publication']],
				'registers' => 'throw',
				'objects' => ['1:11' => [['status' => 'x']]],
			]],
			'no object service' => [[
				'schemas' => [['id' => 11, 'title' => 'Publication']],
				'registers' => [['id' => 1, 'schemas' => [11]]],
				'objects' => null,
			]],
			'no mappers' => [[
				'schemas' => null,
				'registers' => null,
				'objects' => ['1:11' => [['status' => 'x']]],
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
	public function testLookupsMatchTheRecording(array $case): void {
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

	}//end testLookupsMatchTheRecording()

	/**
	 * Run the provider for one case.
	 *
	 * @param array<string, mixed> $case The case input.
	 *
	 * @return array<string, mixed>
	 */
	private function collect(array $case): array {
		$services = [];
		if ($case['schemas'] !== null) {
			$services['OCA\OpenRegister\Db\SchemaMapper'] = new LookupMetricsMapper($case['schemas']);
		}

		if ($case['registers'] !== null) {
			$services['OCA\OpenRegister\Db\RegisterMapper'] = new LookupMetricsMapper($case['registers']);
		}

		$objectService = null;
		if ($case['objects'] !== null) {
			$objectService = new LookupMetricsObjectService($case['objects']);
			$services['OCA\OpenRegister\Service\ObjectService'] = $objectService;
		}

		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			static function (string $id) use ($services) {
				if (isset($services[$id]) === true) {
					return $services[$id];
				}

				throw new \RuntimeException('missing ' . $id);
			}
		);

		$logger = new LookupMetricsLogger();
		$families = [];
		foreach ((new OpenCatalogiMetricsProvider($container, $logger))->metrics() as $sample) {
			$families[] = ['name' => $sample->name, 'samples' => $sample->samples];
		}

		return [
			'families' => $families,
			'searches' => ($objectService?->queries ?? []),
			'log' => $logger->lines,
		];
	}//end collect()
}//end class
