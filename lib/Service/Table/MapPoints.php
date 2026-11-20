<?php

/**
 * The points of a map view, gathered row by row.
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

use stdClass;

/**
 * Turns rows into GeoJSON point features until the limit is reached. A row whose
 * latitude or longitude is empty, not a number or out of range is skipped and counted.
 *
 * @spec openspec/changes/dataset-views-on-the-public-page/specs/open-data-tables/spec.md#requirement-anyone-reads-a-view-s-data-while-the-publication-is-public-req-dsv-002
 */
final class MapPoints {

	/**
	 * The features gathered so far.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private array $features = [];

	/**
	 * The rows left out for want of valid coordinates.
	 *
	 * @var integer
	 */
	private int $skipped = 0;

	/**
	 * Whether rows were left out because the limit was reached.
	 *
	 * @var boolean
	 */
	private bool $truncated = false;

	/**
	 * Constructor.
	 *
	 * @param array<string, mixed> $view  The map view: its latitude, longitude and label columns.
	 * @param integer              $limit The most points the map holds.
	 */
	public function __construct(
		private readonly array $view,
		private readonly int $limit,
	) {
	}//end __construct()

	/**
	 * Add the points of a page of rows.
	 *
	 * @param array<int, mixed> $rows The rows.
	 *
	 * @return bool True while the map can take more points.
	 *
	 * @spec openspec/changes/dataset-views-on-the-public-page/specs/open-data-tables/spec.md#requirement-anyone-reads-a-view-s-data-while-the-publication-is-public-req-dsv-002
	 */
	public function addAll(array $rows): bool {
		foreach ($rows as $row) {
			if (count($this->features) >= $this->limit) {
				$this->truncated = true;
				return false;
			}

			$this->add(row: (array)$row);
		}

		return $rows !== [];
	}//end addAll()

	/**
	 * The map as a GeoJSON FeatureCollection, with `skipped` and `truncated`.
	 *
	 * @return array<string, mixed> The collection.
	 *
	 * @spec openspec/changes/dataset-views-on-the-public-page/specs/open-data-tables/spec.md#requirement-anyone-reads-a-view-s-data-while-the-publication-is-public-req-dsv-002
	 */
	public function collection(): array {
		return [
			'kind' => 'map',
			'type' => 'FeatureCollection',
			'features' => $this->features,
			'skipped' => $this->skipped,
			'truncated' => $this->truncated,
		];
	}//end collection()

	/**
	 * Add one row as a point, or count it as skipped.
	 *
	 * @param array<string, mixed> $row The row.
	 *
	 * @return void
	 */
	private function add(array $row): void {
		$latitude = self::coordinate(value: ($row[(string)$this->view['latitude']] ?? null), bound: 90.0);
		$longitude = self::coordinate(value: ($row[(string)$this->view['longitude']] ?? null), bound: 180.0);
		if ($latitude === null || $longitude === null) {
			$this->skipped++;
			return;
		}

		$label = (string)($this->view['label'] ?? '');
		$properties = new stdClass();
		if ($label !== '') {
			$properties = ['label' => ($row[$label] ?? null)];
		}

		$this->features[] = [
			'type' => 'Feature',
			'geometry' => ['type' => 'Point', 'coordinates' => [$longitude, $latitude]],
			'properties' => $properties,
		];
	}//end add()

	/**
	 * A coordinate as a number within ±bound, or null.
	 *
	 * @param mixed $value The value.
	 * @param float $bound The largest absolute value allowed.
	 *
	 * @return float|null The coordinate.
	 */
	private static function coordinate(mixed $value, float $bound): ?float {
		if (is_numeric($value) === false) {
			return null;
		}

		$number = (float)$value;
		if (abs($number) > $bound) {
			return null;
		}

		return $number;
	}//end coordinate()
}//end class
