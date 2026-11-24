<?php

/**
 * WithheldEntryReaderTest
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Test
 * @package  OCA\OpenCatalogi\Tests\Unit\Service\Withheld
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenCatalogi.nl
 *
 * @spec openspec/changes/woo-decision-shows-what-was-withheld/tasks.md#task-2.1
 */

declare(strict_types=1);

namespace OCA\OpenCatalogi\Tests\Unit\Service\Withheld;

use OCA\OpenCatalogi\Service\Withheld\WithheldEntryReader;
use PHPUnit\Framework\TestCase;

/**
 * An entry is read down to exactly its position and its text ground codes (REQ-WDW-002).
 *
 * @covers \OCA\OpenCatalogi\Service\Withheld\WithheldEntryReader
 */
final class WithheldEntryReaderTest extends TestCase {

	/**
	 * Every key but the position and the grounds is dropped, a title first of all.
	 *
	 * @return void
	 */
	public function testOnlyThePositionAndTheGroundsAreRead(): void {
		self::assertSame(
			expected: ['position' => 3, 'grounds' => ['5.1.2.e', '5.1.1.c']],
			actual: (new WithheldEntryReader())->reduce(
				entry: ['position' => '3', 'grounds' => [' 5.1.2.e ', '', 7, '5.1.1.c'], 'title' => 'Advies', 'source' => 'dossiq']
			)
		);
	}//end testOnlyThePositionAndTheGroundsAreRead()

	/**
	 * Something that is not an entry, or an entry without a numeric position, reads as position 0.
	 *
	 * @return void
	 */
	public function testANonEntryReadsAsPositionZeroWithoutGrounds(): void {
		$reader = new WithheldEntryReader();

		self::assertSame(expected: ['position' => 0, 'grounds' => []], actual: $reader->reduce(entry: 'not an entry'));
		self::assertSame(expected: ['position' => 0, 'grounds' => ['5.1.2.e']], actual: $reader->reduce(entry: ['position' => 'eerste', 'grounds' => '5.1.2.e']));
		self::assertSame(expected: ['position' => 0, 'grounds' => []], actual: $reader->reduce(entry: []));
	}//end testANonEntryReadsAsPositionZeroWithoutGrounds()
}//end class
