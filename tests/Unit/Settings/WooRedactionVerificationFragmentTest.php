<?php

/**
 * The register fragment that stores a person's check of a redaction (REQ-WDA-004).
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
 * @link https://www.OpenCatalogi.nl
 *
 * @spec openspec/changes/woo-document-assessment-screen/specs/woo-transparency/spec.md#requirement-a-person-marks-a-redaction-verified-before-it-can-be-published-req-wda-004
 */

declare(strict_types=1);

namespace Unit\Settings;

use PHPUnit\Framework\TestCase;

/**
 * Reads the fragment as it ships, and the order the importer merges it in.
 *
 * @coversNothing
 */
class WooRedactionVerificationFragmentTest extends TestCase {

	private const DIRECTORY = __DIR__ . '/../../../lib/Settings/register.d/';

	/**
	 * @param string $name The fragment file name.
	 *
	 * @return array<string, mixed>
	 */
	private function fragment(string $name): array {
		return json_decode((string)file_get_contents(self::DIRECTORY . $name), true, 512, JSON_THROW_ON_ERROR);
	}//end fragment()

	public function testTheFragmentAddsBothFieldsToTheAssessment(): void {
		$schema = $this->fragment('woo-redaction-verification.json')['components']['schemas']['wooAssessment'];

		$this->assertSame(['redactionVerifiedBy', 'redactionVerifiedAt'], array_keys($schema['properties']));
		$this->assertSame('string', $schema['properties']['redactionVerifiedBy']['type']);
		$this->assertSame('date-time', $schema['properties']['redactionVerifiedAt']['format']);
		$this->assertArrayNotHasKey('authorization', $schema, 'the assessment keeps its admin-only rule');
	}//end testTheFragmentAddsBothFieldsToTheAssessment()

	public function testTheSchemaVersionIsBumpedAndMergedLast(): void {
		$base = $this->fragment('fix-woo-capability-provisioning.json')['components']['schemas']['wooAssessment']['version'];
		$bumped = $this->fragment('woo-redaction-verification.json')['components']['schemas']['wooAssessment']['version'];

		$this->assertTrue(version_compare($bumped, $base, '>'), $bumped . ' must be above ' . $base);

		// Fragments merge in file name order, so a later version scalar wins.
		$files = array_map('basename', glob(self::DIRECTORY . '*.json'));
		sort($files);
		$versions = [];
		foreach ($files as $file) {
			$version = ($this->fragment($file)['components']['schemas']['wooAssessment']['version'] ?? null);
			if ($version !== null) {
				$versions[$file] = $version;
			}
		}

		$this->assertSame($bumped, end($versions));
	}//end testTheSchemaVersionIsBumpedAndMergedLast()
}//end class
