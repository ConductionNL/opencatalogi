<?php

/**
 * The stamp dossiq's import writes on a moved Woo request is declared, so
 * OpenRegister stores it instead of dropping it.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category  Test
 * @package   OCA\OpenCatalogi\Tests\Unit\Settings
 * @author    Conduction B.V. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 * @link      https://github.com/ConductionNL/opencatalogi
 *
 * @spec openspec/changes/woo-request-intake-hands-over-to-dossiq/specs/woo-request-intake/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenCatalogi\Tests\Unit\Settings;

use PHPUnit\Framework\TestCase;

/**
 * Reads the wooRequest schema from its register fragment.
 *
 * @coversNothing
 */
class WooRequestMigrationStampTest extends TestCase {

	/**
	 * The stamp, in the exact shape dossiq's REQ-WTO-004 step 5 writes it
	 * (dossiq openspec/changes/woo-request-takes-over-from-opencatalogi):
	 * the case uuid and the moment of the move.
	 */
	private const STAMP = [
		'migratedTo' => '7d1c0c7e-0000-4000-a000-000000000042',
		'migratedAt' => '2026-10-20T09:00:00+02:00',
	];

	/**
	 * Every key of the stamp is a declared wooRequest property of the right
	 * type and format; an undeclared key is dropped on save, and the import
	 * would then count a request as moved that never says so.
	 *
	 * @return void
	 */
	public function testTheImportStampIsStoredOnTheRequest(): void {
		$fragment = json_decode(
			(string)file_get_contents(dirname(__DIR__, 3) . '/lib/Settings/register.d/woo-request-intake-and-comment-periods.json'),
			true
		);
		$properties = $fragment['components']['schemas']['wooRequest']['properties'];

		foreach (array_keys(self::STAMP) as $key) {
			self::assertArrayHasKey($key, $properties, 'wooRequest declares ' . $key);
			self::assertSame('string', $properties[$key]['type']);
		}

		self::assertSame('uuid', $properties['migratedTo']['format']);
		self::assertSame('date-time', $properties['migratedAt']['format']);
		self::assertNotFalse(\DateTimeImmutable::createFromFormat(\DATE_ATOM, self::STAMP['migratedAt']));
		self::assertMatchesRegularExpression('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/', self::STAMP['migratedTo']);
		self::assertNotContains('migratedTo', $fragment['components']['schemas']['wooRequest']['required']);
	}//end testTheImportStampIsStoredOnTheRequest()
}//end class
