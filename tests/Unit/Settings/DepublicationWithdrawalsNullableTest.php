<?php

/**
 * An outstanding withdrawal can be stored.
 *
 * @category Test
 * @package  OCA\OpenCatalogi\Tests
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/specs/publication-inspection-and-the-national-indexes/spec.md#requirement-something-published-in-error-is-depublished-with-one-action-req-pin-106
 */

declare(strict_types=1);

namespace OCA\OpenCatalogi\Tests\Unit\Settings;

use OCA\OpenCatalogi\Service\SettingsService;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * DepublicationService writes every withdrawal with `acknowledgedAt` and `answer` null until the channel answers.
 * Declared as plain strings, OpenRegister refused that save ("withdrawals.0.acknowledgedAt should be type 'string'
 * but is 'null'"), so no depublication could be stored. Both are nullable; OpenRegister's import refuses a type
 * list, so the schema says so with `nullable: true`.
 *
 * @coversNothing
 *
 * @spec openspec/specs/publication-inspection-and-the-national-indexes/spec.md#requirement-something-published-in-error-is-depublished-with-one-action-req-pin-106
 */
final class DepublicationWithdrawalsNullableTest extends TestCase {

	/**
	 * The depublication schema as it is imported.
	 *
	 * @return array<string, mixed>
	 */
	private static function depublication(): array {
		$root = dirname(__DIR__, 3) . '/lib/Settings';
		$base = json_decode((string)file_get_contents($root . '/publication_register.json'), true, flags: JSON_THROW_ON_ERROR);
		$merge = new ReflectionMethod(SettingsService::class, 'mergeRegisterFragments');

		return $merge->invoke(null, $base, $root . '/register.d')['components']['schemas']['depublication'];
	}//end depublication()

	/**
	 * Not yet acknowledged and not yet answered are storable.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/publication-inspection-and-the-national-indexes/spec.md#requirement-something-published-in-error-is-depublished-with-one-action-req-pin-106
	 */
	public function testAnOutstandingWithdrawalIsStorable(): void {
		$depublication = self::depublication();
		$withdrawal = $depublication['properties']['withdrawals']['items']['properties'];

		foreach (['acknowledgedAt', 'answer'] as $field) {
			self::assertSame(expected: 'string', actual: $withdrawal[$field]['type'], message: $field);
			self::assertTrue(condition: $withdrawal[$field]['nullable'] ?? false, message: $field);
		}

		self::assertTrue(condition: version_compare($depublication['version'], '0.2.5', '>'), message: $depublication['version']);
	}//end testAnOutstandingWithdrawalIsStorable()
}//end class
