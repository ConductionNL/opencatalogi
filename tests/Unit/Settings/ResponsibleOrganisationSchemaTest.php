<?php
/**
 * ResponsibleOrganisationSchemaTest
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
 * @spec openspec/changes/publications-name-their-responsible-organisation/tasks.md#task-1.1
 */

declare(strict_types=1);

namespace OCA\OpenCatalogi\Tests\Unit\Settings;

use OCA\OpenCatalogi\Service\SettingsService;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * The publication names its responsible organisation apart from its publisher (REQ-PRO-001).
 *
 * @covers \OCA\OpenCatalogi\Service\SettingsService
 */
final class ResponsibleOrganisationSchemaTest extends TestCase {

	/**
	 * The fragment's `responsibleOrganization` is an optional reference to OpenRegister's shared organisation.
	 *
	 * @return void
	 */
	public function testTheReferencePointsAtNcOrganisation(): void {
		$fragment = json_decode(
			json: (string) file_get_contents(filename: self::settings() . '/register.d/publications-name-their-responsible-organisation.json'),
			associative: true,
			flags: JSON_THROW_ON_ERROR
		);
		$publication = $fragment['components']['schemas']['publication'];
		$property    = $publication['properties']['responsibleOrganization'];

		self::assertSame(expected: 'publication', actual: $publication['slug']);
		self::assertSame(expected: ['responsibleOrganization'], actual: array_keys($publication['properties']));
		self::assertSame(expected: 'string', actual: $property['type']);
		self::assertSame(expected: 'uuid', actual: $property['format']);
		self::assertSame(expected: 'nc-organisation', actual: $property['$ref']);
		self::assertSame(expected: 'Responsible organisation', actual: $property['title']);
		self::assertNotSame(expected: '', actual: trim(string: (string) ($property['description'] ?? '')));
		self::assertNotContains(needle: 'responsibleOrganization', haystack: (array) ($publication['required'] ?? []));
	}//end testTheReferencePointsAtNcOrganisation()

	/**
	 * Merged with every fragment, the publication keeps the publisher and gains the property at a bumped version.
	 *
	 * @return void
	 */
	public function testTheMergedSchemaCarriesItBesideThePublisher(): void {
		$root = self::settings();
		$base = json_decode(
			json: (string) file_get_contents(filename: $root . '/publication_register.json'),
			associative: true,
			flags: JSON_THROW_ON_ERROR
		);

		$merge       = new ReflectionMethod(SettingsService::class, 'mergeRegisterFragments');
		$merged      = $merge->invoke(null, $base, $root . '/register.d');
		$publication = $merged['components']['schemas']['publication'];

		self::assertTrue(
			condition: version_compare(version1: $publication['version'], version2: '0.0.12', operator: '>='),
			message: $publication['version']
		);
		self::assertSame(expected: 'nc-organisation', actual: $publication['properties']['organization']['$ref']);
		self::assertSame(expected: 'nc-organisation', actual: $publication['properties']['responsibleOrganization']['$ref']);
	}//end testTheMergedSchemaCarriesItBesideThePublisher()

	/**
	 * Every locale catalogue translates the property's title and description.
	 *
	 * @return void
	 */
	public function testEveryLocaleTranslatesTheLabels(): void {
		$catalogues = glob(pattern: dirname(path: __DIR__, levels: 3) . '/l10n/[a-z]*.json');
		self::assertNotEmpty(actual: $catalogues);

		foreach ($catalogues as $catalogue) {
			$translations = json_decode(
				json: (string) file_get_contents(filename: $catalogue),
				associative: true,
				flags: JSON_THROW_ON_ERROR
			)['translations'];
			self::assertArrayHasKey(key: 'Responsible organisation', array: $translations, message: basename(path: $catalogue));
			self::assertArrayHasKey(
				key: 'The organisation responsible for this publication, when it is not the publisher.',
				array: $translations,
				message: basename(path: $catalogue)
			);
		}
	}//end testEveryLocaleTranslatesTheLabels()

	/**
	 * The app's settings directory.
	 *
	 * @return string The path.
	 */
	private static function settings(): string {
		return dirname(path: __DIR__, levels: 3) . '/lib/Settings';
	}//end settings()
}//end class
