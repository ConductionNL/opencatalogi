<?php
/**
 * Tests for the catalogue-staff-access register fragment: the catalog schema
 * declares `staffAccess` in the shape CatalogAccessService reads.
 *
 * @category Test
 * @package  OCA\OpenCatalogi\Tests
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/catalogue-staff-access/specs/catalogs/spec.md
 */

declare(strict_types=1);

namespace Unit\Settings;

use PHPUnit\Framework\TestCase;

/**
 * Unit tests for lib/Settings/register.d/catalogue-staff-access.json.
 */
class CatalogueStaffAccessSchemaTest extends TestCase {

	private const FRAGMENT = __DIR__ . '/../../../lib/Settings/register.d/catalogue-staff-access.json';

	public function testTheCatalogSchemaDeclaresStaffAccess(): void {
		$fragment = json_decode((string)file_get_contents(self::FRAGMENT), true, 512, JSON_THROW_ON_ERROR);
		$access = $fragment['components']['schemas']['catalog']['properties']['staffAccess'];

		$this->assertSame('object', $access['type']);
		$this->assertSame('boolean', $access['properties']['restricted']['type']);
		$this->assertFalse($access['properties']['restricted']['default']);
		foreach (['readGroups', 'editGroups'] as $list) {
			$this->assertSame('array', $access['properties'][$list]['type']);
			$this->assertSame('string', $access['properties'][$list]['items']['type']);
		}

	}//end testTheCatalogSchemaDeclaresStaffAccess()
}//end class
