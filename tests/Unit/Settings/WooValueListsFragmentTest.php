<?php

/**
 * Tests for the woo-value-lists-on-the-concept-register register fragment.
 *
 * @category Test
 * @package  Unit\Settings
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * @version GIT: <git_id>
 * @link https://www.OpenCatalogi.nl
 */

declare(strict_types=1);

namespace Unit\Settings;

use PHPUnit\Framework\TestCase;

/**
 * The fragment adds the two marker fields to wooAssessment and its version wins the merge.
 *
 * @coversNothing
 */
class WooValueListsFragmentTest extends TestCase {

	/**
	 * The fragment directory.
	 */
	private const DIRECTORY = __DIR__ . '/../../../lib/Settings/register.d/';

	/**
	 * The fragment's wooAssessment schema.
	 *
	 * @param string $file The fragment file.
	 *
	 * @return array<string, mixed>|null The schema, or null when the fragment has none.
	 */
	private function assessment(string $file): ?array {
		$data = json_decode((string)file_get_contents(self::DIRECTORY . $file), true, 512, JSON_THROW_ON_ERROR);

		return ($data['components']['schemas']['wooAssessment'] ?? null);
	}//end assessment()

	/**
	 * The marker and the unmapped list are declared with their types.
	 *
	 * @return void
	 */
	public function testTheMarkerFieldsAreDeclared(): void {
		$properties = $this->assessment('woo-value-lists-on-the-concept-register.json')['properties'];

		$this->assertSame('string', $properties['groundsListVersion']['type']);
		$this->assertSame(['type' => 'array', 'items' => ['type' => 'string']], array_intersect_key($properties['unmappedGrounds'], ['type' => 1, 'items' => 1]));
	}//end testTheMarkerFieldsAreDeclared()

	/**
	 * The version that wins the merge is the highest, and never below its own bump.
	 *
	 * @return void
	 */
	public function testItsVersionWinsTheMerge(): void {
		$files = array_map('basename', glob(self::DIRECTORY . '*.json'));
		sort($files);
		$versions = [];
		foreach ($files as $file) {
			$version = ($this->assessment($file)['version'] ?? null);
			if ($version !== null) {
				$versions[$file] = $version;
			}
		}

		// A later fragment may bump it again (woo-x-access-boundary-hardening sorts
		// last on purpose); what must hold is that the version that wins the merge
		// is the highest, and never below this change's bump.
		$merged = end($versions);
		$own = $versions['woo-value-lists-on-the-concept-register.json'];
		$this->assertTrue(version_compare($merged, $own, '>='), $merged . ' is below ' . $own);
		foreach ($versions as $file => $version) {
			$this->assertTrue(version_compare($merged, $version, '>='), $file . ' carries ' . $version . ', above the merged ' . $merged);
		}

	}//end testItsVersionWinsTheMerge()
}//end class
