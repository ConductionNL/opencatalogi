<?php

/**
 * Golden test for the register configuration merge in SettingsService.
 *
 * Runs the private static `deepMergeConfig()` and
 * `attachOrphanSchemasToPublicationRegister()` over payloads that reach every
 * branch: list and map arrays on either side, empty arrays, scalars over
 * arrays and arrays over scalars, nested maps, payloads without schemas or a
 * publication register, registers with non-list schema fields, non-string
 * slugs and explicit configuration. Each result is compared, key order
 * included, with what was recorded.
 *
 * Re-record (only when behaviour is meant to change) with
 * `GOLDEN_RECORD=1 vendor/bin/phpunit tests/Unit/Service/SettingsConfigMergeGoldenTest.php`.
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
 * @spec openspec/specs/admin-settings/spec.md
 *
 * @covers \OCA\OpenCatalogi\Service\SettingsService
 */

declare(strict_types=1);

namespace Unit\Service;

use OCA\OpenCatalogi\Service\SettingsService;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * Golden test for SettingsService::deepMergeConfig() and attachOrphanSchemasToPublicationRegister().
 */
class SettingsConfigMergeGoldenTest extends TestCase {

	private const FIXTURE = __DIR__ . '/../../fixtures/settings-config-merge-golden.json';

	/**
	 * The base and overlay of each merge case.
	 *
	 * @return array<string, array{0: array<mixed>, 1: array<mixed>}>
	 */
	public static function merges(): array {
		return [
			'empty both' => [[], []],
			'disjoint maps' => [['a' => 1], ['b' => 2]],
			'scalar overwrites' => [['a' => 1, 'b' => 'x'], ['a' => 2]],
			'lists concatenate' => [['l' => [1, 2]], ['l' => [2, 3]]],
			'empty base list' => [['l' => []], ['l' => ['x']]],
			'empty overlay list' => [['l' => ['x']], ['l' => []]],
			'list into map' => [['m' => ['k' => 1]], ['m' => [5, 6]]],
			'map into list' => [['l' => [1, 2]], ['l' => ['k' => 'v']]],
			'sparse list is a map' => [['l' => [0 => 'a', 2 => 'c']], ['l' => [0 => 'z']]],
			'array over scalar' => [['a' => 'x'], ['a' => ['y']]],
			'scalar over array' => [['a' => ['y']], ['a' => 'x']],
			'null overwrites' => [['a' => ['y']], ['a' => null]],
			'nested maps' => [
				['components' => ['schemas' => ['one' => ['type' => 'object', 'tags' => ['a']]], 'registers' => ['r' => ['schemas' => ['one']]]]],
				['components' => ['schemas' => ['two' => ['type' => 'object'], 'one' => ['tags' => ['b'], 'title' => 'One']], 'registers' => ['r' => ['schemas' => ['two']]]]],
			],
			'integer keys map' => [[5 => 'a', 7 => 'b'], [5 => 'c']],
			'list overlay keys' => [['l' => ['a']], [0 => 'top', 'l' => ['b']]],
		];

	}//end merges()

	/**
	 * The payloads each attach case transforms.
	 *
	 * @return array<string, array{0: array<mixed>}>
	 */
	public static function payloads(): array {
		$publication = ['schemas' => ['publication'], 'configuration' => ['schemas' => ['publication' => ['magicMapping' => false]]]];

		return [
			'no components' => [[]],
			'schemas not a map' => [['components' => ['schemas' => 'x', 'registers' => ['publication' => $publication]]]],
			'no schemas' => [['components' => ['schemas' => [], 'registers' => ['publication' => $publication]]]],
			'no publication register' => [['components' => ['schemas' => ['a' => []], 'registers' => ['other' => ['schemas' => []]]]]],
			'publication register not a map' => [['components' => ['schemas' => ['a' => []], 'registers' => ['publication' => 'x']]]],
			'all declared' => [['components' => ['schemas' => ['publication' => [], 'a' => []], 'registers' => ['publication' => $publication, 'other' => ['schemas' => ['a']]]]]],
			'orphans attached' => [[
				'components' => [
					'schemas' => ['publication' => [], 'a' => [], 'b' => [], 'c' => [], 7 => []],
					'registers' => [
						'publication' => $publication + ['extra' => true],
						'other' => ['schemas' => ['b', 5, null]],
						'broken' => ['schemas' => 'c'],
						'scalar' => 'x',
						'none' => [],
					],
				],
				'paths' => ['/x' => []],
			]],
			'explicit configuration kept' => [[
				'components' => [
					'schemas' => ['a' => [], 'b' => []],
					'registers' => ['publication' => ['configuration' => ['schemas' => ['a' => ['magicMapping' => false]]]]],
				],
			]],
			'register without schemas key' => [['components' => ['schemas' => ['a' => []], 'registers' => ['publication' => ['title' => 'P']]]]],
		];

	}//end payloads()

	/**
	 * deepMergeConfig() matches the recording.
	 *
	 * @param array<mixed> $base    The base.
	 * @param array<mixed> $overlay The overlay.
	 *
	 * @return void
	 */
	#[DataProvider('merges')]
	public function testMergeMatchesTheRecording(array $base, array $overlay): void {
		$this->compare(name: 'merge: ' . $this->dataName(), actual: $this->call(name: 'deepMergeConfig', args: [$base, $overlay]));

	}//end testMergeMatchesTheRecording()

	/**
	 * attachOrphanSchemasToPublicationRegister() matches the recording.
	 *
	 * @param array<mixed> $payload The payload.
	 *
	 * @return void
	 */
	#[DataProvider('payloads')]
	public function testAttachMatchesTheRecording(array $payload): void {
		$this->compare(name: 'attach: ' . $this->dataName(), actual: $this->call(name: 'attachOrphanSchemasToPublicationRegister', args: [$payload]));

	}//end testAttachMatchesTheRecording()

	/**
	 * Call one private static method.
	 *
	 * @param string       $name The method.
	 * @param array<mixed> $args The arguments.
	 *
	 * @return mixed
	 */
	private function call(string $name, array $args): mixed {
		$method = new ReflectionMethod(SettingsService::class, $name);
		return $method->invoke(null, ...$args);

	}//end call()

	/**
	 * Compare one result with its recording, recording it under GOLDEN_RECORD=1.
	 *
	 * The result is stored as JSON text, so empty and integer keys survive.
	 *
	 * @param string $name   The recording key.
	 * @param mixed  $actual The result.
	 *
	 * @return void
	 */
	private function compare(string $name, mixed $actual): void {
		$json = json_encode($actual, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
		$recorded = [];
		if (is_file(self::FIXTURE) === true) {
			$recorded = json_decode((string)file_get_contents(self::FIXTURE), true);
		}

		if (getenv('GOLDEN_RECORD') === '1') {
			$recorded[$name] = $json;
			ksort($recorded);
			file_put_contents(self::FIXTURE, json_encode($recorded, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
		}

		$this->assertArrayHasKey($name, $recorded, 'No recording for this case.');
		$this->assertSame($recorded[$name], $json);

	}//end compare()
}//end class
