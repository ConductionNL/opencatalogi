<?php

/**
 * Golden test for RenameDutchPublicationColumns::run().
 *
 * Every case runs the step over a recording database and compares every query,
 * statement, log line and output line, in order, to the recording in
 * fixtures/rename-dutch-publication-columns-golden.json.
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

namespace Unit\Repair;

use OCA\OpenCatalogi\Repair\RenameDutchPublicationColumns;
use OCP\DB\Exception;
use OCP\DB\IPreparedStatement;
use OCP\DB\IResult;
use OCP\IDBConnection;
use OCP\Migration\IOutput;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Golden test for moving the Dutch publication columns.
 *
 * @covers \OCA\OpenCatalogi\Repair\RenameDutchPublicationColumns
 */
class RenameDutchPublicationColumnsGoldenTest extends TestCase {

	private const FIXTURE = __DIR__ . '/fixtures/rename-dutch-publication-columns-golden.json';

	/**
	 * Everything asked and said, in order.
	 *
	 * @var array<int, mixed>
	 */
	private array $calls = [];

	/**
	 * The recorded worlds: schema ids, the tables information_schema lists, the
	 * columns per table, and what fails.
	 *
	 * @return array<string, array{0: array<string, mixed>}>
	 */
	public static function worlds(): array {
		$tables = [
			'oc_openregister_table_1_4',
			'oc_openregister_table_1_14',
			'oc_openregister_table_2_9',
			'oc_openregister_table_1_4',
			'oc_other_table_4',
			'',
			'oc_openregister_table_3_8',
		];
		$columns = [
			'oc_openregister_table_1_4' => ['id', 'publicatiedatum', 'depublicatiedatum', 'publication_date', '', 'besluit'],
			'oc_openregister_table_2_9' => ['inventarislijst', 'inventory_list', 'besluit', 'decision_letter'],
		];

		return [
			'schema ids unreadable' => [['fail' => ['ids']]],
			'no schema ids' => [['ids' => []]],
			'tables unlistable' => [['ids' => ['4'], 'fail' => ['tables']]],
			'no shard tables' => [['ids' => ['4'], 'tables' => ['oc_other_table_4', '']]],
			'renames and back-fills' => [['ids' => ['4', '9', 'x'], 'tables' => $tables, 'columns' => $columns]],
			'a table without the Dutch columns' => [['ids' => ['4'], 'tables' => ['oc_openregister_table_1_4'], 'columns' => ['oc_openregister_table_1_4' => ['id', 'publication_date']]]],
			'columns of one table unreadable' => [['ids' => ['4', '9'], 'tables' => $tables, 'columns' => $columns, 'fail' => ['columns:oc_openregister_table_1_4']]],
			'some statements fail' => [['ids' => ['4', '9'], 'tables' => $tables, 'columns' => $columns, 'fail' => ['exec:RENAME COLUMN "besluit"', 'exec:SET "inventory_list"']]],
			'every statement fails' => [['ids' => ['4', '9'], 'tables' => $tables, 'columns' => $columns, 'fail' => ['exec:']]],
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
	public function testRunMatchesTheRecording(array $world): void {
		$name = $this->dataName();
		$logger = $this->createMock(LoggerInterface::class);
		$logger->method('warning')->willReturnCallback(
			function (string $message, array $context = []): void {
				$this->calls[] = ['warning' => [$message, $context]];
			}
		);
		$output = $this->createMock(IOutput::class);
		$output->method('info')->willReturnCallback(
			function (string $message): void {
				$this->calls[] = ['info' => $message];
			}
		);

		(new RenameDutchPublicationColumns($this->db(world: $world), $logger))->run($output);

		$recorded = [];
		if (file_exists(self::FIXTURE) === true) {
			$recorded = json_decode((string)file_get_contents(self::FIXTURE), true);
		}

		if (getenv('GOLDEN_RECORD') === '1') {
			$recorded[$name] = $this->calls;
			file_put_contents(self::FIXTURE, json_encode($recorded, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
		}

		$this->assertArrayHasKey($name, $recorded, 'No recording for ' . $name);
		$this->assertSame($recorded[$name], json_decode((string)json_encode($this->calls), true));

	}//end testRunMatchesTheRecording()

	/**
	 * Whether the world fails at a point.
	 *
	 * @param array<string, mixed> $world The world.
	 * @param string               $point The failure point.
	 *
	 * @return bool
	 */
	private static function fails(array $world, string $point): bool {
		foreach (($world['fail'] ?? []) as $fail) {
			if ($fail === $point || (str_starts_with($fail, 'exec:') === true && str_starts_with($point, 'exec:') === true && str_contains($point, substr($fail, 5)) === true)) {
				return true;
			}
		}

		return false;

	}//end fails()

	/**
	 * A database that answers the world and records every call.
	 *
	 * @param array<string, mixed> $world The world.
	 *
	 * @return IDBConnection
	 */
	private function db(array $world): IDBConnection {
		$db = $this->createMock(IDBConnection::class);
		$db->method('getDatabasePlatform')->willReturn(
			new class {
				/**
				 * Quote an identifier.
				 *
				 * @param string $identifier The identifier.
				 *
				 * @return string
				 */
				public function quoteSingleIdentifier(string $identifier): string {
					return '"' . $identifier . '"';
				}//end quoteSingleIdentifier()
			}
		);
		$db->method('executeQuery')->willReturnCallback(
			function (string $sql, array $params = []) use ($world): IResult {
				$this->calls[] = ['executeQuery' => [$sql, $params]];
				if (self::fails($world, 'ids') === true) {
					throw new Exception('schemas table locked');
				}

				$result = $this->createMock(IResult::class);
				$result->method('fetchAll')->willReturnCallback(
					function (int $mode) use ($world): array {
						$this->calls[] = ['fetchAll' => $mode];
						return ($world['ids'] ?? []);
					}
				);

				return $result;
			}
		);
		$db->method('prepare')->willReturnCallback(
			function (string $sql) use ($world): IPreparedStatement {
				$this->calls[] = ['prepare' => $sql];
				if (str_contains($sql, 'information_schema.tables') === true && self::fails($world, 'tables') === true) {
					throw new \RuntimeException('information_schema unavailable');
				}

				return $this->statement(world: $world, sql: $sql);
			}
		);
		$db->method('executeStatement')->willReturnCallback(
			function (string $sql) use ($world): int {
				$this->calls[] = ['executeStatement' => $sql];
				if (self::fails($world, 'exec:' . $sql) === true) {
					throw new Exception('statement refused');
				}

				return 1;
			}
		);

		return $db;

	}//end db()

	/**
	 * A prepared statement over the world's tables or columns.
	 *
	 * @param array<string, mixed> $world The world.
	 * @param string               $sql   The prepared SQL.
	 *
	 * @return IPreparedStatement
	 */
	private function statement(array $world, string $sql): IPreparedStatement {
		$bound = [];
		$rows = [];
		$stmt = $this->createMock(IPreparedStatement::class);
		$stmt->method('bindValue')->willReturnCallback(
			function ($param, $value) use (&$bound): bool {
				$this->calls[] = ['bindValue' => [$param, $value]];
				$bound[$param] = $value;
				return true;
			}
		);
		$stmt->method('execute')->willReturnCallback(
			function () use ($world, $sql, &$bound, &$rows): IResult {
				$this->calls[] = ['execute' => $bound];
				if (str_contains($sql, 'information_schema.tables') === true) {
					$rows = array_map(static fn (string $t): array => ['table_name' => $t], ($world['tables'] ?? []));
				} else {
					$table = (string)($bound['table'] ?? '');
					if (self::fails($world, 'columns:' . $table) === true) {
						throw new Exception('columns unreadable');
					}

					$rows = array_map(static fn (string $c): array => ['column_name' => $c], ($world['columns'][$table] ?? []));
				}

				return $this->createMock(IResult::class);
			}
		);
		$stmt->method('fetch')->willReturnCallback(
			function () use (&$rows): array|false {
				return (array_shift($rows) ?? false);
			}
		);

		return $stmt;

	}//end statement()
}//end class
