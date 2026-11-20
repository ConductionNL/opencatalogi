<?php

/**
 * The column checks a view passes before it is stored (design D2).
 *
 * @category Service
 * @package  OCA\OpenCatalogi\Service\Table
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

namespace OCA\OpenCatalogi\Service\Table;

use InvalidArgumentException;

/**
 * Refuses a view that names a column its table does not have, a sum or average
 * over a column that is not numeric, or map coordinates that are not numeric.
 * Each refusal names the column, so a renamed column never leaves an empty chart.
 *
 * @spec openspec/changes/dataset-views-on-the-public-page/specs/open-data-tables/spec.md#requirement-an-editor-adds-a-table-chart-or-map-view-to-a-published-table-req-dsv-001
 */
final class ViewColumnRules {

	/**
	 * A table view: at least one column, each one the table has.
	 *
	 * @param array<string, mixed> $record  The view.
	 * @param array<string, bool>  $columns Whether each column of the table is numeric.
	 *
	 * @return array<string, mixed> The view with only the table fields.
	 *
	 * @throws InvalidArgumentException When a column is unknown or none is chosen.
	 *
	 * @spec openspec/changes/dataset-views-on-the-public-page/specs/open-data-tables/spec.md#requirement-an-editor-adds-a-table-chart-or-map-view-to-a-published-table-req-dsv-001
	 */
	public static function table(array $record, array $columns): array {
		$chosen = array_values(array_map('strval', (array)($record['columns'] ?? [])));
		if ($chosen === []) {
			throw new InvalidArgumentException('A table view shows at least one column.');
		}

		foreach ($chosen as $column) {
			self::known(column: $column, columns: $columns);
		}

		$record['columns'] = $chosen;

		return self::only(record: $record, fields: ['columns']);
	}//end table()

	/**
	 * A bar or line chart: a known `x`, an aggregate, and a numeric `y` for a sum or an average.
	 *
	 * @param array<string, mixed> $record  The view.
	 * @param array<string, bool>  $columns Whether each column of the table is numeric.
	 *
	 * @return array<string, mixed> The view with only the chart fields.
	 *
	 * @throws InvalidArgumentException When a column is unknown or not numeric where it must be.
	 *
	 * @spec openspec/changes/dataset-views-on-the-public-page/specs/open-data-tables/spec.md#requirement-an-editor-adds-a-table-chart-or-map-view-to-a-published-table-req-dsv-001
	 */
	public static function chart(array $record, array $columns): array {
		self::known(column: (string)($record['x'] ?? ''), columns: $columns);

		$aggregate = (string)($record['aggregate'] ?? 'count');
		if (in_array($aggregate, DatasetViewService::AGGREGATES, true) === false) {
			throw new InvalidArgumentException('A chart counts, sums or averages, not "'.$aggregate.'".');
		}

		$record['aggregate'] = $aggregate;
		$record['y'] = trim((string)($record['y'] ?? ''));
		if ($aggregate !== 'count') {
			self::numeric(column: $record['y'], columns: $columns);
		} else if ($record['y'] !== '') {
			self::known(column: $record['y'], columns: $columns);
		}

		return self::only(record: $record, fields: ['x', 'y', 'aggregate']);
	}//end chart()

	/**
	 * A map: numeric latitude and longitude columns, and a known label column when one is named.
	 *
	 * @param array<string, mixed> $record  The view.
	 * @param array<string, bool>  $columns Whether each column of the table is numeric.
	 *
	 * @return array<string, mixed> The view with only the map fields.
	 *
	 * @throws InvalidArgumentException When a coordinate column is unknown or not numeric, or the label column is unknown.
	 *
	 * @spec openspec/changes/dataset-views-on-the-public-page/specs/open-data-tables/spec.md#requirement-an-editor-adds-a-table-chart-or-map-view-to-a-published-table-req-dsv-001
	 */
	public static function map(array $record, array $columns): array {
		self::numeric(column: (string)($record['latitude'] ?? ''), columns: $columns);
		self::numeric(column: (string)($record['longitude'] ?? ''), columns: $columns);

		$record['label'] = trim((string)($record['label'] ?? ''));
		if ($record['label'] !== '') {
			self::known(column: $record['label'], columns: $columns);
		}

		return self::only(record: $record, fields: ['latitude', 'longitude', 'label']);
	}//end map()

	/**
	 * Refuse a column the table does not have.
	 *
	 * @param string              $column  The column.
	 * @param array<string, bool> $columns The table's columns.
	 *
	 * @return void
	 *
	 * @throws InvalidArgumentException When the table has no such column.
	 */
	private static function known(string $column, array $columns): void {
		if ($column === '' || array_key_exists($column, $columns) === false) {
			throw new InvalidArgumentException('This table has no column named "'.$column.'".');
		}
	}//end known()

	/**
	 * Refuse a column that is unknown or does not hold numbers.
	 *
	 * @param string              $column  The column.
	 * @param array<string, bool> $columns The table's columns.
	 *
	 * @return void
	 *
	 * @throws InvalidArgumentException When the column is unknown or not numeric.
	 */
	private static function numeric(string $column, array $columns): void {
		self::known(column: $column, columns: $columns);
		if ($columns[$column] === false) {
			throw new InvalidArgumentException('The column "'.$column.'" does not hold numbers.');
		}
	}//end numeric()

	/**
	 * The fields every view keeps, plus those of its kind.
	 *
	 * @param array<string, mixed> $record The view.
	 * @param array<int, string>   $fields The fields of its kind.
	 *
	 * @return array<string, mixed> The view.
	 */
	private static function only(array $record, array $fields): array {
		$kept = array_intersect_key($record, array_flip(array_merge(['table', 'kind', 'title', 'position'], $fields)));
		$kept['position'] = max(0, (int)($kept['position'] ?? 0));

		return $kept;
	}//end only()
}//end class
