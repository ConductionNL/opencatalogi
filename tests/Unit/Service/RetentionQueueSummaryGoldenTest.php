<?php

/**
 * Golden test for RetentionService::getQueueSummary.
 *
 * Every world hands the service a config and a page of publications and
 * records the summary, the search asked (with its window end as a day count
 * from now) and the warnings logged.
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

use OCA\OpenCatalogi\Service\RetentionService;
use OCP\IAppConfig;
use OCP\IUserSession;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Golden test for RetentionService::getQueueSummary.
 *
 * @covers \OCA\OpenCatalogi\Service\RetentionService
 * @uses \OCA\OpenCatalogi\Service\RetentionQueue
 * @uses \OCA\OpenCatalogi\Service\RetentionPolicyTable
 */
class RetentionQueueSummaryGoldenTest extends TestCase {

	private const FIXTURE = __DIR__ . '/fixtures/retention-queue-summary-golden.json';

	/**
	 * What the doubles were asked.
	 *
	 * @var array<string, list<mixed>>
	 */
	private array $log = [];

	/**
	 * The recorded worlds.
	 *
	 * @return array<string, array{0: array<string, mixed>}>
	 */
	public static function worlds(): array {
		$scope = ['publication_register' => '5', 'publication_schema' => '6'];
		$past = '2001-02-03T04:05:06+00:00';
		$future = '2999-01-01T00:00:00+00:00';

		return [
			'no register' => [['config' => ['publication_schema' => '6'], 'rows' => []]],
			'no schema' => [['config' => ['publication_register' => '5'], 'rows' => []]],
			'no object service' => [['config' => $scope, 'rows' => [], 'objectService' => false]],
			'search fails' => [['config' => $scope, 'rows' => [], 'searchFails' => true]],
			'no results key' => [['config' => $scope, 'rows' => null]],
			'every branch' => [[
				'config' => $scope,
				'rows' => [
					['status' => 'archived', 'retentionExpiresAt' => $past],
					['status' => 'archived'],
					['status' => 'published', 'retentionExpiresAt' => $past],
					['retentionExpiresAt' => $past, 'retentionAction' => 'review'],
					['retentionExpiresAt' => $past, 'retentionAction' => 'depublish'],
					['retentionExpiresAt' => $future],
					['retentionExpiresAt' => $future, 'retentionAction' => 'depublish'],
					['retentionExpiresAt' => null],
					['retentionExpiresAt' => ''],
					['retentionExpiresAt' => 'not a date'],
					['status' => 'Archived', 'retentionExpiresAt' => $future],
				],
			],
			],
			'entity rows' => [['config' => $scope, 'rows' => [['status' => 'archived'], ['retentionExpiresAt' => $past]], 'entities' => true]],
			'custom window' => [['config' => $scope + ['retention_warning_window_days' => '7'], 'rows' => []]],
			'zero window' => [['config' => $scope + ['retention_warning_window_days' => '0'], 'rows' => []]],
		];

	}//end worlds()

	/**
	 * Summarise the world's queue and compare with the recording.
	 *
	 * @param array<string, mixed> $world The world.
	 *
	 * @return void
	 */
	#[DataProvider('worlds')]
	public function testSummaryMatchesTheRecording(array $world): void {
		$this->log = ['searched' => [], 'warnings' => []];
		$summary = $this->service(world: $world)->getQueueSummary();

		$actual = json_decode((string)json_encode(['summary' => $summary, 'log' => $this->log]), true);

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

	}//end testSummaryMatchesTheRecording()

	/**
	 * Build the service around the world's config and search answer.
	 *
	 * @param array<string, mixed> $world The world.
	 *
	 * @return RetentionService
	 */
	private function service(array $world): RetentionService {
		$config = $this->createMock(IAppConfig::class);
		$config->method('getValueString')->willReturnCallback(
			static fn ($app, $key, $default = '') => ($world['config'][$key] ?? $default)
		);

		$record = function (array $search): void {
			$this->log['searched'][] = $search;
		};
		$objectService = new class($world, $record) {
			/**
			 * @param array<string, mixed> $world  The world.
			 * @param \Closure             $record Records a search.
			 */
			public function __construct(private array $world, private \Closure $record) {
			}

			/**
			 * Record the search and answer with the world's rows.
			 *
			 * @param array<string, mixed> $query         The query.
			 * @param boolean              $_rbac         RBAC flag.
			 * @param boolean              $_multitenancy Multitenancy flag.
			 *
			 * @return array<string, mixed>
			 */
			public function searchObjectsPaginated(array $query, bool $_rbac = true, bool $_multitenancy = true): array {
				$end = strtotime((string)$query['retentionExpiresAt']['$lte']);
				$query['retentionExpiresAt']['$lte'] = 'now+' . (int)round(($end - time()) / 86400) . 'd';
				($this->record)(['query' => $query, 'rbac' => $_rbac, 'multitenancy' => $_multitenancy]);
				if (($this->world['searchFails'] ?? false) === true) {
					throw new \RuntimeException('index down');
				}

				if ($this->world['rows'] === null) {
					return [];
				}

				$rows = $this->world['rows'];
				if (($this->world['entities'] ?? false) === true) {
					$rows = array_map(static fn (array $row): object => new class($row) implements \JsonSerializable {
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
					}, $rows);
				}

				return ['results' => $rows];
			}
		};

		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			static function (string $id) use ($world, $objectService) {
				if (($world['objectService'] ?? true) === false) {
					throw new \RuntimeException('no ' . $id);
				}

				return $objectService;
			}
		);

		$logger = $this->createMock(LoggerInterface::class);
		$logger->method('warning')->willReturnCallback(
			function (string $message): void {
				$this->log['warnings'][] = $message;
			}
		);

		return new RetentionService($config, $container, $this->createMock(IUserSession::class), $logger);

	}//end service()
}//end class
