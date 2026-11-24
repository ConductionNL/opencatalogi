<?php

/**
 * The catalogue schema declares the licence its DCAT feed carries.
 *
 * @category Test
 * @package  OCA\OpenCatalogi\Tests\Unit\Settings
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/dcat-catalogue-completion/tasks.md#task-1.4
 */

declare(strict_types=1);

namespace OCA\OpenCatalogi\Tests\Unit\Settings;

use PHPUnit\Framework\TestCase;

/**
 * Without the declaration the catalog schema's hard validation strips
 * `dcatLicense` on save, so the licence picked on the catalogue form would
 * never reach the feed.
 *
 * @coversNothing
 *
 * @spec openspec/changes/dcat-catalogue-completion/tasks.md#task-1.4
 */
class CatalogLicenceSchemaTest extends TestCase {

	/**
	 * A schema of the publication register file or one of its fragments.
	 *
	 * @param string $file The file under lib/Settings.
	 *
	 * @return array<string, mixed>
	 */
	private static function catalog(string $file): array {
		$register = json_decode(
			json: (string)file_get_contents(filename: __DIR__ . '/../../../lib/Settings/' . $file),
			associative: true,
			depth: 512,
			flags: JSON_THROW_ON_ERROR
		);

		return $register['components']['schemas']['catalog'];
	}//end catalog()

	/**
	 * `dcatLicense` is a string with a title, on a schema whose hard validation would strip it otherwise.
	 *
	 * @spec openspec/changes/dcat-catalogue-completion/tasks.md#task-1.4
	 *
	 * @return void
	 */
	public function testDcatLicenceIsDeclaredOnTheCatalogue(): void {
		$catalog = self::catalog(file: 'publication_register.json');

		$this->assertTrue(condition: $catalog['hardValidation']);
		$this->assertSame(
			expected: ['type' => 'string', 'title' => 'Licence'],
			actual: array_intersect_key($catalog['properties']['dcatLicense'], ['type' => 1, 'title' => 1])
		);
		$this->assertArrayNotHasKey(key: 'enum', array: $catalog['properties']['dcatLicense'], message: 'the list is held by LicenceCheck, so a stored legacy value never blocks a save');
	}//end testDcatLicenceIsDeclaredOnTheCatalogue()

	/**
	 * The base and the last catalogue fragment carry the same bumped version, so the merged schema moves.
	 *
	 * @spec openspec/changes/dcat-catalogue-completion/tasks.md#task-1.4
	 *
	 * @return void
	 */
	public function testTheCatalogueVersionMoved(): void {
		$this->assertSame(expected: '0.6.1', actual: self::catalog(file: 'publication_register.json')['version']);
		$this->assertSame(expected: '0.6.1', actual: self::catalog(file: 'register.d/woo-publication-translations.json')['version']);
	}//end testTheCatalogueVersionMoved()

	/**
	 * The catalogue modal offers the picker once the DCAT feed is on, and sends an empty licence by default.
	 *
	 * @spec openspec/changes/dcat-catalogue-completion/tasks.md#task-1.4
	 *
	 * @return void
	 */
	public function testTheCatalogModalCarriesThePicker(): void {
		$modal = (string)file_get_contents(filename: __DIR__ . '/../../../src/modals/catalog/CatalogModal.vue');

		$this->assertStringContainsString(needle: 'v-model="catalogi.dcatLicense"', haystack: $modal);
		$this->assertStringContainsString(needle: "dcatLicense: '',", haystack: $modal);
	}//end testTheCatalogModalCarriesThePicker()
}//end class
