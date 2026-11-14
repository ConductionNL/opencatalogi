<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace Unit\Service;

use InvalidArgumentException;
use OCA\OpenCatalogi\Service\CorsOriginSetting;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The websites an administrator lists become the stored CORS allowlist (REQ-SCO-003).
 *
 * @spec openspec/changes/search-cors-on-every-public-endpoint/specs/cross-origin-api-access/spec.md#requirement-the-administrator-edits-the-list-of-allowed-websites-req-sco-003
 */
#[CoversClass(CorsOriginSetting::class)]
class CorsOriginSettingTest extends TestCase {
	/**
	 * Entered lists and the value stored for each.
	 *
	 * @return array<string, array{0: string, 1: string}>
	 */
	public static function enteredLists(): array {
		return [
			'two lines' => ["https://www.voorbeeldgemeente.nl\nhttps://andere.example:8443", 'https://www.voorbeeldgemeente.nl,https://andere.example:8443'],
			'windows line ends, blanks and spaces' => ["  https://a.example \r\n\r\n http://b.example\r\n", 'https://a.example,http://b.example'],
			'a comma separates too' => ['https://a.example, https://b.example', 'https://a.example,https://b.example'],
			'upper case is lowered' => ['HTTPS://WWW.Voorbeeldgemeente.NL', 'https://www.voorbeeldgemeente.nl'],
			'a repeat is stored once' => ["https://a.example\nhttps://a.example", 'https://a.example'],
			'a star alone' => ['*', '*'],
			'a star among others allows every website' => ["https://a.example\n*", '*'],
			'nothing entered' => ["\n  \n", '*'],
		];
	}//end enteredLists()

	/**
	 * Each entered list is stored as the comma-separated allowlist.
	 *
	 * @param string $entered What the administrator entered.
	 * @param string $stored  The value stored.
	 *
	 * @return void
	 */
	#[DataProvider('enteredLists')]
	public function testAnEnteredListIsStoredAsTheAllowlist(string $entered, string $stored): void {
		$this->assertSame($stored, CorsOriginSetting::storable(value: $entered));
	}//end testAnEnteredListIsStoredAsTheAllowlist()

	/**
	 * Lines that are not a bare origin, each refused by name.
	 *
	 * @return array<string, array{0: string}>
	 */
	public static function refusedLines(): array {
		return [
			'a path' => ['https://www.voorbeeldgemeente.nl/zoeken'],
			'a trailing slash' => ['https://www.voorbeeldgemeente.nl/'],
			'no scheme' => ['www.voorbeeldgemeente.nl'],
			'another scheme' => ['ftp://www.voorbeeldgemeente.nl'],
			'a query' => ['https://www.voorbeeldgemeente.nl?q=1'],
			'a user' => ['https://user@www.voorbeeldgemeente.nl'],
			'a wildcard host' => ['https://*.voorbeeldgemeente.nl'],
		];
	}//end refusedLines()

	/**
	 * A line that is not a bare origin refuses the whole list and names that line.
	 *
	 * @param string $line The refused line.
	 *
	 * @return void
	 */
	#[DataProvider('refusedLines')]
	public function testALineThatIsNotAnOriginIsRefusedByName(string $line): void {
		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessage('"' . $line . '" is not a website origin');

		CorsOriginSetting::storable(value: "https://a.example\n" . $line);
	}//end testALineThatIsNotAnOriginIsRefusedByName()

	/**
	 * A list or a number is refused: the field holds text.
	 *
	 * @return void
	 */
	public function testAValueThatIsNotTextIsRefused(): void {
		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessage('Setting cors_allowed_origins takes text');

		CorsOriginSetting::storable(value: 42);
	}//end testAValueThatIsNotTextIsRefused()
}//end class
