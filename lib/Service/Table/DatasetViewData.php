<?php

/**
 * Shapes the data of a table, chart or map view (design D3).
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
 * @spec openspec/changes/dataset-views-on-the-public-page/specs/open-data-tables/spec.md#requirement-anyone-reads-a-view-s-data-while-the-publication-is-public-req-dsv-002
 */

declare(strict_types=1);

namespace OCA\OpenCatalogi\Service\Table;

use OCA\OpenCatalogi\Contract\PublishedTableServiceInterface;
use OCA\OpenRegister\Search\Service\Aggregation\AggregationQuery;
use OCA\OpenRegister\Service\Aggregation\AggregationRunnerInterface;

/**
 * A table view answers the rows endpoint's own first page with only its columns;
 * a chart answers OpenRegister's grouped aggregation (at most 200 groups); a map
 * answers a GeoJSON FeatureCollection of at most 5,000 points.
 *
 * Every read runs as the system: the caller has checked that the publication is readable.
 *
 * @spec openspec/changes/dataset-views-on-the-public-page/specs/open-data-tables/spec.md#requirement-anyone-reads-a-view-s-data-while-the-publication-is-public-req-dsv-002
 */
class DatasetViewData {

	/**
	 * The most groups a chart answers.
	 *
	 * @var integer
	 */
	public const MAX_GROUPS = 200;

	/**
	 * The most points a map answers.
	 *
	 * @var integer
	 */
	public const MAX_POINTS = 5000;

	/**
	 * Rows read per page while a map is built.
	 *
	 * @var integer
	 */
	private const MAP_PAGE = 1000;

	/**
	 * Constructor.
	 *
	 * @param PublishedTableServiceInterface $tables      The rows of a published table.
	 * @param AggregationRunnerInterface     $aggregation OpenRegister's in-process aggregation.
	 */
	public function __construct(
		private readonly PublishedTableServiceInterface $tables,
		private readonly AggregationRunnerInterface $aggregation,
	) {
	}//end __construct()

	/**
	 * A table view: the first page of rows, with only the view's columns.
	 *
	 * @param array<string, mixed> $view  The view.
	 * @param array<string, mixed> $table The table.
	 *
	 * @return array<string, mixed> `kind`, `columns`, `results`, `total`, `page` and `pages`.
	 *
	 * @spec openspec/changes/dataset-views-on-the-public-page/specs/open-data-tables/spec.md#requirement-anyone-reads-a-view-s-data-while-the-publication-is-public-req-dsv-002
	 */
	public function table(array $view, array $table): array {
		$columns = array_values(array_map('strval', (array)($view['columns'] ?? [])));
		$rows = $this->tables->rows(table: $table, query: ['_limit' => 20, '_page' => 1]);
		$keep = array_flip($columns);

		$rows['results'] = array_map(
			static fn (mixed $row): array => array_intersect_key((array)$row, $keep),
			(array)($rows['results'] ?? [])
		);

		return ['kind' => 'table', 'columns' => $columns] + $rows;
	}//end table()

	/**
	 * A bar or line chart: one value per group of `x`, at most {@see MAX_GROUPS}.
	 *
	 * @param array<string, mixed> $view  The view.
	 * @param array<string, mixed> $table The table.
	 *
	 * @return array<string, mixed> `kind`, `x`, `y`, `aggregate`, `points` and `truncated`.
	 *
	 * @spec openspec/changes/dataset-views-on-the-public-page/specs/open-data-tables/spec.md#requirement-anyone-reads-a-view-s-data-while-the-publication-is-public-req-dsv-002
	 * @SuppressWarnings(PHPMD.StaticAccess) AggregationQuery::create() is OpenRegister's value factory.
	 */
	public function chart(array $view, array $table): array {
		$aggregate = (string)($view['aggregate'] ?? 'count');
		$measure = (string)($view['y'] ?? '');
		$field = null;
		if ($aggregate !== 'count' && $measure !== '') {
			$field = $measure;
		}

		$query = AggregationQuery::create(
			metric: $aggregate,
			field: $field,
			groupBy: ['field' => (string)$view['x'], 'limit' => (self::MAX_GROUPS + 1)]
		);

		$result = $this->aggregation->runAdhocByRef(
			registerRef: PublishedTableService::ROWS_REGISTER,
			schemaRef: (string)$table['schema'],
			query: $query,
			bypassRbac: true
		);

		$points = array_map(
			static fn (mixed $group): array => ['x' => ($group['key'] ?? null), 'value' => ($group['value'] ?? null)],
			array_values((array)($result['groups'] ?? []))
		);

		return [
			'kind' => (string)$view['kind'],
			'x' => (string)$view['x'],
			'y' => $measure,
			'aggregate' => $aggregate,
			'points' => array_slice($points, 0, self::MAX_GROUPS),
			'truncated' => count($points) > self::MAX_GROUPS,
		];
	}//end chart()

	/**
	 * A map: a GeoJSON FeatureCollection of the rows with valid WGS84 coordinates,
	 * at most {@see MAX_POINTS}; rows without them are counted in `skipped`.
	 *
	 * @param array<string, mixed> $view  The view.
	 * @param array<string, mixed> $table The table.
	 *
	 * @return array<string, mixed> The FeatureCollection with `skipped` and `truncated`.
	 *
	 * @spec openspec/changes/dataset-views-on-the-public-page/specs/open-data-tables/spec.md#requirement-anyone-reads-a-view-s-data-while-the-publication-is-public-req-dsv-002
	 */
	public function map(array $view, array $table): array {
		$map = new MapPoints(view: $view, limit: self::MAX_POINTS);
		$page = 1;
		do {
			$rows = $this->tables->rows(table: $table, query: ['_limit' => self::MAP_PAGE, '_page' => $page]);
			$more = $map->addAll(rows: (array)($rows['results'] ?? []));
			$page++;
		} while ($more === true && $page <= (int)($rows['pages'] ?? 1));

		return $map->collection();
	}//end map()
}//end class
