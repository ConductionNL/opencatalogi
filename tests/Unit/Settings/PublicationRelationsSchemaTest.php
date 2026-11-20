<?php

/**
 * Unit tests for the publication-relations-place-and-source-ids register fragment.
 *
 * @category Test
 * @package  OCA\OpenCatalogi\Tests
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://www.OpenCatalogi.nl
 */

declare(strict_types=1);

namespace Unit\Settings;

use OCA\OpenCatalogi\Service\SettingsService;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * The publication schema names four relation types and carries source identifiers.
 *
 * @coversNothing
 */
class PublicationRelationsSchemaTest extends TestCase {

	private const FRAGMENT = __DIR__ . '/../../../lib/Settings/register.d/publication-relations-place-and-source-ids.json';

	/**
	 * The publication schema of the effective register, merged the way loadSettings() merges it.
	 *
	 * @return array<string, mixed> The schema.
	 */
	private function publication(): array {
		$root = dirname(__DIR__, 3);
		$data = json_decode((string)file_get_contents($root . '/lib/Settings/publication_register.json'), true);
		$merge = new ReflectionMethod(SettingsService::class, 'deepMergeConfig');
		$merge->setAccessible(true);
		$fragments = glob($root . '/lib/Settings/register.d/*.json');
		sort($fragments);
		foreach ($fragments as $fragment) {
			$data = $merge->invoke(null, $data, json_decode((string)file_get_contents($fragment), true));
		}

		return $data['components']['schemas']['publication'];
	}//end publication()

	/**
	 * The vocabulary declares the four types with their labels, `relatedTo` symmetric and without an inverse.
	 *
	 * @return void
	 */
	public function testTheVocabularyDeclaresFourTypes(): void {
		$vocabulary = $this->publication()['x-openregister-relation-types'];

		$this->assertSame(['replaces', 'amends', 'implements', 'relatedTo'], array_column($vocabulary, 'key'));
		$byKey = array_column($vocabulary, null, 'key');
		$this->assertSame('vervangt', $byKey['replaces']['label']['nl']);
		$this->assertSame('wordt vervangen door', $byKey['replaces']['inverseLabel']['nl']);
		$this->assertSame('wijzigt', $byKey['amends']['label']['nl']);
		$this->assertSame('wordt gewijzigd door', $byKey['amends']['inverseLabel']['nl']);
		$this->assertSame('geeft uitvoering aan', $byKey['implements']['label']['nl']);
		$this->assertSame('wordt uitgevoerd door', $byKey['implements']['inverseLabel']['nl']);
		$this->assertSame('hoort bij', $byKey['relatedTo']['label']['nl']);
		$this->assertTrue($byKey['relatedTo']['symmetric']);
		$this->assertArrayNotHasKey('inverseLabel', $byKey['relatedTo']);
	}//end testTheVocabularyDeclaresFourTypes()

	/**
	 * Each relation property is a list of publication references typed by its own key.
	 *
	 * @return void
	 */
	public function testEachRelationPropertyReferencesAPublication(): void {
		$properties = $this->publication()['properties'];
		foreach (['replaces', 'amends', 'implements', 'relatedTo'] as $key) {
			$this->assertSame('array', $properties[$key]['type'], $key);
			$this->assertSame('publication', $properties[$key]['items']['$ref'], $key);
			$this->assertSame(['type' => $key], $properties[$key]['items']['x-openregister-relation'], $key);
		}
	}//end testEachRelationPropertyReferencesAPublication()

	/**
	 * Source identifiers need a system and an identifier, and are private unless marked public.
	 *
	 * @return void
	 */
	public function testSourceIdentifiersArePrivateByDefault(): void {
		$items = $this->publication()['properties']['sourceIdentifiers']['items'];

		$this->assertSame(['system', 'identifier'], $items['required']);
		$this->assertSame('boolean', $items['properties']['public']['type']);
		$this->assertFalse($items['properties']['public']['default']);
		$this->assertArrayHasKey('caseReference', $this->publication()['properties']);
	}//end testSourceIdentifiersArePrivateByDefault()

	/**
	 * The fragment names the schema by slug and bumps its version, and declares no place (gated on D10).
	 *
	 * @return void
	 */
	public function testTheFragmentHasASlugAVersionAndNoPlace(): void {
		$fragment = json_decode((string)file_get_contents(self::FRAGMENT), true)['components']['schemas']['publication'];

		$this->assertSame('publication', $fragment['slug']);
		$this->assertTrue(version_compare($fragment['version'], '0.0.7', '>'));
		$this->assertArrayNotHasKey('geo', $this->publication()['properties']);
	}//end testTheFragmentHasASlugAVersionAndNoPlace()

	/**
	 * OpenRegister's relation validator accepts the declaration, where OpenRegister is installed.
	 *
	 * @return void
	 */
	public function testTheDeclarationPassesOpenRegistersRelationValidator(): void {
		$validator = 'OCA\OpenRegister\Service\Relation\RelationAnnotationValidator';
		if (class_exists($validator) === false) {
			$this->markTestSkipped('OpenRegister with relation types is not installed beside this app.');
		}

		$this->assertSame([], (new $validator())->validate($this->publication()));
	}//end testTheDeclarationPassesOpenRegistersRelationValidator()
}//end class
