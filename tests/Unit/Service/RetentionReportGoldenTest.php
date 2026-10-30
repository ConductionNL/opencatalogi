<?php

/**
 * Golden test for `RetentionService::buildReport()`.
 *
 * Runs buildReport() over a scripted OpenRegister and compares the rows, the
 * search it asked for and every log line with what was recorded: the config
 * and OpenRegister gaps, a failing search, the catalog and date filters
 * (including rows without a publication date), entity rows, and the last
 * decision of each decision log.
 *
 * Re-record (only when behaviour is meant to change) with
 * `GOLDEN_RECORD=1 vendor/bin/phpunit -c phpunit-unit.xml tests/Unit/Service/RetentionReportGoldenTest.php`.
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
 * @spec openspec/specs/publication-retention-lifecycle/spec.md#requirement-retention-report-export-ret-009
 *
 * @covers \OCA\OpenCatalogi\Service\RetentionService
 */

declare(strict_types=1);

namespace OCA\OpenCatalogi\Tests\Unit\Service;

use OCA\OpenCatalogi\Service\RetentionService;
use OCP\IAppConfig;
use OCP\IUserSession;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Golden test for RetentionService::buildReport().
 */
class RetentionReportGoldenTest extends TestCase {

	private const FIXTURE = __DIR__ . '/../../fixtures/retention-report-golden.json';

	/**
	 * The stored publications most cases report on.
	 *
	 * @return list<array<string, mixed>>
	 */
	private static function publications(): array {
		return [
			[
				'id' => 'p-1',
				'title' => 'Besluit',
				'catalog' => 'woo',
				'publicationDate' => '2026-01-15T10:00:00+00:00',
				'retentionCategory' => 'B',
				'retentionTermMonths' => 60,
				'retentionExpiresAt' => '2031-01-15',
				'status' => 'published',
				'retentionDecisionLog' => [
					['decision' => 'extend', 'by' => 'officer1', 'at' => '2026-02-01', 'note' => 'first'],
					['decision' => 'keep', 'by' => 'officer2', 'at' => '2026-03-01', 'note' => 'last'],
				],
			],
			['uuid' => 'p-2', 'catalog' => 'open', 'publicationDate' => '2026-03-31', 'retentionDecisionLog' => []],
			['id' => 'p-3', 'catalog' => 'woo', 'publicationDate' => '', 'title' => 'No date'],
			['id' => 'p-4', 'catalog' => 'woo', 'title' => 'Date missing', 'retentionDecisionLog' => 'not a log'],
			['id' => 'p-5', 'publicationDate' => '2025-12-31T23:59:59Z', 'retentionDecisionLog' => [['decision' => 'destroy']]],
			['entity' => ['id' => 'p-6', 'catalog' => 'woo', 'publicationDate' => '2026-02-10', 'status' => 'archived']],
			'scalar row',
		];
	}//end publications()

	/**
	 * The filters, config and store of each case.
	 *
	 * @return array<string, array{0: array<string, mixed>}>
	 */
	public static function cases(): array {
		return [
			'no register' => [['config' => ['publication_register' => '']]],
			'no schema' => [['config' => ['publication_schema' => '']]],
			'no object service' => [['containerFails' => true]],
			'search fails' => [['searchFails' => true]],
			'no results key' => [['answer' => ['total' => 0]]],
			'everything' => [[]],
			'empty filters' => [['filter' => ['', '', '']]],
			'catalog woo' => [['filter' => ['woo', null, null]]],
			'catalog unknown' => [['filter' => ['nope', null, null]]],
			'from' => [['filter' => [null, '2026-01-15', null]]],
			'to' => [['filter' => [null, null, '2026-02-10']]],
			'from and to' => [['filter' => [null, '2026-01-01', '2026-03-30']]],
			'catalog and dates' => [['filter' => ['woo', '2026-02-01', '2026-12-31']]],
		];

	}//end cases()

