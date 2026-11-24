<?php

/**
 * The id selection of a paginated search, in the shape the installed OpenRegister takes.
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
 * @spec openspec/changes/oai-pmh-endpoint/specs/oai-pmh-endpoint/spec.md#requirement-a-withdrawn-record-is-reported-not-shown-oai-007
 */

declare(strict_types=1);

namespace Unit\Service;

use OCA\OpenCatalogi\Service\SearchSelection;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\OpenCatalogi\Service\SearchSelection
 */
final class SearchSelectionTest extends TestCase {

	/**
	 * An OpenRegister with search options gets the ids inside them.
	 *
	 * @return void
	 */
	public function testANewerOpenRegisterGetsTheIdsInsideItsOptions(): void {
		$args = SearchSelection::byIds(
			ids: ['a', 'b'],
			optionsClass: SearchSelectionOptionsDouble::class,
			selectionClass: SearchSelectionIdsDouble::class
		);

		$this->assertSame(expected: ['options'], actual: array_keys($args));
		$this->assertInstanceOf(expected: SearchSelectionOptionsDouble::class, actual: $args['options']);
		$this->assertSame(expected: ['a', 'b'], actual: $args['options']->selection->ids);
	}//end testANewerOpenRegisterGetsTheIdsInsideItsOptions()

	/**
	 * A released OpenRegister, without search options, gets the ids as their own argument.
	 *
	 * @return void
	 */
	public function testAReleasedOpenRegisterGetsTheIdsAsTheirOwnArgument(): void {
		$args = SearchSelection::byIds(ids: ['a'], optionsClass: 'OCA\OpenRegister\Contract\NoSuchOptions');

		$this->assertSame(expected: ['ids' => ['a']], actual: $args);
	}//end testAReleasedOpenRegisterGetsTheIdsAsTheirOwnArgument()
}//end class

/**
 * Stands in for OpenRegister's SearchOptions.
 */
final class SearchSelectionOptionsDouble {

	/**
	 * The options with a selection.
	 *
	 * @param SearchSelectionIdsDouble $selection The id selection.
	 */
	public function __construct(public readonly SearchSelectionIdsDouble $selection) {
	}//end __construct()
}//end class

/**
 * Stands in for OpenRegister's ObjectSelection.
 */
final class SearchSelectionIdsDouble {

	/**
	 * The ids.
	 *
	 * @param array<int, string>|null $ids The ids.
	 */
	public function __construct(public readonly ?array $ids = null) {
	}//end __construct()
}//end class
