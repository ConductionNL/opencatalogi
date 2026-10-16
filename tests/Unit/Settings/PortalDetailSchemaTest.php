<?php

/**
 * The portal detail opt-ins ship switched off.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/publication-detail-for-the-portal/specs/publications/spec.md#requirement-a-catalogue-may-show-that-documents-were-withheld-and-why-req-pdp-004
 */

declare(strict_types=1);

namespace Unit\Settings;

use PHPUnit\Framework\TestCase;

/**
 * Reads the register files as they ship.
 *
 * @coversNothing
 */
class PortalDetailSchemaTest extends TestCase {

	private const SETTINGS = __DIR__ . '/../../../lib/Settings/';

	/**
	 * @return array<string, mixed>
	 */
	private function schema(string $file, string $slug): array {
		$data = json_decode((string)file_get_contents(self::SETTINGS . $file), true, 512, JSON_THROW_ON_ERROR);

		return $data['components']['schemas'][$slug];
	}//end schema()

	public function testTheDefaultsAreOff(): void {
		$catalog = $this->schema('publication_register.json', 'catalog');
		$assessment = $this->schema('register.d/fix-woo-capability-provisioning.json', 'wooAssessment');

		$this->assertSame(['type' => 'boolean', 'default' => false], array_intersect_key($catalog['properties']['showWithheld'], ['type' => 1, 'default' => 1]));
		$this->assertSame(['type' => 'boolean', 'default' => false], array_intersect_key($assessment['properties']['titlePublic'], ['type' => 1, 'default' => 1]));
		$this->assertSame('0.4.0', $catalog['version'], 'a schema change moves its version');
		$this->assertSame('0.3.0', $assessment['version'], 'a schema change moves its version');
	}//end testTheDefaultsAreOff()
}//end class
