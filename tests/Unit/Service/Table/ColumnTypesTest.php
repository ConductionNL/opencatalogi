<?php

/**
 * Unit tests for ColumnTypes.
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

namespace Unit\Service\Table;

use OCA\OpenCatalogi\Service\Table\ColumnTypes;
use PHPUnit\Framework\TestCase;

/**
 * The guessed column types of a CSV with mixed columns.
 *
 * @covers \OCA\OpenCatalogi\Service\Table\ColumnTypes
 */
class ColumnTypesTest extends TestCase {

	/**
	 * Each column gets the narrowest type every sample value fits.
	 *
	 * @return void
	 */
	public function testEachColumnGetsTheNarrowestTypeItsValuesFit(): void {
		$rows = [
			['naam' => 'Park', 'toestellen' => '4', 'oppervlak' => '12.5', 'open' => 'true', 'sinds' => '2020-02-29', 'leeg' => ''],
			['naam' => 'Plein', 'toestellen' => '-2', 'oppervlak' => '3', 'open' => 'FALSE', 'sinds' => '2021-12-01', 'leeg' => ' '],
		];

		$properties = ColumnTypes::properties(header: array_keys($rows[0]), rows: $rows);

		$this->assertSame(['naam', 'toestellen', 'oppervlak', 'open', 'sinds', 'leeg'], array_keys($properties));
		$this->assertSame('string', $properties['naam']['type']);
		$this->assertSame('integer', $properties['toestellen']['type']);
		$this->assertSame('number', $properties['oppervlak']['type']);
		$this->assertSame('boolean', $properties['open']['type']);
		$this->assertSame(['type' => 'string', 'format' => 'date'], array_intersect_key($properties['sinds'], ['type' => 1, 'format' => 1]));
		$this->assertSame('string', $properties['leeg']['type']);
		$this->assertArrayNotHasKey('format', $properties['naam']);
	}//end testEachColumnGetsTheNarrowestTypeItsValuesFit()

	/**
	 * One value that does not fit makes the column text; an impossible date is not a date.
	 *
	 * @return void
	 */
	public function testOneValueThatDoesNotFitMakesTheColumnText(): void {
		$rows = [
			['toestellen' => '4', 'sinds' => '2021-02-30'],
			['toestellen' => 'onbekend', 'sinds' => '2021-02-01'],
		];

		$properties = ColumnTypes::properties(header: ['toestellen', 'sinds'], rows: $rows);

		$this->assertSame('string', $properties['toestellen']['type']);
		$this->assertSame('string', $properties['sinds']['type']);
		$this->assertArrayNotHasKey('format', $properties['sinds']);
	}//end testOneValueThatDoesNotFitMakesTheColumnText()

	/**
	 * The header is the title, the description starts empty, and blank or repeated headers are dropped.
	 *
	 * @return void
	 */
	public function testTheHeaderIsTheTitleAndBlankOrRepeatedHeadersAreDropped(): void {
		$properties = ColumnTypes::properties(header: [' wijk ', '', 'wijk'], rows: [['wijk' => 'Centrum']]);

		$this->assertSame(['wijk'], array_keys($properties));
		$this->assertSame('wijk', $properties['wijk']['title']);
		$this->assertSame('', $properties['wijk']['description']);
	}//end testTheHeaderIsTheTitleAndBlankOrRepeatedHeadersAreDropped()

	/**
	 * A column is facetable below fifty distinct values, and only the first two hundred rows count.
	 *
	 * @return void
	 */
	public function testFacetableBelowFiftyDistinctValuesInTheSample(): void {
		$rows = [];
		for ($i = 0; $i < 300; $i++) {
			$rows[] = ['wijk' => 'W'.($i % 3), 'id' => (string)$i, 'late' => $i < 200 ? 'a' : 'x'.$i];
		}

		$properties = ColumnTypes::properties(header: ['wijk', 'id', 'late'], rows: $rows);

		$this->assertTrue($properties['wijk']['facetable']);
		$this->assertFalse($properties['id']['facetable']);
		$this->assertTrue($properties['late']['facetable']);
		$this->assertSame('string', $properties['late']['type']);
	}//end testFacetableBelowFiftyDistinctValuesInTheSample()

	/**
	 * Non-scalar cells are ignored rather than cast.
	 *
	 * @return void
	 */
	public function testNonScalarCellsAreIgnored(): void {
		$properties = ColumnTypes::properties(header: ['n'], rows: [['n' => ['1']], ['n' => '7'], ['other' => 'x']]);

		$this->assertSame('integer', $properties['n']['type']);
	}//end testNonScalarCellsAreIgnored()
}//end class
