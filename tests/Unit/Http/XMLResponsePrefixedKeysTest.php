<?php

/**
 * Tests for how XMLResponse writes `@name` keys.
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

namespace Unit\Http;

use OCA\OpenCatalogi\Http\XMLResponse;
use PHPUnit\Framework\TestCase;

/**
 * A single-valued `@name` key is an attribute; a list or map under `@name` stays an element.
 *
 * @covers \OCA\OpenCatalogi\Http\XMLResponse
 */
class XMLResponsePrefixedKeysTest extends TestCase {

	/**
	 * Single values become attributes, in order, next to `@attributes`; lists stay elements.
	 *
	 * @return void
	 */
	public function testPrefixedKeysWithOneValueAreAttributes(): void {
		$xml = (new XMLResponse())->arrayToXml(
			data: [
				'@root' => 'doc',
				'@attributes' => ['xmlns' => 'urn:x'],
				'item' => ['@resource' => 'urn:r', '@n' => 3, '@flag' => true, '#text' => 'tekst'],
				'@list' => ['a' => 'b'],
			]
		);

		$this->assertSame(
			'<?xml version="1.0" encoding="UTF-8"?>' . "\n"
			. '<doc xmlns="urn:x">' . "\n"
			. '  <item resource="urn:r" n="3" flag="1">tekst</item>' . "\n"
			. '  <list>' . "\n"
			. '    <a>b</a>' . "\n"
			. '  </list>' . "\n"
			. '</doc>' . "\n",
			$xml
		);

	}//end testPrefixedKeysWithOneValueAreAttributes()
}//end class
