<?php

/**
 * The publication-tells-its-source fragment adds its five properties to the
 * publication schema, and the merged schema carries the bumped version.
 *
 * @category Test
 * @package  OCA\OpenCatalogi\Tests\Unit\Settings
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
 * @spec openspec/changes/publication-tells-its-source/specs/publications/spec.md#requirement-a-publication-records-whether-it-is-complete-and-public-req-pts-002
 */

declare(strict_types=1);

namespace Unit\Settings;

use OCA\OpenCatalogi\Service\SettingsService;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * @covers \OCA\OpenCatalogi\Service\SettingsService
 */
final class PublicationTellsItsSourceSchemaTest extends TestCase {

	/**
	 * The fragment declares the five properties on the publication schema, under its slug.
	 *
	 * @return void
	 */
	public function testTheFragmentDeclaresTheFiveProperties(): void {
		$fragment = json_decode(
			(string)file_get_contents(dirname(__DIR__, 3) . '/lib/Settings/register.d/publication-tells-its-source.json'),
			true,
			flags: JSON_THROW_ON_ERROR
		);
		$publication = $fragment['components']['schemas']['publication'];

		self::assertSame('publication', $publication['slug']);
		self::assertSame(['publicUrl', 'officerUrl', 'completeness', 'completeAt', 'expectedDocuments'], array_keys($publication['properties']));
		self::assertSame(['incomplete', 'complete'], $publication['properties']['completeness']['enum']);
		self::assertSame('incomplete', $publication['properties']['completeness']['default']);
		self::assertSame(1, $publication['properties']['expectedDocuments']['minimum']);
		foreach (['publicUrl', 'officerUrl', 'completeness', 'completeAt'] as $readOnly) {
			self::assertTrue($publication['properties'][$readOnly]['readOnly'], $readOnly);
		}
	}//end testTheFragmentDeclaresTheFiveProperties()

	/**
	 * Merged with every fragment, the publication schema holds the properties and the bumped version.
	 *
	 * @return void
	 */
	public function testTheMergedSchemaCarriesThePropertiesAndTheBump(): void {
		$root = dirname(__DIR__, 3) . '/lib/Settings';
		$base = json_decode((string)file_get_contents($root . '/publication_register.json'), true, flags: JSON_THROW_ON_ERROR);

		$merge  = new ReflectionMethod(SettingsService::class, 'mergeRegisterFragments');
		$merged = $merge->invoke(null, $base, $root . '/register.d');
		$publication = $merged['components']['schemas']['publication'];

		self::assertSame('0.0.8', $publication['version']);
		foreach (['publicUrl', 'officerUrl', 'completeness', 'completeAt', 'expectedDocuments', 'wooCategory', 'publicationKind'] as $property) {
			self::assertArrayHasKey($property, $publication['properties'], $property);
		}
	}//end testTheMergedSchemaCarriesThePropertiesAndTheBump()
}//end class
