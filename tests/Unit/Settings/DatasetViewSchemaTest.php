<?php

/**
 * The tableView schema as the dataset-views fragment ships it.
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
 *
 * @spec openspec/changes/dataset-views-on-the-public-page/specs/open-data-tables/spec.md#requirement-an-editor-adds-a-table-chart-or-map-view-to-a-published-table-req-dsv-001
 */

declare(strict_types=1);

namespace Unit\Settings;

use OCA\OpenCatalogi\Service\Table\DatasetViewService;
use PHPUnit\Framework\TestCase;

/**
 * Reads the fragment as it ships.
 *
 * @coversNothing
 */
class DatasetViewSchemaTest extends TestCase {

	private const FRAGMENT = __DIR__ . '/../../../lib/Settings/register.d/dataset-views.json';

	/**
	 * The fragment.
	 *
	 * @return array<string, mixed>
	 */
	private function fragment(): array {
		return json_decode((string)file_get_contents(self::FRAGMENT), true, 512, JSON_THROW_ON_ERROR);
	}//end fragment()

	/**
	 * The schema carries its slug and no public read rule; only admins write it directly.
	 *
	 * @return void
	 */
	public function testTheFragmentHasASlugAndNoPublicReadRule(): void {
		$schema = $this->fragment()['components']['schemas']['tableView'];

		$this->assertSame('tableView', $schema['slug']);
		$this->assertSame(['authenticated'], $schema['authorization']['read']);
		foreach (['create', 'update', 'delete'] as $action) {
			$this->assertSame(['admin'], $schema['authorization'][$action], $action.' must be admin only');
		}

		$this->assertStringNotContainsString('public', json_encode($schema['authorization'], JSON_THROW_ON_ERROR));
		$this->assertContains('tableView', $this->fragment()['components']['registers']['publication']['schemas']);
	}//end testTheFragmentHasASlugAndNoPublicReadRule()

	/**
	 * The properties are those of design D1, and the enums are the ones the service checks.
	 *
	 * @return void
	 */
	public function testThePropertiesAreTheOnesOfTheDesign(): void {
		$schema = $this->fragment()['components']['schemas']['tableView'];

		$this->assertSame(
			['table', 'kind', 'title', 'columns', 'x', 'y', 'aggregate', 'latitude', 'longitude', 'label', 'position'],
			array_keys($schema['properties'])
		);
		$this->assertSame(['table', 'kind', 'title'], $schema['required']);
		$this->assertSame(DatasetViewService::KINDS, $schema['properties']['kind']['enum']);
		$this->assertSame(DatasetViewService::AGGREGATES, $schema['properties']['aggregate']['enum']);
		$this->assertSame('#/components/schemas/publishedTable', $schema['properties']['table']['$ref']);
	}//end testThePropertiesAreTheOnesOfTheDesign()
}//end class
