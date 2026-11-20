<?php

/**
 * Unit tests for the theme schema as it ships (subjects-as-first-class-records, REQ-SUB-001).
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

use PHPUnit\Framework\TestCase;

/**
 * Reads the theme schema as it ships.
 */
class ThemeSchemaTest extends TestCase {

	private const REGISTER = __DIR__ . '/../../../lib/Settings/publication_register.json';

	/**
	 * @return array<string, mixed>
	 */
	private function schema(): array {
		$register = json_decode((string)file_get_contents(self::REGISTER), true, 512, JSON_THROW_ON_ERROR);
		return $register['components']['schemas']['theme'];
	}//end schema()

	public function testTheThemeSchemaDeclaresFeaturedOrderAndSlug(): void {
		$properties = $this->schema()['properties'];

		$this->assertSame('boolean', $properties['featured']['type']);
		$this->assertFalse($properties['featured']['default']);
		$this->assertSame('integer', $properties['featuredOrder']['type']);
		$this->assertSame(0, $properties['featuredOrder']['minimum']);
		$this->assertSame('string', $properties['slug']['type']);
		$this->assertSame(1, preg_match('/' . $properties['slug']['pattern'] . '/', 'de-nieuwe-sporthal'));
		$this->assertSame(0, preg_match('/' . $properties['slug']['pattern'] . '/', 'De Sporthal'));
		$this->assertSame(0, preg_match('/' . $properties['slug']['pattern'] . '/', '-sporthal'));
	}//end testTheThemeSchemaDeclaresFeaturedOrderAndSlug()

	public function testTheVersionIsBumpedAndTheSubjectStaysPubliclyReadable(): void {
		$schema = $this->schema();

		$this->assertTrue(version_compare($schema['version'], '0.0.4', '>'));
		$this->assertSame([['group' => 'public', 'crossOrganisation' => true]], $schema['authorization']['read']);
	}//end testTheVersionIsBumpedAndTheSubjectStaysPubliclyReadable()
}
