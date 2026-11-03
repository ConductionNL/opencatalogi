<?php

/**
 * Golden test for the dataset refs of SchemaOrgService::buildCatalogNode().
 *
 * Every world is recorded once and replayed from the fixture: the dataset refs,
 * the search asked (with its RBAC flags) and the log lines written.
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

use OCA\OpenCatalogi\Service\DcatService;
use OCA\OpenCatalogi\Service\SchemaOrgService;
use OCP\IURLGenerator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Golden test for the catalog node's visible publication refs.
 *
 * @covers \OCA\OpenCatalogi\Service\SchemaOrgService
 * @uses   \OCA\OpenCatalogi\Service\CallerScope
 */
class SchemaOrgCatalogRefsGoldenTest extends TestCase {

	private const FIXTURE = __DIR__ . '/fixtures/schema-org-catalog-refs-golden.json';

	/**
	 * What the doubles were asked, in order.
	 *
	 * @var list<mixed>
	 */
	private array $log = [];

	/**
	 * The recorded worlds: the catalog and what the search answers.
	 *
	 * @return array<string, array{0: array<string, mixed>}>
	 */
	public static function worlds(): array {
		$rows = [
			['id' => 'p1', '@self' => ['uuid' => 'u1']],
			['id' => 'p2'],
			['@self' => ['uuid' => '']],
			['title' => 'no id'],
			['id' => 7, '@self' => []],
		];

		return [
			'one register, one schema' => [['catalog' => ['registers' => [1], 'schemas' => [11]], 'rows' => $rows]],
			'several registers and schemas' => [['catalog' => ['registers' => [1, '2'], 'schemas' => [11, 12]], 'rows' => $rows]],
			'lists as json text' => [['catalog' => ['registers' => '[1, "3", "x"]', 'schemas' => '["11", 2.7]'], 'rows' => $rows]],
			'invalid json text' => [['catalog' => ['registers' => 'nope', 'schemas' => [11]], 'rows' => $rows]],
			'json text that is a number' => [['catalog' => ['registers' => '5', 'schemas' => [11]], 'rows' => $rows]],
			'non-numeric entries only' => [['catalog' => ['registers' => ['a', null, true], 'schemas' => [11]], 'rows' => $rows]],
			'numeric strings with spaces and signs' => [['catalog' => ['registers' => [' 4', '-2', '1e1', '0x1A'], 'schemas' => ['11.0']], 'rows' => $rows]],
			'keyed lists' => [['catalog' => ['registers' => ['a' => 3, 'b' => 'z', 'c' => 4], 'schemas' => [5 => 11]], 'rows' => $rows]],
			'no registers' => [['catalog' => ['schemas' => [11]], 'rows' => $rows]],
			'no schemas' => [['catalog' => ['registers' => [1], 'schemas' => []], 'rows' => $rows]],
			'null lists' => [['catalog' => ['registers' => null, 'schemas' => null], 'rows' => $rows]],
			'search fails' => [['catalog' => ['registers' => [1], 'schemas' => [11]], 'rows' => 'throw']],
			'no results key' => [['catalog' => ['registers' => [1], 'schemas' => [11]], 'rows' => null]],
			'rows as entities' => [['catalog' => ['registers' => [1], 'schemas' => [11]], 'rows' => $rows, 'entities' => true]],
			'no OpenRegister' => [['catalog' => ['registers' => [1], 'schemas' => [11]], 'rows' => $rows, 'openregister' => false]],
		];

	}//end worlds()

	/**
	 * Replay a world and compare it to the recording.
	 *
	 * @param array<string, mixed> $world The world.
	 *
	 * @return void
	 */
	#[DataProvider('worlds')]
	public function testRefsMatchTheRecording(array $world): void {
		$this->log = [];
		$node = $this->service(world: $world)->buildCatalogNode($world['catalog'] + ['title' => 'T'], 'woo');
		$actual = json_decode((string)json_encode(['dataset' => $node['dataset'], 'log' => $this->log]), true);

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

	}//end testRefsMatchTheRecording()

	/**
	 * The service around the world's OpenRegister.
	 *
	 * @param array<string, mixed> $world The world.
	 *
	 * @return SchemaOrgService
	 */
	private function service(array $world): SchemaOrgService {
		$log = function (array $entry): void {
			$this->log[] = $entry;
		};
		$objects = new class($world, $log) {
			public function __construct(private array $world, private \Closure $log) {
			}

			public function searchObjectsPaginated(array $query = [], bool $_rbac = true, bool $_multitenancy = true, bool $deleted = false): array {
				($this->log)(['search' => ['query' => $query, '_rbac' => $_rbac, '_multitenancy' => $_multitenancy, 'deleted' => $deleted]]);
				if ($this->world['rows'] === 'throw') {
					throw new RuntimeException('search down');
				}

				if ($this->world['rows'] === null) {
					return ['total' => 0];
				}

				if (($this->world['entities'] ?? false) === true) {
					return ['results' => array_map(static fn (array $row): object => new class($row) implements \JsonSerializable {
						public function __construct(private array $row) {
						}

						public function jsonSerialize(): array {
							return $this->row;
						}
					}, $this->world['rows'])];
				}

				return ['results' => $this->world['rows']];
			}
		};

		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			static function (string $id) use ($objects, $world): object {
				if ($id === 'OCA\OpenRegister\Service\ObjectService' && ($world['openregister'] ?? true) === true) {
					return $objects;
				}

				throw new RuntimeException('no ' . $id);
			}
		);
		$dcat = $this->createMock(DcatService::class);
		$dcat->method('datasetIri')->willReturnCallback(static fn (string $slug, string $uuid): string => "iri:$slug/$uuid");
		$dcat->method('resolveDefaults')->willReturn(['publisherName' => '', 'publisherUri' => '', 'license' => '', 'contactPoint' => '', 'organisation' => null]);
		$url = $this->createMock(IURLGenerator::class);
		$url->method('getBaseUrl')->willReturn('https://host');
		$logger = $this->createMock(LoggerInterface::class);
		foreach (['debug', 'warning', 'error', 'info'] as $level) {
			$logger->method($level)->willReturnCallback(
				function (string $message, array $context = []) use ($level): void {
					$this->log[] = [$level => $message, 'context' => $context];
				}
			);
		}

		return new SchemaOrgService($container, $dcat, $url, $logger);

	}//end service()
}//end class
