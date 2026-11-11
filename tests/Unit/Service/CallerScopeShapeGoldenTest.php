<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Golden test for CallerScope: the exact query, key order included, that
 * strip() leaves and writeScope() writes for every shape a caller's query and
 * a server scope can take, and the refusal of an empty scope.
 *
 * Re-record (only when behaviour is meant to change) with
 * `GOLDEN_RECORD=1 vendor/bin/phpunit -c phpunit-unit.xml tests/Unit/Service/CallerScopeShapeGoldenTest.php`.
 */

declare(strict_types=1);

namespace Unit\Service;

use OCA\OpenCatalogi\Service\CallerScope;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Golden test for CallerScope.
 */
class CallerScopeShapeGoldenTest extends TestCase {

	private const FIXTURE = __DIR__ . '/fixtures/caller-scope-shape-golden.json';

	/**
	 * Queries as a caller may send them.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private static function callerQueries(): array {
		return [
			'plain' => ['_search' => 'woo', '_limit' => 5],
			'every top-level scope key' => ['register' => 1, 'schema' => 2, '_register' => 3, '_registers' => [4], '_schema' => 5, '_schemas' => [6], '_search' => 'x'],
			'self scope and filters' => ['@self' => ['register' => 1, 'registers' => [2], 'schema' => 3, 'schemas' => [4], 'owner' => 'alice', 'created' => '2026'], '_page' => 2],
			'self only scope' => ['@self' => ['schema' => 3]],
			'self a string' => ['@self' => 'schema=3', '_search' => 'x'],
			'self null' => ['@self' => null, '_search' => 'x'],
			'self empty' => ['@self' => [], '_order' => ['title' => 'asc']],
			'self first' => ['@self' => ['owner' => 'bob'], 'schema' => 9, '_limit' => 1],
		];

	}//end callerQueries()

	/**
	 * Every case: strip a caller query, or write a scope into a query.
	 *
	 * @return array<string, array{0: string, 1: array<string, mixed>, 2: array<int|string, mixed>, 3: array<int|string, mixed>}>
	 */
	public static function cases(): array {
		$cases = [];
		foreach (self::callerQueries() as $name => $query) {
			$cases['strip: ' . $name] = ['strip', $query, [], []];
		}

		$scopes = [
			'one in one' => [[20], [28]],
			'two schemas in one' => [[20], [28, 29]],
			'one in two' => [[20, 21], [28]],
			'two in two' => [[20, 21], [28, 29]],
			'keyed lists' => [['a' => 20, 'b' => 21], ['x' => 28]],
			'keyed single' => [['a' => 20], ['x' => '28']],
			'no register' => [[], [28]],
			'no schema' => [[20], []],
		];
		$queries = [
			'empty' => [],
			'with filters' => ['_search' => 'woo', '@self' => ['owner' => 'alice']],
			'with stale lists' => ['_schemas' => [99], '_registers' => [98], '_limit' => 5],
			'self a string' => ['@self' => 'x', '_page' => 1],
			'self scope already' => ['@self' => ['schema' => 1, 'register' => 2, 'owner' => 'z']],
		];
		foreach ($scopes as $scopeName => [$registers, $schemas]) {
			foreach ($queries as $queryName => $query) {
				$cases['write ' . $scopeName . ' into ' . $queryName] = ['write', $query, $registers, $schemas];
			}
		}

		return $cases;

	}//end cases()

	/**
	 * Run one case and compare it, key order included, with the recording.
	 *
	 * @param string $operation strip or write.
	 * @param array<string, mixed> $query The query.
	 * @param array<int|string, mixed> $registers The scope's registers.
	 * @param array<int|string, mixed> $schemas The scope's schemas.
	 *
	 * @return void
	 */
	#[DataProvider('cases')]
	public function testTheQueryMatchesTheRecording(string $operation, array $query, array $registers, array $schemas): void {
		$name = $this->dataName();
		try {
			$actual = CallerScope::strip(query: $query);
			if ($operation === 'write') {
				$actual = CallerScope::writeScope(query: $query, registers: $registers, schemas: $schemas);
			}
		} catch (\InvalidArgumentException $e) {
			$actual = ['refused' => $e->getMessage()];
		}

		$encoded = json_encode($actual, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

		$recorded = [];
		if (is_file(self::FIXTURE) === true) {
			$recorded = json_decode((string)file_get_contents(self::FIXTURE), true);
		}

		if (getenv('GOLDEN_RECORD') === '1') {
			$recorded[$name] = $encoded;
			ksort($recorded);
			file_put_contents(self::FIXTURE, json_encode($recorded, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
		}

		$this->assertArrayHasKey($name, $recorded, 'No recording for this case.');
		$this->assertSame($recorded[$name], $encoded);

	}//end testTheQueryMatchesTheRecording()
}//end class
