<?php

/**
 * Unit tests for RedactedValues.
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
 * @spec openspec/changes/published-file-carries-its-facts/tasks.md#task-2.2b
 */

declare(strict_types=1);

namespace Unit\Service\Publication;

use OCA\OpenCatalogi\Service\Publication\RedactedValues;
use PHPUnit\Framework\TestCase;

/**
 * Tests for RedactedValues.
 *
 * @covers \OCA\OpenCatalogi\Service\Publication\RedactedValues
 */
class RedactedValuesTest extends TestCase {

	/**
	 * The distinct non-empty values of the log, and the stored output mode.
	 *
	 * @return void
	 */
	public function testTheValuesComeFromTheLog(): void {
		$redacted = new RedactedValues(
			redactionLog: [['value' => 'Jan Jansen'], ['value' => 'Jan Jansen'], ['value' => '0612345678'], ['value' => ''], ['value' => 7], 'noise'],
			verification: ['verdict' => 'clean', 'outputMode' => 'pdf']
		);

		$this->assertSame(expected: ['Jan Jansen', '0612345678'], actual: $redacted->values());
		$this->assertSame(expected: 'pdf', actual: $redacted->outputMode());
	}//end testTheValuesComeFromTheLog()

	/**
	 * Without a log or a readable output mode there is nothing, and an empty mode.
	 *
	 * @return void
	 */
	public function testAnUnreadableLogAndModeAreEmpty(): void {
		$this->assertSame(expected: [], actual: (new RedactedValues(redactionLog: null, verification: null))->values());
		$this->assertSame(expected: '', actual: (new RedactedValues(redactionLog: null, verification: null))->outputMode());
		$this->assertSame(expected: '', actual: (new RedactedValues(redactionLog: [], verification: ['outputMode' => ['pdf']]))->outputMode());
	}//end testAnUnreadableLogAndModeAreEmpty()

	/**
	 * Serialised or dumped, it says how many values there are, never which.
	 *
	 * @return void
	 */
	public function testTheValuesNeverShowWhenSerialisedOrDumped(): void {
		$redacted = new RedactedValues(redactionLog: [['value' => 'Jan Jansen']], verification: ['outputMode' => 'pdf']);

		$this->assertSame(expected: '{"values":1,"outputMode":"pdf"}', actual: json_encode($redacted));
		$this->assertStringNotContainsString(needle: 'Jan Jansen', haystack: print_r($redacted, true));
		$this->assertSame(expected: ['values' => 1, 'outputMode' => 'pdf'], actual: $redacted->__debugInfo());
	}//end testTheValuesNeverShowWhenSerialisedOrDumped()
}//end class
