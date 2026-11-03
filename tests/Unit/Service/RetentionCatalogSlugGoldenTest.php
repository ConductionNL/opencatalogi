<?php

/**
 * Golden test for the catalog a publication's retention defaults come from.
 *
 * @category Test
 * @package  OCA\OpenCatalogi\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/specs/publication-retention-lifecycle/spec.md#requirement-per-catalog-retention-defaults-per-woo-information-category-ret-004
 */

declare(strict_types=1);

namespace Unit\Service;

use OCA\OpenCatalogi\Service\RetentionService;
use OCP\IAppConfig;
use OCP\IUserSession;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * The slug of the catalog holding a publication's register and schema, in every
 * world the lookup can meet, matches the recording: the answer, the catalog search
 * with its flags, and the log lines.
 *
 * @covers \OCA\OpenCatalogi\Service\RetentionService
 * @uses \OCA\OpenCatalogi\Service\RetentionPolicyTable
 */
class RetentionCatalogSlugGoldenTest extends TestCase {

	private const FIXTURE = __DIR__ . '/fixtures/retention-catalog-slug-golden.json';

	/**
	 * Every world: the configuration, the store's answer and the publication's register and schema.
	 *
	 * @return array<string, array{0: array<string, mixed>}>
	 */
	public static function worlds(): array {
		$both = ['catalog_register' => '5', 'catalog_schema' => '6'];
		$open = ['slug' => 'open', 'registers' => [1, '2'], 'schemas' => ['3']];
		$other = ['slug' => 'other', 'registers' => [1], 'schemas' => [4]];
		return [
			'no OpenRegister' => [['config' => $both, 'store' => 'missing', 'pair' => ['1', '3']]],
			'no catalog register configured' => [['config' => ['catalog_schema' => '6'], 'store' => [$open], 'pair' => ['1', '3']]],
			'no catalog schema configured' => [['config' => ['catalog_register' => '5'], 'store' => [$open], 'pair' => ['1', '3']]],
			'search fails' => [['config' => $both, 'store' => 'throws', 'pair' => ['1', '3']]],
			'no catalogs' => [['config' => $both, 'store' => [], 'pair' => ['1', '3']]],
			'no results key' => [['config' => $both, 'store' => 'no-results', 'pair' => ['1', '3']]],
			'register held, schema not' => [['config' => $both, 'store' => [$other], 'pair' => ['1', '3']]],
			'schema held, register not' => [['config' => $both, 'store' => [$open], 'pair' => ['9', '3']]],
			'held, ids as text' => [['config' => $both, 'store' => [$other, $open], 'pair' => ['2', '3']]],
			'two catalogs hold it, the first wins' => [['config' => $both, 'store' => [$open, ['slug' => 'second'] + $open], 'pair' => ['1', '3']]],
			'held by an entity row' => [['config' => $both, 'store' => ['entity:open'], 'pair' => ['1', '3']]],
			'held, no slug' => [['config' => $both, 'store' => [['registers' => [1], 'schemas' => [3]]], 'pair' => ['1', '3']]],
			'scalar lists' => [['config' => $both, 'store' => [['slug' => 'scalar', 'registers' => '1', 'schemas' => 3]], 'pair' => ['1', '3']]],
			'other uuids do not match' => [['config' => $both, 'store' => [['slug' => 'zero', 'registers' => ['abc'], 'schemas' => ['def']]], 'pair' => ['uuid-r', 'uuid-s']]],
			'a zero-padded id is another id' => [['config' => $both, 'store' => [['slug' => 'padded', 'registers' => ['01'], 'schemas' => ['3']]], 'pair' => ['1', '3']]],
			'a zero-padded schema id is another id' => [['config' => $both, 'store' => [['slug' => 'padded', 'registers' => ['1'], 'schemas' => ['03']]], 'pair' => ['1', '3']]],
			'the same uuids match' => [['config' => $both, 'store' => [['slug' => 'by-uuid', 'registers' => ['uuid-r'], 'schemas' => ['uuid-s']]], 'pair' => ['uuid-r', 'uuid-s']]],
		];
	}//end worlds()

	/**
	 * The lookup in one world matches its recording.
	 *
	 * @param array<string, mixed> $world The world.
	 *
	 * @return void
	 */
	#[DataProvider('worlds')]
	public function testTheCatalogSlugMatchesTheRecording(array $world): void {
		$log = [];
		$config = $this->createMock(IAppConfig::class);
		$config->method('getValueString')->willReturnCallback(
			static fn (string $app, string $key, string $default = ''): string => (string)($world['config'][$key] ?? $default)
		);
		$store = new class($world['store'], $log) {
			/**
			 * @param mixed $answer What the search answers.
			 * @param array<int, mixed> $log The calls, by reference.
			 */
			public function __construct(
				private mixed $answer,
				private array &$log,
			) {
			}

			/**
			 * Record the search and answer it.
			 *
			 * @param array $query The query.
			 * @param bool $_rbac Whether RBAC applies.
			 * @param bool $_multitenancy Whether the organisation filter applies.
			 *
			 * @return array<string, mixed>
			 */
			public function searchObjectsPaginated(array $query = [], bool $_rbac = true, bool $_multitenancy = true): array {
				$this->log[] = ['search' => ['query' => $query, '_rbac' => $_rbac, '_multitenancy' => $_multitenancy]];
				if ($this->answer === 'throws') {
					throw new \RuntimeException('store down');
				}

				if ($this->answer === 'no-results') {
					return ['total' => 0];
				}

				return ['results' => array_map(static fn (mixed $row): mixed => self::row(row: $row), (array)$this->answer)];
			}

			/**
			 * A stored row: an array, or an entity whose jsonSerialize() holds the catalog.
			 *
			 * @param mixed $row The row as the world names it.
			 *
			 * @return mixed
			 */
			private static function row(mixed $row): mixed {
				if ($row !== 'entity:open') {
					return $row;
				}

				return new class implements \JsonSerializable {
					/**
					 * The catalog.
					 *
					 * @return array<string, mixed>
					 */
					public function jsonSerialize(): array {
						return ['slug' => 'open', 'registers' => [1], 'schemas' => [3]];
					}
				};
			}
		};
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			static function (string $id) use ($world, $store): object {
				if ($world['store'] === 'missing') {
					throw new \RuntimeException('not installed: ' . $id);
				}

				return $store;
			}
		);
		$logger = $this->createMock(LoggerInterface::class);
		$logger->method('warning')->willReturnCallback(
			static function (string $message) use (&$log): void {
				$log[] = ['warning' => $message];
			}
		);

		$service = new RetentionService($config, $container, $this->createMock(IUserSession::class), $logger);
		$method = new \ReflectionMethod($service, 'resolveCatalogSlug');
		$actual = ['slug' => $method->invoke($service, $world['pair'][0], $world['pair'][1]), 'log' => $log];

		$recorded = [];
		if (file_exists(self::FIXTURE) === true) {
			$recorded = json_decode((string)file_get_contents(self::FIXTURE), true);
		}

		$name = (string)$this->dataName();
		if (getenv('GOLDEN_RECORD') === '1') {
			$recorded[$name] = $actual;
			file_put_contents(self::FIXTURE, json_encode($recorded, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
		}

		$this->assertArrayHasKey($name, $recorded, 'No recording for ' . $name);
		$this->assertSame($recorded[$name], $actual);
	}//end testTheCatalogSlugMatchesTheRecording()
}//end class
