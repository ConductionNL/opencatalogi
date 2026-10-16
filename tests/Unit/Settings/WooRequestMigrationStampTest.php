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
	 * Every key of the stamp is a declared, optional wooRequest property of the
	 * right type and format, in the bumped schema version; an undeclared key is
	 * dropped on save, and the import would then count a request as moved that
	 * never says so.
	 *
	 * This reads the register fragment. It does not save the stamp: that needs
	 * OpenRegister, which this unit suite does not have.
	 *
	 * @return void
	 */
	public function testTheImportStampIsDeclaredOnTheRequest(): void {
		$fragment = json_decode(
			(string)file_get_contents(dirname(__DIR__, 3) . '/lib/Settings/register.d/woo-request-intake-and-comment-periods.json'),
			true
		);
		$schema = $fragment['components']['schemas']['wooRequest'];
		$properties = $schema['properties'];

		self::assertSame('0.2.0', $schema['version'], 'the version is bumped with the two keys');

		foreach (array_keys(self::STAMP) as $key) {
			self::assertArrayHasKey($key, $properties, 'wooRequest declares ' . $key);
			self::assertSame('string', $properties[$key]['type']);
		}

		self::assertSame('uuid', $properties['migratedTo']['format']);
		self::assertSame('date-time', $properties['migratedAt']['format']);
		foreach (array_keys(self::STAMP) as $key) {
			self::assertNotContains($key, $schema['required'], 'a request without the stamp stays valid');
		}
	}//end testTheImportStampIsDeclaredOnTheRequest()
}//end class
