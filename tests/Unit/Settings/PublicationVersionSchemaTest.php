<?php

/**
 * PublicationVersionSchemaTest
 *
 * An earlier public version of a corrected publication is kept as a
 * publicationVersion object, which is never readable through the public API
 * on its own (REQ-PWA-001).
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
 * @spec openspec/changes/publication-withdrawal-aftercare/tasks.md#task-1.1
 */

declare(strict_types=1);

namespace OCA\OpenCatalogi\Tests\Unit\Settings;

use OCA\OpenCatalogi\Service\SettingsService;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * @covers \OCA\OpenCatalogi\Service\SettingsService
 */
final class PublicationVersionSchemaTest extends TestCase {

	/**
	 * The register document as the app imports it: the base merged with every fragment.
	 *
	 * @return array<string, mixed>
	 */
	private static function components(): array {
		$root = dirname(path: __DIR__, levels: 3) . '/lib/Settings';
		$base = json_decode(
			json: (string) file_get_contents(filename: $root . '/publication_register.json'),
			associative: true,
			flags: JSON_THROW_ON_ERROR
		);
		$merge = new ReflectionMethod(objectOrMethod: SettingsService::class, method: 'mergeRegisterFragments');

		return $merge->invoke(null, $base, $root . '/register.d')['components'];
	}//end components()

	/**
	 * The schema has its slug, a version, the fields of the spec, and no rule that lets an anonymous or any signed-in reader read it.
	 *
	 * @return void
	 */
	public function testTheFragmentHasASlugAndNoPublicReadRule(): void {
		$components = self::components();
		$schema = $components['schemas']['publicationVersion'];

		self::assertSame(expected: 'publicationVersion', actual: $schema['slug']);
		self::assertMatchesRegularExpression(pattern: '/^\d+\.\d+\.\d+$/', string: $schema['version']);
		self::assertSame(expected: 'uuid', actual: $schema['properties']['publication']['format']);
		self::assertSame(expected: 'integer', actual: $schema['properties']['version']['type']);
		self::assertSame(expected: 1, actual: $schema['properties']['version']['minimum']);
		self::assertSame(expected: 'date-time', actual: $schema['properties']['supersededAt']['format']);
		self::assertSame(expected: 'object', actual: $schema['properties']['fields']['type']);
		self::assertSame(
			expected: ['title', 'format', 'fileId'],
			actual: array_keys($schema['properties']['documents']['items']['properties'])
		);

		$read = $schema['authorization']['read'];
		self::assertNotContains(needle: 'public', haystack: $read);
		self::assertNotContains(needle: 'authenticated', haystack: $read);
		self::assertSame(expected: [], actual: $schema['authorization']['update'], message: 'A version is never edited.');

		self::assertContains(needle: 'publicationVersion', haystack: $components['registers']['publication']['schemas']);
	}//end testTheFragmentHasASlugAndNoPublicReadRule()
}//end class
