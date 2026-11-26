<?php

/**
 * PublicationLicenceSchemaTest
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
 * @spec openspec/changes/dcat-catalogue-completion/tasks.md#task-1.4
 */

declare(strict_types=1);

namespace OCA\OpenCatalogi\Tests\Unit\Settings;

use OCA\OpenCatalogi\Listener\LicenceCheck;
use OCA\OpenCatalogi\Service\SettingsService;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * The publication declares its own licence as a field from the EU licences list (REQ-DCC-001, task 1.4).
 *
 * @coversNothing
 */
final class PublicationLicenceSchemaTest extends TestCase {

	/**
	 * The publication schema as it is imported: the base register with every register.d fragment merged.
	 *
	 * @return array<string, mixed>
	 */
	private static function publication(): array {
		$root = dirname(__DIR__, 3) . '/lib/Settings';
		$base = json_decode((string)file_get_contents($root . '/publication_register.json'), true, flags: JSON_THROW_ON_ERROR);
		$merge = new ReflectionMethod(SettingsService::class, 'mergeRegisterFragments');

		return $merge->invoke(null, $base, $root . '/register.d')['components']['schemas']['publication'];
	}//end publication()

	/**
	 * The property LicenceCheck guards is declared, optional, a URI, and names the licence list the form offers.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/dcat-catalogue-completion/specs/dcat-ap-harvest/spec.md#requirement-the-licence-comes-from-a-controlled-list-bind-or-omit-req-dcc-001
	 */
	public function testThePublicationDeclaresItsLicenceFromTheList(): void {
		$publication = self::publication();
		$property = $publication['properties'][LicenceCheck::FIELDS['publication_schema']];

		self::assertSame(expected: 'string', actual: $property['type']);
		self::assertSame(expected: 'uri', actual: $property['format']);
		self::assertSame(expected: 'licence', actual: $property['x-value-list']);
		self::assertNotContains(needle: 'license', haystack: $publication['required']);
	}//end testThePublicationDeclaresItsLicenceFromTheList()

	/**
	 * The schema version moved past 0.0.18, so an instance that has the schema imports the new field.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/dcat-catalogue-completion/tasks.md#task-1.4
	 */
	public function testTheVersionMovedPastTheOneWithoutTheField(): void {
		$version = self::publication()['version'];

		self::assertTrue(condition: version_compare($version, '0.0.18', '>'), message: $version);
	}//end testTheVersionMovedPastTheOneWithoutTheField()
}//end class
