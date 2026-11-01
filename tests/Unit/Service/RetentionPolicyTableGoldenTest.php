<?php

/**
 * Golden test for RetentionPolicyTable::fromDefaults.
 *
 * Every case feeds one stored defaults map and compares the decision table
 * (or null) with the recording.
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

use OCA\OpenCatalogi\Service\RetentionPolicyTable;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Golden test for RetentionPolicyTable::fromDefaults.
 *
 * @covers \OCA\OpenCatalogi\Service\RetentionPolicyTable
 */
class RetentionPolicyTableGoldenTest extends TestCase {

	private const FIXTURE = __DIR__ . '/fixtures/retention-policy-table-golden.json';

	/**
	 * The recorded defaults maps.
	 *
	 * @return array<string, array{0: array<string|int, mixed>}>
	 */
	public static function defaults(): array {
		return [
			'empty' => [[]],
			'only non-array rows' => [['a' => 'x', 'b' => 3, 'c' => null]],
			'one full category' => [['permits' => ['termMonths' => 12, 'action' => 'depublish']]],
			'term only' => [['permits' => ['termMonths' => '7']]],
			'action only' => [['permits' => ['action' => 5]]],
			'empty rule' => [['permits' => []]],
			'null term and action' => [['permits' => ['termMonths' => null, 'action' => null]]],
			'zero term' => [['permits' => ['termMonths' => 0, 'action' => '']]],
			'fallback only' => [['_fallback' => ['termMonths' => 6, 'action' => 'review']]],
			'fallback first' => [['_fallback' => ['termMonths' => 6], 'a' => ['termMonths' => 1], 'b' => ['action' => 'keep']]],
			'fallback middle' => [['a' => ['termMonths' => 1], '_fallback' => ['action' => 'review'], 'b' => ['termMonths' => 2]]],
			'fallback not an array' => [['_fallback' => 'x', 'a' => ['termMonths' => 1]]],
			'quote in category' => [['say "hi"' => ['termMonths' => 3]]],
			'dash and operator categories' => [['-' => ['termMonths' => 1], '>= 5' => ['termMonths' => 2], '' => ['action' => 'x']]],
			'integer keys' => [[0 => ['termMonths' => 1], 7 => ['action' => 'y']]],
			'float term' => [['a' => ['termMonths' => 2.9]]],
			'extra keys ignored' => [['a' => ['termMonths' => 4, 'action' => 'z', 'other' => 1]]],
		];

	}//end defaults()

	/**
	 * Build the table and compare with the recording.
	 *
	 * @param array<string|int, mixed> $catalogDefaults The stored defaults.
	 *
	 * @return void
	 */
	#[DataProvider('defaults')]
	public function testFromDefaultsMatchesTheRecording(array $catalogDefaults): void {
		$actual = ['table' => (new RetentionPolicyTable())->fromDefaults(catalogDefaults: $catalogDefaults)];

		$recorded = [];
		if (file_exists(self::FIXTURE) === true) {
			$recorded = json_decode((string)file_get_contents(self::FIXTURE), true);
		}

		$name = (string)$this->dataName();
		if (getenv('GOLDEN_RECORD') === '1') {
			$recorded[$name] = $actual;
			file_put_contents(self::FIXTURE, json_encode($recorded, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
		}

		$this->assertArrayHasKey(key: $name, array: $recorded, message: 'No recording for ' . $name);
		$this->assertSame(expected: $recorded[$name], actual: $actual);

	}//end testFromDefaultsMatchesTheRecording()
}//end class
