<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace Unit\Service;

use OCA\OpenCatalogi\Service\AppliedFilters;
use OCA\OpenCatalogi\Service\CallerScope;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for CallerScope::writeScope().
 *
 * @covers \OCA\OpenCatalogi\Service\CallerScope
 * @uses \OCA\OpenCatalogi\Service\AppliedFilters
 */
class CallerScopeTest extends TestCase {

	/**
	 * Scope shapes and the query OpenRegister has to receive for each.
	 *
	 * @return array<string, array{0: array<int, int>, 1: array<int, int>, 2: array<string, mixed>}>
	 */
	public static function scopeShapes(): array {
		return [
			'one schema, one register' => [[20], [28], ['@self' => ['owner' => 'alice', 'register' => 20, 'schema' => 28]]],
			'several schemas, one register' => [[20], [28, 29], ['@self' => ['owner' => 'alice', 'register' => 20, 'schema' => [28, 29]], '_schemas' => [28, 29]]],
			'one schema, several registers' => [[20, 21], [28], ['@self' => ['owner' => 'alice', 'register' => [20, 21], 'schema' => [28]], '_registers' => [20, 21], '_schemas' => [28]]],
			'several of both' => [[20, 21], [28, 29], ['@self' => ['owner' => 'alice', 'register' => [20, 21], 'schema' => [28, 29]], '_registers' => [20, 21], '_schemas' => [28, 29]]],
		];
	}

	/**
	 * WOO-581 review rounds 3 and 4 (f1): OpenRegister routes on the shape of the
	 * scope. A scalar schema next to anything but one scalar register runs
	 * `find((int) $register)` — register 1 for a list — or falls through to the
	 * all-tables `_ids` lookup; a register or schema list under `@self` alone
	 * collapses the same way in the row and facet routers. The schema is scalar
	 * only for one schema in one register; every list also goes to the top-level
	 * `_registers` / `_schemas`, never to `@self.registers` / `@self.schemas`,
	 * which OR applies as (unknown) column filters and rejects.
	 *
	 * @dataProvider scopeShapes
	 *
	 * @param array<int, int> $registers The scope's registers.
	 * @param array<int, int> $schemas The scope's schemas.
	 * @param array<string, mixed> $expected The expected scope keys of the query.
	 *
	 * @return void
	 */
	public function testWriteScopeOnlyEmitsAScalarSchemaForOneSchemaInOneRegister(array $registers, array $schemas, array $expected): void {
		$query = CallerScope::writeScope(query: ['_limit' => 5, '@self' => ['owner' => 'alice']], registers: $registers, schemas: $schemas);

		$this->assertSame(['_limit' => 5] + $expected, $query);
		$this->assertArrayNotHasKey('registers', $query['@self']);
		$this->assertArrayNotHasKey('schemas', $query['@self']);
	}//end testWriteScopeOnlyEmitsAScalarSchemaForOneSchemaInOneRegister()

	/**
	 * Empty scopes, which writeScope() refuses to write.
	 *
	 * @return array<string, array{0: array<int, int>, 1: array<int, int>}>
	 */
	public static function emptyScopes(): array {
		return [
			'no register' => [[], [28]],
			'no schema' => [[20], []],
			'neither' => [[], []],
		];
	}

	/**
	 * WOO-581 review round 6: neither empty list is safe to hand to OR. No register
	 * only finds nothing by accident (register 0), and an empty schema list skips the
	 * multi-schema route and can reach the all-tables `_ids` lookup. Every caller
	 * fails closed first; writeScope() throws, so one that forgets fails loud and
	 * closed instead of relying on that accident.
	 *
	 * @dataProvider emptyScopes
	 *
	 * @param array<int, int> $registers The scope's registers.
	 * @param array<int, int> $schemas The scope's schemas.
	 *
	 * @return void
	 */
	public function testWriteScopeRefusesAnEmptyScope(array $registers, array $schemas): void {
		$this->expectException(\InvalidArgumentException::class);

		CallerScope::writeScope(query: ['_ids' => ['uuid-x']], registers: $registers, schemas: $schemas);
	}//end testWriteScopeRefusesAnEmptyScope()

	/**
	 * A query without (or with a non-array) `@self` gets a fresh block.
	 *
	 * @return void
	 */
	public function testWriteScopeCreatesTheSelfBlock(): void {
		$this->assertSame(['register' => 20, 'schema' => 28], CallerScope::writeScope(query: ['@self' => 'x'], registers: [20], schemas: [28])['@self']);
	}//end testWriteScopeCreatesTheSelfBlock()
	/**
	 * strip() names every scope key it removes, in check order, with `@self`
	 * sub-keys prefixed; the values the caller sent are not echoed (REQ-ASF-002).
	 *
	 * @return void
	 */
	public function testStripReportsTheRemovedKeys(): void {
		$filters = new AppliedFilters();

		$query = CallerScope::strip(
			query: ['q' => 'x', '_schemas' => ['secret'], 'register' => 7, '@self' => ['schema' => 9, 'owner' => 'me']],
			appliedFilters: $filters
		);

		$this->assertSame(['q' => 'x', '@self' => ['owner' => 'me']], $query);
		$entries = $filters->all();
		$this->assertCount(1, $entries);
		$this->assertSame('callerScopeIgnored', $entries[0]['name']);
		$this->assertSame(['register', '_schemas', '@self.schema'], $entries[0]['value']);
		$this->assertStringNotContainsString('secret', (string)json_encode($entries));
	}//end testStripReportsTheRemovedKeys()

	/**
	 * A query without scope keys records nothing, and a non-array `@self` is named as `@self`.
	 *
	 * @return void
	 */
	public function testStripRecordsNothingWithoutScopeKeysAndNamesAScalarSelf(): void {
		$filters = new AppliedFilters();
		CallerScope::strip(query: ['q' => 'x', '@self' => ['owner' => 'me']], appliedFilters: $filters);
		$this->assertSame([], $filters->all());

		$this->assertSame(['@self'], CallerScope::removedKeys(query: ['@self' => 'x']));
		$this->assertSame([], CallerScope::removedKeys(query: ['q' => 'x']));
	}//end testStripRecordsNothingWithoutScopeKeysAndNamesAScalarSelf()

}//end class
