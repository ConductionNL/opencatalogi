<?php

/**
 * PublicationArchiveDeclarationTest
 *
 * The publication schema offers OpenRegister's archive and freeze, so a
 * withdrawn publication can be frozen (REQ-PWA-003).
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
 * @spec openspec/changes/publication-withdrawal-aftercare/tasks.md#task-3.1
 */

declare(strict_types=1);

namespace OCA\OpenCatalogi\Tests\Unit\Settings;

use OCA\OpenCatalogi\Service\SettingsService;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * @covers \OCA\OpenCatalogi\Service\SettingsService
 */
final class PublicationArchiveDeclarationTest extends TestCase {

	/**
	 * The schemas as the app imports them: the base merged with every fragment.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private static function schemas(): array {
		$root = dirname(path: __DIR__, levels: 3) . '/lib/Settings';
		$base = json_decode(
			json: (string) file_get_contents(filename: $root . '/publication_register.json'),
			associative: true,
			flags: JSON_THROW_ON_ERROR
		);
		$merge = new ReflectionMethod(objectOrMethod: SettingsService::class, method: 'mergeRegisterFragments');

		return $merge->invoke(null, $base, $root . '/register.d')['components']['schemas'];
	}//end schemas()

	/**
	 * The merged publication schema declares archiving, at a bumped version, and OpenRegister reads it as offered.
	 *
	 * @return void
	 */
	public function testThePublicationSchemaOffersArchiving(): void {
		$publication = self::schemas()['publication'];

		self::assertSame(expected: ['enabled' => true], actual: $publication['configuration']['x-openregister-archive']);
		self::assertTrue(
			condition: version_compare(version1: $publication['version'], version2: '0.0.13', operator: '>'),
			message: $publication['version']
		);

		if (class_exists('OCA\OpenRegister\Db\Schema') === false
			|| method_exists('OCA\OpenRegister\Db\Schema', 'isArchivingEnabled') === false
		) {
			self::markTestSkipped(message: 'OpenRegister with archiving is not installed beside this app.');
		}

		$schema = new \OCA\OpenRegister\Db\Schema();
		$schema->hydrate(object: $publication);
		self::assertTrue(condition: $schema->isArchivingEnabled());
	}//end testThePublicationSchemaOffersArchiving()

	/**
	 * The depublication records whether the freeze worked, at a bumped version.
	 *
	 * @return void
	 */
	public function testTheDepublicationRecordsTheFreeze(): void {
		$depublication = self::schemas()['depublication'];

		self::assertSame(expected: 'boolean', actual: $depublication['properties']['frozen']['type']);
		self::assertSame(expected: 'string', actual: $depublication['properties']['frozenError']['type']);
		self::assertTrue(
			condition: version_compare(version1: $depublication['version'], version2: '0.2.4', operator: '>'),
			message: $depublication['version']
		);
	}//end testTheDepublicationRecordsTheFreeze()
}//end class
