<?php

/**
 * Golden test for SettingsService::enrichRegistersWithSchemas.
 *
 * Every world hands a set of registers and a schema table to the enrichment
 * and records the registers it returns and the schema ids it looked up.
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

use OCA\OpenCatalogi\Service\RegisterSchemaLinkService;
use OCA\OpenCatalogi\Service\SettingsService;
use OCA\OpenRegister\Db\SchemaMapper;
use OCP\App\IAppManager;
use OCP\IAppConfig;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Golden test for SettingsService::enrichRegistersWithSchemas.
 *
 * @covers \OCA\OpenCatalogi\Service\SettingsService
 */
class SettingsEnrichGoldenTest extends TestCase {

	private const FIXTURE = __DIR__ . '/fixtures/settings-enrich-golden.json';

	/**
	 * The schema ids looked up, per call.
	 *
	 * @var list<mixed>
	 */
	private array $lookups = [];

	/**
	 * The recorded worlds.
	 *
	 * Registers and table rows are given as arrays; `as` says how each one is
	 * handed over: `array`, `entity` (jsonSerialize) or `plain` (stdClass).
	 *
	 * @return array<string, array{0: array<string, mixed>}>
	 */
	public static function worlds(): array {
		$table = [
			3 => ['as' => 'entity', 'row' => ['id' => 3, 'title' => 'Drie']],
			4 => ['as' => 'plain', 'row' => ['id' => 4, 'title' => 'Vier']],
			5 => ['as' => 'array', 'row' => ['id' => 5, 'title' => 'Vijf']],
		];

		return [
			'no registers' => [['registers' => [], 'table' => $table]],
			'array registers' => [[
				'registers' => [
					['as' => 'array', 'row' => ['id' => 1, 'schemas' => [3, '4', 5, 99, 'slug', ['id' => 7], 3.0]]],
					['as' => 'array', 'row' => ['id' => 2, 'schemas' => []]],
					['as' => 'array', 'row' => ['id' => 6, 'schemas' => 'not-a-list']],
					['as' => 'array', 'row' => ['id' => 8]],
				],
				'table' => $table,
			],
			],
			'entity and plain registers' => [[
				'registers' => [
					['as' => 'entity', 'row' => ['id' => 1, 'schemas' => [5, 4]]],
					['as' => 'plain', 'row' => ['id' => 2, 'schemas' => ['3', 'x']]],
				],
				'table' => $table,
			],
			],
			'only non-numeric ids' => [['registers' => [['as' => 'array', 'row' => ['schemas' => ['a', 'b']]]], 'table' => $table]],
			'no schema found' => [['registers' => [['as' => 'array', 'row' => ['schemas' => [42, 43]]]], 'table' => []]],
			'duplicate ids' => [['registers' => [['as' => 'array', 'row' => ['schemas' => [5, '5', 3]]], ['as' => 'array', 'row' => ['schemas' => [3]]]], 'table' => $table]],
			'mapper missing' => [['registers' => [['as' => 'array', 'row' => ['schemas' => [3]]]], 'table' => $table, 'mapper' => 'null']],
			'openregister absent' => [['registers' => [['as' => 'entity', 'row' => ['schemas' => [3]]]], 'table' => $table, 'mapper' => 'absent']],
			'lookup fails' => [['registers' => [['as' => 'entity', 'row' => ['schemas' => [3]]], ['as' => 'array', 'row' => ['schemas' => [4]]]], 'table' => $table, 'mapper' => 'fails']],
		];

	}//end worlds()

	/**
	 * Enrich the world's registers and compare with the recording.
	 *
	 * @param array<string, mixed> $world The world.
	 *
	 * @return void
	 */
	#[DataProvider('worlds')]
	public function testEnrichmentMatchesTheRecording(array $world): void {
		$this->lookups = [];
		$registers = array_map(fn (array $spec) => self::handOver(spec: $spec), $world['registers']);

		$method = new \ReflectionMethod(SettingsService::class, 'enrichRegistersWithSchemas');
		$result = $method->invoke($this->service(world: $world), $registers);

		$actual = ['result' => self::describe(value: $result), 'lookups' => $this->lookups];

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

	}//end testEnrichmentMatchesTheRecording()

	/**
	 * Describe a value with the PHP type of every object in it.
	 *
	 * @param mixed $value The value.
	 *
	 * @return mixed The description.
	 */
	private static function describe(mixed $value): mixed {
		if (is_object($value) === true) {
			$kind = 'plain';
			if ($value instanceof \JsonSerializable) {
				$kind = 'entity';
			}

			return ['(object)' => $kind, 'data' => self::describe(value: ($kind === 'entity' ? $value->jsonSerialize() : (array)$value))];
		}

		if (is_array($value) === true) {
			$out = [];
			foreach ($value as $key => $item) {
				$out[] = [$key, self::describe(value: $item)];
			}

			return $out;
		}

		return [gettype($value) => $value];

	}//end describe()

	/**
	 * Hand a row over in the shape its spec names.
	 *
	 * @param array<string, mixed> $spec The spec: `as` and `row`.
	 *
	 * @return mixed The row as an array, an entity or a plain object.
	 */
	private static function handOver(array $spec): mixed {
		if ($spec['as'] === 'plain') {
			return (object)$spec['row'];
		}

		if ($spec['as'] === 'entity') {
			return new class($spec['row']) implements \JsonSerializable {
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
		}

		return $spec['row'];

	}//end handOver()

	/**
	 * Build the service around the world's schema table.
	 *
	 * @param array<string, mixed> $world The world.
	 *
	 * @return SettingsService
	 */
	private function service(array $world): SettingsService {
		$mapper = $this->createMock(SchemaMapper::class);
		$mapper->method('findMultipleOptimized')->willReturnCallback(
			function (array $ids) use ($world): array {
				$this->lookups[] = $ids;
				if (($world['mapper'] ?? '') === 'fails') {
					throw new \Exception('table gone');
				}

				$found = [];
				foreach ($ids as $id) {
					if (isset($world['table'][$id]) === true) {
						$found[$id] = self::handOver(spec: $world['table'][$id]);
					}
				}

				return $found;
			}
		);

		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturn(($world['mapper'] ?? '') === 'null' ? null : $mapper);

		$apps = $this->createMock(IAppManager::class);
		$apps->method('getInstalledApps')->willReturn(($world['mapper'] ?? '') === 'absent' ? ['files'] : ['openregister']);

		return new SettingsService(
			$this->createMock(IAppConfig::class),
			$container,
			$apps,
			$this->createMock(LoggerInterface::class),
			$this->createMock(RegisterSchemaLinkService::class)
		);

	}//end service()
}//end class