	/**
	 * Run one case and compare it with the recording.
	 *
	 * @param array<string, mixed> $case The case input.
	 *
	 * @return void
	 */
	#[DataProvider('cases')]
	public function testReportMatchesTheRecording(array $case): void {
		$name = $this->dataName();
		$actual = $this->report(case: $case);

		$recorded = [];
		if (is_file(self::FIXTURE) === true) {
			$recorded = json_decode((string)file_get_contents(self::FIXTURE), true);
		}

		if (getenv('GOLDEN_RECORD') === '1') {
			$recorded[$name] = $actual;
			ksort($recorded);
			file_put_contents(self::FIXTURE, json_encode($recorded, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
		}

		$this->assertArrayHasKey($name, $recorded, 'No recording for this case.');
		$this->assertSame(
			json_encode($recorded[$name], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES),
			json_encode($actual, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
		);

	}//end testReportMatchesTheRecording()

	/**
	 * Build the service over a scripted store and build the report.
	 *
	 * @param array<string, mixed> $case The case input.
	 *
	 * @return array<string, mixed> The rows, the search calls and the log.
	 */
	private function report(array $case): array {
		$calls = [];
		$values = array_merge(
			['publication_register' => 'pub-reg', 'publication_schema' => 'pub-sch'],
			($case['config'] ?? [])
		);
		$config = $this->createMock(IAppConfig::class);
		$config->method('getValueString')->willReturnCallback(
			static fn (string $app, string $key, string $default = ''): string => ($values[$key] ?? $default)
		);

		$rows = [];
		foreach (self::publications() as $row) {
			if (is_array($row) === true && isset($row['entity']) === true) {
				$data = $row['entity'];
				$row = new class($data) {
					/**
					 * @param array<string, mixed> $data The serialised row.
					 */
					public function __construct(private array $data) {
					}

					/**
					 * @return array<string, mixed>
					 */
					public function jsonSerialize(): array {
						return $this->data;
					}
				};
			}

			$rows[] = $row;
		}

		$answer = ($case['answer'] ?? ['results' => $rows]);
		$fails = ($case['searchFails'] ?? false);
		$store = new class($answer, $fails) {
			/**
			 * @var list<array<int, mixed>>
			 */
			public array $calls = [];

			/**
			 * @param array<string, mixed> $answer The search answer.
			 * @param bool $fails Whether the search throws.
			 */
			public function __construct(private array $answer, private bool $fails) {
			}

			/**
			 * @param array<string, mixed> $query The query.
			 * @param bool $_rbac Whether RBAC applies.
			 * @param bool $_multitenancy Whether multitenancy applies.
			 *
			 * @return array<string, mixed>
			 */
			public function searchObjectsPaginated(array $query = [], bool $_rbac = true, bool $_multitenancy = true): array {
				$this->calls[] = ['searchObjectsPaginated', $query, $_rbac, $_multitenancy];
				if ($this->fails === true) {
					throw new RuntimeException('search down');
				}

				return $this->answer;
			}
		};

		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			static function (string $id) use ($store, $case): object {
				if (($case['containerFails'] ?? false) === true) {
					throw new RuntimeException('no ' . $id);
				}

				return $store;
			}
		);

		$logger = $this->createMock(LoggerInterface::class);
		foreach (['info', 'warning', 'error', 'debug'] as $level) {
			$logger->method($level)->willReturnCallback(
				static function (string $message, array $context = []) use (&$calls, $level): void {
					$calls[] = [$level, $message, $context];
				}
			);
		}

		$service = new RetentionService($config, $container, $this->createMock(IUserSession::class), $logger);
		[$catalog, $from, $to] = ($case['filter'] ?? [null, null, null]);
		$report = $service->buildReport($catalog, $from, $to);

		return ['rows' => $report, 'search' => $store->calls, 'log' => $calls];

	}//end report()
}//end class
