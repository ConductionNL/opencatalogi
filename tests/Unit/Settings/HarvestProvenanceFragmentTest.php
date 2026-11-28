<?php

/**
 * The harvest provenance fragment puts `source` and `derivedFrom` on the publication.
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
 * @version GIT: <git_id>
 *
 * @link https://www.OpenCatalogi.nl
 *
 * @spec openspec/changes/harvest-feed-intake/specs/harvest-feed-intake/spec.md#requirement-a-harvested-publication-names-its-source-req-hfi-005
 */

declare(strict_types=1);

namespace OCA\OpenCatalogi\Tests\Unit\Settings;

use OCA\OpenCatalogi\Service\Harvest\HarvestFeedService;
use OCA\OpenCatalogi\Service\SettingsService;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * The fragment declares both properties with titles, and the merged schema
 * carries them under the bumped version.
 */
final class HarvestProvenanceFragmentTest extends TestCase {

	/**
	 * The fragment declares `source` (an address) and `derivedFrom`, each with a title.
	 *
	 * @return void
	 */
	public function testTheFragmentDeclaresSourceAndDerivedFrom(): void {
		$fragment = json_decode(
			json: (string) file_get_contents(dirname(__DIR__, 3).'/lib/Settings/register.d/harvest-provenance.json'),
			associative: true,
			flags: JSON_THROW_ON_ERROR
		);
		$publication = $fragment['components']['schemas']['publication'];

		self::assertSame(expected: 'publication', actual: $publication['slug']);
		self::assertSame(expected: ['source', 'derivedFrom'], actual: array_keys($publication['properties']));
		self::assertSame(expected: 'uri', actual: $publication['properties']['source']['format']);
		self::assertSame(expected: 'Source', actual: $publication['properties']['source']['title']);
		self::assertSame(expected: 'Harvested from', actual: $publication['properties']['derivedFrom']['title']);

	}//end testTheFragmentDeclaresSourceAndDerivedFrom()

	/**
	 * The properties are the ones every feed's provenance setting names.
	 *
	 * @return void
	 */
	public function testThePropertiesAreTheOnesTheFeedsName(): void {
		self::assertSame(
			expected: ['externalIdProperty' => 'source', 'sourceProperty' => 'derivedFrom'],
			actual: HarvestFeedService::PROVENANCE
		);

	}//end testThePropertiesAreTheOnesTheFeedsName()

	/**
	 * Merged with every fragment, the publication holds both under the bumped version.
	 *
	 * @return void
	 */
	public function testTheMergedSchemaCarriesBothAndTheBump(): void {
		$root = dirname(__DIR__, 3).'/lib/Settings';
		$base = json_decode(
			json: (string) file_get_contents($root.'/publication_register.json'),
			associative: true,
			flags: JSON_THROW_ON_ERROR
		);

		$merge       = new ReflectionMethod(objectOrMethod: SettingsService::class, method: 'mergeRegisterFragments');
		$merged      = $merge->invoke(null, $base, $root.'/register.d');
		$publication = $merged['components']['schemas']['publication'];

		self::assertTrue(condition: version_compare($publication['version'], '0.0.20', '>='), message: $publication['version']);
		self::assertArrayHasKey(key: 'source', array: $publication['properties']);
		self::assertArrayHasKey(key: 'derivedFrom', array: $publication['properties']);

	}//end testTheMergedSchemaCarriesBothAndTheBump()
}//end class
