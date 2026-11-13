<?php

/**
 * Unit tests for RenameDutchPublicationColumns.
 *
 * Covers the decision that determines which shard tables the migration touches,
 * plus two invariants the step relies on that were previously asserted only in
 * prose.
 *
 * The DDL/DML paths are deliberately not unit-tested: they need a live
 * database. What IS testable in isolation is the logic deciding which tables
 * are in scope, and that is what these tests pin.
 *
 * @category Tests
 * @package  Unit\Repair
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <dev@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace Unit\Repair;

use OCA\OpenCatalogi\Repair\RenameDutchPublicationColumns;
use OCP\DB\IPreparedStatement;
use OCP\DB\IResult;
use OCP\IDBConnection;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ReflectionClass;
use ReflectionMethod;

/**
 * @covers \OCA\OpenCatalogi\Repair\RenameDutchPublicationColumns
 */
class RenameDutchPublicationColumnsTest extends TestCase {
	/**
	 * The step under test.
	 *
	 * @var RenameDutchPublicationColumns
	 */
	private RenameDutchPublicationColumns $step;

	/**
	 * Build the step WITHOUT running its constructor.
	 *
	 * The methods under test are pure — they read neither $db nor $logger — so
	 * no collaborators are needed, and mocking IDBConnection can drag in
	 * Doctrine types the unit environment does not install. Skipping the
	 * constructor keeps the test honest about what it exercises.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->step = (new ReflectionClass(RenameDutchPublicationColumns::class))->newInstanceWithoutConstructor();

	}//end setUp()

	/**
	 * Invoke a private method on a step.
	 *
	 * @param string $name Method name.
	 * @param array<mixed> $args Positional arguments.
	 * @param RenameDutchPublicationColumns|null $on The step to invoke on; the
	 *                                               constructor-less one when omitted.
	 *
	 * @return mixed
	 */
	private function call(string $name, array $args, ?RenameDutchPublicationColumns $on = null) {
		$m = new ReflectionMethod(RenameDutchPublicationColumns::class, $name);
		$m->setAccessible(true);
		return $m->invokeArgs($on ?? $this->step, $args);
	}//end call()

	/**
	 * Read a private constant off the step.
	 *
	 * @param string $name Constant name.
	 *
	 * @return mixed
	 */
	private function constant(string $name) {
		return (new ReflectionClass(RenameDutchPublicationColumns::class))->getConstant($name);
	}//end constant()

	/**
	 * Every openregister shard table is in scope, regardless of which schema
	 * it belongs to.
	 *
	 * This used to resolve `Publication`/`Document`/`WooBatch` schema ids
	 * first and only consider their shards, which silently excluded a
	 * tenant's own domain schema (a Woo category, say) carrying the exact same
	 * Dutch columns under a different title. There is no longer a schema
	 * lookup at all: `shardTables()` lists every shard, and `migrateTable()`'s
	 * own per-column check (not under test here) decides which of them
	 * actually need a column moved.
	 *
	 * @return void
	 */
	public function testListsEveryShardRegardlessOfSchema(): void {
		$tables = $this->shardTablesOver(['oc_openregister_table_15_42', 'oc_openregister_table_2424_99']);

		self::assertSame(['oc_openregister_table_15_42', 'oc_openregister_table_2424_99'], $tables);

	}//end testListsEveryShardRegardlessOfSchema()

	/**
	 * A table without the openregister marker is never selected.
	 *
	 * information_schema's `LIKE` match is already anchored on the marker, but
	 * a defence-in-depth check on the fetched name is what keeps a same-named
	 * table from an unrelated app off the migration.
	 *
	 * @return void
	 */
	public function testRequiresTheOpenregisterMarker(): void {
		$tables = $this->shardTablesOver(['oc_some_other_42', '', 'oc_openregister_table_1_4']);

		self::assertSame(['oc_openregister_table_1_4'], $tables);

	}//end testRequiresTheOpenregisterMarker()

	/**
	 * Duplicate rows from information_schema collapse to one entry.
	 *
	 * @return void
	 */
	public function testDeduplicatesTables(): void {
		$tables = $this->shardTablesOver(['oc_openregister_table_1_4', 'oc_openregister_table_1_4']);

		self::assertSame(['oc_openregister_table_1_4'], $tables);

	}//end testDeduplicatesTables()

	/**
	 * `shardTables()` over a fixed list of information_schema rows.
	 *
	 * @param array<int, string> $tableNames The `table_name` rows to answer with.
	 *
	 * @return array<int, string>
	 */
	private function shardTablesOver(array $tableNames): array {
		$rows = array_map(static fn (string $name): array => ['table_name' => $name], $tableNames);

		$statement = $this->createMock(IPreparedStatement::class);
		$statement->method('bindValue')->willReturn(true);
		$statement->method('execute')->willReturn($this->createMock(IResult::class));
		$statement->method('fetch')->willReturnCallback(
			static function () use (&$rows) {
				return (array_shift($rows) ?? false);
			}
		);

		$db = $this->createMock(IDBConnection::class);
		$db->method('prepare')->willReturn($statement);

		$step = new RenameDutchPublicationColumns($db, $this->createMock(LoggerInterface::class));

		return $this->call('shardTables', [], $step);

	}//end shardTablesOver()

	/**
	 * Every destination is snake_case, never camelCase.
	 *
	 * MagicMapper stores `publicationDate` as `publication_date`, and its
	 * de-duplication path DROPS a camelCase column whose snake_case twin
	 * exists — so a camelCase destination would be deleted on the next sync.
	 *
	 * @return void
	 */
	public function testEveryDestinationIsSnakeCase(): void {
		$map = $this->constant('COLUMN_MAP');
		self::assertIsArray($map);
		foreach ($map as $old => $new) {
			self::assertSame(
				strtolower($new),
				$new,
				"Destination '$new' (from '$old') must be snake_case, not camelCase"
			);
		}

	}//end testEveryDestinationIsSnakeCase()

	/**
	 * No two Dutch columns map to the same English name.
	 *
	 * This step has no collision guard, unlike its siblings in procest and
	 * softwarecatalog, and needs none only for as long as the map stays
	 * injective. If a later edit introduces a duplicate destination the step
	 * would silently overwrite one value with another; this catches it at
	 * review time rather than in production.
	 *
	 * @return void
	 */
	public function testColumnMapIsInjective(): void {
		$map = $this->constant('COLUMN_MAP');
		self::assertSame(
			count($map),
			count(array_unique(array_values($map))),
			'Two Dutch columns map to the same English name; add a collision guard first'
		);

	}//end testColumnMapIsInjective()

	/**
	 * The publication date is in the map.
	 *
	 * Not decoration: anonymous visibility on the ORI harvest feed is governed
	 * by `publicatiedatum <= now`. If this entry were dropped the column would
	 * never move, every read would return null, and the feed's visibility rule
	 * would change silently.
	 *
	 * @return void
	 */
	public function testPublicationDateIsMigrated(): void {
		$map = $this->constant('COLUMN_MAP');
		self::assertArrayHasKey('publicatiedatum', $map);
		self::assertSame('publication_date', $map['publicatiedatum']);

	}//end testPublicationDateIsMigrated()

	/**
	 * The step reports a human-readable name.
	 *
	 * @return void
	 */
	public function testGetName(): void {
		self::assertNotSame('', $this->step->getName());

	}//end testGetName()
}//end class
