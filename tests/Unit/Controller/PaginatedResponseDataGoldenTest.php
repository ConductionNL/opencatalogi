<?php

/**
 * Golden test for the paginated answer of the configured-object list
 * endpoints (ListsConfiguredObjects::paginatedResponseData()).
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

namespace Unit\Controller;

use OCA\OpenCatalogi\Controller\ListsConfiguredObjects;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * The exact answer, key order included, for each shape of search result.
 */
class PaginatedResponseDataGoldenTest extends TestCase {

	/**
	 * Search results and the answer for each.
	 *
	 * @return array<string, array{0: array<string, mixed>, 1: array<string, mixed>}>
	 */
	public static function results(): array {
		$defaults = ['results' => [], 'total' => 0, 'limit' => 20, 'offset' => 0, 'page' => 1, 'pages' => 1];
		return [
			'empty' => [[], $defaults],
			'paging only' => [['results' => [['id' => 1]], 'total' => 41, 'limit' => 10, 'offset' => 20, 'page' => 3, 'pages' => 5, 'other' => 'x'], ['results' => [['id' => 1]], 'total' => 41, 'limit' => 10, 'offset' => 20, 'page' => 3, 'pages' => 5]],
			'every part, in reverse' => [
				['facetable' => ['f'], 'facets' => ['a' => 1], 'prev' => 'p', 'next' => 'n'],
				$defaults + ['next' => 'n', 'prev' => 'p', 'facets' => ['a' => 1], 'facetable' => ['f']],
			],
			'prev only' => [['prev' => 'p'], $defaults + ['prev' => 'p']],
			'null parts' => [['next' => null, 'prev' => null, 'facets' => null, 'facetable' => null], $defaults],
			'nested facets' => [['facets' => ['facets' => ['b' => 2], 'other' => 1]], $defaults + ['facets' => ['b' => 2]]],
			'nested facets not a list' => [['facets' => ['facets' => 'x']], $defaults + ['facets' => ['facets' => 'x']]],
			'facets not an array' => [['facets' => 'flat'], $defaults + ['facets' => 'flat']],
		];

	}//end results()

	/**
	 * The answer for one search result.
	 *
	 * @param array<string, mixed> $result The search result.
	 * @param array<string, mixed> $expected The answer.
	 *
	 * @return void
	 */
	#[DataProvider('results')]
	public function testPaginatedResponseData(array $result, array $expected): void {
		$host = new class {
			use ListsConfiguredObjects;
		};
		$method = new ReflectionMethod($host, 'paginatedResponseData');
		$method->setAccessible(true);

		$this->assertSame($expected, $method->invoke($host, $result));

	}//end testPaginatedResponseData()
}//end class
