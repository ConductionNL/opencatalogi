<?php

/**
 * ThemeHotspotSchemaTest
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Test
 * @package  OCA\OpenCatalogi\Tests\Unit\Settings
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenCatalogi.nl
 *
 * @spec openspec/changes/theme-archive-hotspot/tasks.md#task-1.1
 */

declare(strict_types=1);

namespace OCA\OpenCatalogi\Tests\Unit\Settings;

use OCA\OpenCatalogi\Service\SettingsService;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * The subject carries the archive hotspot marking (REQ-THA-001).
 *
 * @covers \OCA\OpenCatalogi\Service\SettingsService
 */
final class ThemeHotspotSchemaTest extends TestCase {

	/**
	 * Merged with every fragment, the theme declares the three fields at a bumped version, the moment read-only.
	 *
	 * @return void
	 */
	public function testTheThemeDeclaresTheHotspotFields(): void {
		$root = dirname(path: __DIR__, levels: 3) . '/lib/Settings';
		$base = json_decode(
			json: (string) file_get_contents(filename: $root . '/publication_register.json'),
			associative: true,
			flags: JSON_THROW_ON_ERROR
		);
		$merge = new ReflectionMethod(objectOrMethod: SettingsService::class, method: 'mergeRegisterFragments');
		$theme = $merge->invoke(null, $base, $root . '/register.d')['components']['schemas']['theme'];

		self::assertTrue(condition: version_compare(version1: $theme['version'], version2: '0.0.5', operator: '>'), message: $theme['version']);
		self::assertSame(expected: 'boolean', actual: $theme['properties']['archiveHotspot']['type']);
		self::assertFalse(condition: $theme['properties']['archiveHotspot']['default']);
		self::assertSame(expected: 'string', actual: $theme['properties']['archiveHotspotReason']['type']);
		self::assertSame(expected: 'date-time', actual: $theme['properties']['archiveHotspotSince']['format']);
		self::assertTrue(condition: $theme['properties']['archiveHotspotSince']['readOnly']);
		self::assertSame(expected: 'title', actual: array_key_first($theme['properties']));
	}//end testTheThemeDeclaresTheHotspotFields()

	/**
	 * Every locale catalogue translates the fields' titles and descriptions.
	 *
	 * @return void
	 */
	public function testEveryLocaleTranslatesTheLabels(): void {
		$fragment = json_decode(
			json: (string) file_get_contents(filename: dirname(path: __DIR__, levels: 3) . '/lib/Settings/register.d/theme-archive-hotspot.json'),
			associative: true,
			flags: JSON_THROW_ON_ERROR
		);
		$strings = [];
		foreach ($fragment['components']['schemas']['theme']['properties'] as $property) {
			array_push($strings, $property['title'], $property['description']);
		}

		foreach (glob(pattern: dirname(path: __DIR__, levels: 3) . '/l10n/[a-z]*.json') as $catalogue) {
			$translations = json_decode(json: (string) file_get_contents(filename: $catalogue), associative: true, flags: JSON_THROW_ON_ERROR)['translations'];
			foreach ($strings as $string) {
				self::assertArrayHasKey(key: $string, array: $translations, message: basename(path: $catalogue));
			}
		}
	}//end testEveryLocaleTranslatesTheLabels()
}//end class
