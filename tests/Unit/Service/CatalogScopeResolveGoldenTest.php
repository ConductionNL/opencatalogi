<?php

/**
 * Golden test for CatalogScopeSlugResolver::resolveScope.
 *
 * Every case is recorded once and replayed from the fixture: the resolved
 * scope and every lookup the resolver made.
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

use OCA\OpenCatalogi\Service\CatalogScopeLookups;
use OCA\OpenCatalogi\Service\CatalogScopeSlugResolver;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Golden test for CatalogScopeSlugResolver::resolveScope.
 *
 * @covers \OCA\OpenCatalogi\Service\CatalogScopeSlugResolver
 * @uses   \OCA\OpenCatalogi\Service\CatalogScopeLookups
 */
class CatalogScopeResolveGoldenTest extends TestCase {

	private const FIXTURE = __DIR__ . '/fixtures/catalog-scope-resolve-golden.json';

	/**
	 * Every lookup made, in order.
	 *
	 * @var array<int, string>
	 */
	private array $calls = [];

	/**
	 * The recorded cases: registers and schemas as stored.
	 *
	 * @return array<string, array{0: array<int, mixed>, 1: array<int, mixed>}>
	 */
	public static function cases(): array {
		return [
			'register and schema slugs' => [['stackiq'], ['organization', 'module']],
			'schema slug not listed yet' => [['stackiq'], ['module', 'usage']],
			'ids only' => [['20', 7], ['33', 34]],
			'unknown register' => [['nope'], ['module']],
			'unknown register and ids' => [['nope', '20'], ['33']],
			'register lookup throws' => [['boom', 'stackiq'], ['MODULE']],
			'register listing throws' => [['broken-register', 'stackiq'], ['module']],
			'schema slug lookup throws' => [['stackiq'], ['module', 'shadow']],
			'first register wins' => [['publication', 'stackiq'], ['organization']],
			'empty scope' => [[], []],
			'blank and odd entries' => [['', null, 'stackiq', 5.5], ['', false, 'module']],
			'schema slugs without registers' => [[], ['module']],
			'register resolves to empty id' => [['empty'], ['module']],
			'duplicate pending' => [['nope', 'NOPE'], ['x']],
		];

	}//end cases()

	/**
	 * Replay a case and compare it to the recording.
	 *
	 * @param array<int, mixed> $registers The registers.
	 * @param array<int, mixed> $schemas   The schemas.
	 *
	 * @return void
	 */
	#[DataProvider('cases')]
	public function testResolveMatchesTheRecording(array $registers, array $schemas): void {
		$name = $this->dataName();
		$actual = ['scope' => $this->resolve(registers: $registers, schemas: $schemas), 'calls' => $this->calls];

		$recorded = [];
		if (file_exists(self::FIXTURE) === true) {
			$recorded = json_decode((string)file_get_contents(self::FIXTURE), true);
		}

		if (getenv('GOLDEN_RECORD') === '1') {
			$recorded[$name] = $actual;
			file_put_contents(self::FIXTURE, json_encode($recorded, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
		}

		$this->assertArrayHasKey($name, $recorded, 'No recording for ' . $name);
		$this->assertSame($recorded[$name], $actual);

	}//end testResolveMatchesTheRecording()

	/**
	 * Resolve a scope against a small register/schema world.
	 *
	 * @param array<int, mixed> $registers The registers.
	 * @param array<int, mixed> $schemas   The schemas.
	 *
	 * @return array<string, mixed> The resolved scope.
	 */
	private function resolve(array $registers, array $schemas): array {
		$registerIds = ['stackiq' => 20, 'publication' => 7, 'broken-register' => 30, 'empty' => ''];
		$schemasOf = [20 => [33, 34, 'x', 36], 7 => [9, 10], 30 => []];
		$slugs = [33 => 'Organization', 34 => 'module', 36 => 'shadow', 9 => 'organization', 10 => 'module'];

		return (new CatalogScopeSlugResolver())->resolveScope(
			registers: $registers,
			schemas: $schemas,
			lookups: new CatalogScopeLookups(
				findRegisterId: function (string $slug) use ($registerIds) {
					$this->calls[] = 'register:' . $slug;
					if ($slug === 'boom') {
						throw new \RuntimeException('boom');
					}

					return ($registerIds[$slug] ?? null);
				},
				schemaIdsOfRegister: function (int $id) use ($schemasOf): array {
					$this->calls[] = 'schemas-of:' . $id;
					if ($id === 30) {
						throw new \RuntimeException('unreadable');
					}

					return ($schemasOf[$id] ?? []);
				},
				schemaSlugOf: function (int $id) use ($slugs): ?string {
					$this->calls[] = 'slug-of:' . $id;
					if ($id === 36) {
						throw new \RuntimeException('unreadable');
					}

					return ($slugs[$id] ?? null);
				}
			)
		);

	}//end resolve()
}//end class
