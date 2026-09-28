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
	 * Scope shapes and the `@self` block OpenRegister has to receive for each.
	 *
	 * @return array<string, array{0: array<int, int>, 1: array<int, int>, 2: array<string, mixed>}>
	 */
	public static function scopeShapes(): array {
		return [
			'one schema, one register'      => [[20], [28], ['register' => 20, 'schema' => 28]],
			'several schemas, one register' => [[20], [28, 29], ['register' => 20, 'schema' => [28, 29]]],
			'one schema, several registers' => [[20, 21], [28], ['register' => [20, 21], 'registers' => [20, 21], 'schema' => [28]]],
			'several of both'               => [[20, 21], [28, 29], ['register' => [20, 21], 'registers' => [20, 21], 'schema' => [28, 29]]],
			'one schema, no register'       => [[], [28], ['schema' => [28]]],
		];
	}

	/**
	 * WOO-581 review round 3 (f1): OpenRegister routes on the shape of the scope.
	 * A scalar schema next to anything but one scalar register runs
	 * `find((int) $register)` — register 1 for a list — or falls through to the
	 * all-tables `_ids` lookup; a register list under `@self.register` alone
	 * collapses the same way on the multi-schema route. The schema is scalar only
	 * for one schema in one register, and a register list is also written to
	 * `@self.registers`.
	 *
	 * @dataProvider scopeShapes
	 *
	 * @param array<int, int>      $registers The scope's registers.
	 * @param array<int, int>      $schemas   The scope's schemas.
	 * @param array<string, mixed> $self      The expected `@self` block.
	 *
	 * @return void
	 */
	public function testWriteScopeOnlyEmitsAScalarSchemaForOneSchemaInOneRegister(array $registers, array $schemas, array $self): void {
		$query = CallerScope::writeScope(query: ['_limit' => 5, '@self' => ['owner' => 'alice']], registers: $registers, schemas: $schemas);

		$this->assertSame(['owner' => 'alice'] + $self, $query['@self']);
		$this->assertSame(5, $query['_limit']);
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
