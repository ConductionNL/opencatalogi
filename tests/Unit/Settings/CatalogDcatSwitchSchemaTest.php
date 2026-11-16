<?php

/**
 * The catalog schema declares the per-catalogue DCAT switch
 * (dcat-catalogue-completion 6.1).
 *
 * @category Test
 * @package  OCA\OpenCatalogi\Tests\Unit\Settings
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/dcat-catalogue-completion
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenCatalogi\Tests\Unit\Settings;

use PHPUnit\Framework\TestCase;

/**
 * Without the declaration the schema's hard validation strips `hasDcat` on
 * save, and the feed that DcatService gates on it can never be switched on.
 *
 * @coversNothing
 */
class CatalogDcatSwitchSchemaTest extends TestCase {

	/**
	 * The catalog schema.
	 *
	 * @return array<string, mixed>
	 */
	private static function catalog(): array {
		$register = json_decode((string)file_get_contents(__DIR__ . '/../../../lib/Settings/publication_register.json'), true, 512, JSON_THROW_ON_ERROR);

		return $register['components']['schemas']['catalog'];
	}//end catalog()

	/**
	 * `hasDcat` is a boolean that defaults to off, beside `hasOoapi`.
	 *
	 * @return void
	 */
	public function testHasDcatIsDeclaredAndOffByDefault(): void {
		$catalog = self::catalog();

		$this->assertSame(
			['type' => 'boolean', 'default' => false, 'title' => 'Has DCAT feed'],
			array_intersect_key($catalog['properties']['hasDcat'], ['type' => 1, 'default' => 1, 'title' => 1])
		);
		$this->assertArrayHasKey('hasOoapi', $catalog['properties']);
	}//end testHasDcatIsDeclaredAndOffByDefault()

	/**
	 * The modal sends the switch with the catalogue, defaulting to off.
	 *
	 * @return void
	 */
	public function testTheCatalogModalCarriesTheSwitch(): void {
		$modal = (string)file_get_contents(__DIR__ . '/../../../src/modals/catalog/CatalogModal.vue');

		$this->assertStringContainsString('v-model="catalogi.hasDcat"', $modal);
		$this->assertStringContainsString("hasDcat: false,", $modal);
	}//end testTheCatalogModalCarriesTheSwitch()
}//end class
