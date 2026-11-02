<?php

/**
 * Golden test for `DirectoryService::aggregateFacets()`.
 *
 * Merges facet sets as the federated search does across peers and compares
 * the result with what was recorded: empty sides, new fields, non-array
 * fields and buckets, count merging by `_id`, buckets without a count, and
 * the count-then-id ordering with its tie and malformed-bucket cases.
 *
 * Re-record (only when behaviour is meant to change) with
 * `GOLDEN_RECORD=1 vendor/bin/phpunit -c phpunit-unit.xml tests/Unit/Service/FacetAggregationGoldenTest.php`.
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
 * @spec openspec/specs/federation/spec.md
 *
 * @covers \OCA\OpenCatalogi\Service\DirectoryService
 */

declare(strict_types=1);

namespace OCA\OpenCatalogi\Tests\Unit\Service;

use OCA\OpenCatalogi\Service\DirectoryService;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * Golden test for DirectoryService::aggregateFacets().
 */
class FacetAggregationGoldenTest extends TestCase {

	private const FIXTURE = __DIR__ . '/../../fixtures/facet-aggregation-golden.json';

	/**
	 * The existing and new facet sets of each case.
	 *
	 * @return array<string, array{0: array<string, mixed>, 1: array<string, mixed>}>
	 */
	public static function cases(): array {
		$type = [['_id' => 'besluit', 'count' => 3], ['_id' => 'advies', 'count' => 5]];
		return [
			'both empty' => [[], []],
			'new empty' => [['type' => $type], []],
			'existing empty' => [[], ['type' => $type]],
			'new field' => [['type' => $type], ['year' => [['_id' => '2026', 'count' => 1]]]],
			'new field not an array' => [['type' => $type], ['year' => 'x', 'type' => [['_id' => 'besluit', 'count' => 1]]]],
			'existing field not an array' => [['type' => 'x', 'other' => $type], ['type' => [['_id' => 'a', 'count' => 1]]]],
			'counts merge and sort' => [
				['type' => $type],
				['type' => [['_id' => 'besluit', 'count' => 4], ['_id' => 'nieuw', 'count' => 2]]],
			],
			'tie sorts by id' => [
				['type' => [['_id' => 'b', 'count' => 2], ['_id' => 'c', 'count' => 2]]],
				['type' => [['_id' => 'a', 'count' => 2]]],
			],
			'incoming without count is appended' => [
				['type' => $type],
				['type' => [['_id' => 'besluit'], ['_id' => 'zonder']]],
			],
			'existing without count is kept' => [
				['type' => [['_id' => 'besluit'], ['_id' => 'advies', 'count' => 1]]],
				['type' => [['_id' => 'besluit', 'count' => 4]]],
			],
			'malformed buckets' => [
				['type' => ['loose', ['count' => 9], ['_id' => 'x', 'count' => 1]]],
				['type' => ['loose', ['count' => 2], ['_id' => 'x', 'count' => 2], ['_id' => 'y', 'count' => 7]]],
			],
			'duplicate incoming ids' => [
				['type' => [['_id' => 'a', 'count' => 1]]],
				['type' => [['_id' => 'b', 'count' => 1], ['_id' => 'b', 'count' => 2], ['_id' => 'a', 'count' => 1]]],
			],
			'string counts' => [
				['type' => [['_id' => 'a', 'count' => '1'], ['_id' => 'b', 'count' => 1]]],
				['type' => [['_id' => 'c', 'count' => '1']]],
			],
			'empty field lists' => [['type' => []], ['type' => []]],
			'keyed buckets' => [
				['type' => ['k1' => ['_id' => 'a', 'count' => 1], 'k2' => ['_id' => 'b', 'count' => 5]]],
				['type' => [['_id' => 'a', 'count' => 9]]],
			],
		];

	}//end cases()

	/**
	 * Merge one case and compare it with the recording.
	 *
	 * @param array<string, mixed> $existing The current aggregated facets.
	 * @param array<string, mixed> $new The facets to merge in.
	 *
	 * @return void
	 */
	#[DataProvider('cases')]
	public function testAggregationMatchesTheRecording(array $existing, array $new): void {
		$name = $this->dataName();
		$reflection = new ReflectionClass(DirectoryService::class);
		$service = $reflection->newInstanceWithoutConstructor();
		$actual = $reflection->getMethod('aggregateFacets')->invoke($service, $existing, $new);

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

	}//end testAggregationMatchesTheRecording()
}//end class
