<?php

declare(strict_types=1);

namespace Unit\Service;

use OCA\OpenCatalogi\Service\CallerScope;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for CallerScope::writeScope().
 *
 * @covers \OCA\OpenCatalogi\Service\CallerScope
 */
class CallerScopeTest extends TestCase {

	/**
	 * Scope shapes and the query OpenRegister has to receive for each.
	 *
	 * @return array<string, array{0: array<int, int>, 1: array<int, int>, 2: array<string, mixed>}>
	 */
	public static function scopeShapes(): array {
		return [
			'one schema, one register'      => [[20], [28], ['@self' => ['owner' => 'alice', 'register' => 20, 'schema' => 28]]],
			'several schemas, one register' => [[20], [28, 29], ['@self' => ['owner' => 'alice', 'register' => 20, 'schema' => [28, 29]], '_schemas' => [28, 29]]],
			'one schema, several registers' => [[20, 21], [28], ['@self' => ['owner' => 'alice', 'register' => [20, 21], 'schema' => [28]], '_registers' => [20, 21], '_schemas' => [28]]],
			'several of both'               => [[20, 21], [28, 29], ['@self' => ['owner' => 'alice', 'register' => [20, 21], 'schema' => [28, 29]], '_registers' => [20, 21], '_schemas' => [28, 29]]],
			// Callers refuse an empty scope before they get here; if one does not, the
			// empty register list is written as-is (OR reads it as register 0: nothing).
			'one schema, no register'       => [[], [28], ['@self' => ['owner' => 'alice', 'register' => [], 'schema' => [28]], '_schemas' => [28]]],
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
	 * @param array<int, int>      $registers The scope's registers.
	 * @param array<int, int>      $schemas   The scope's schemas.
	 * @param array<string, mixed> $expected  The expected scope keys of the query.
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
	 * A query without (or with a non-array) `@self` gets a fresh block.
	 *
	 * @return void
	 */
	public function testWriteScopeCreatesTheSelfBlock(): void {
		$this->assertSame(['register' => 20, 'schema' => 28], CallerScope::writeScope(query: ['@self' => 'x'], registers: [20], schemas: [28])['@self']);
	}//end testWriteScopeCreatesTheSelfBlock()
}//end class
