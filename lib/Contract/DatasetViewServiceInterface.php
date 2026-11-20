<?php

/**
 * Contract of DatasetViewService, for callers in other components.
 *
 * @category Contract
 * @package  OCA\OpenCatalogi\Contract
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

namespace OCA\OpenCatalogi\Contract;

use InvalidArgumentException;

/**
 * Stores the table, chart and map views of a published table and answers their data.
 *
 * @spec openspec/changes/dataset-views-on-the-public-page/specs/open-data-tables/spec.md#requirement-an-editor-adds-a-table-chart-or-map-view-to-a-published-table-req-dsv-001
 */
interface DatasetViewServiceInterface {

	/**
	 * Check a view against its table's columns and store it.
	 *
	 * @param array<string, mixed> $view  The view: kind, title and the columns its kind needs; `id` updates.
	 * @param array<string, mixed> $table The published table the view shows.
	 *
	 * @return array<string, mixed> The stored view.
	 *
	 * @throws InvalidArgumentException When the view is incomplete or names a column the table cannot serve; the message names it.
	 *
	 * @spec openspec/changes/dataset-views-on-the-public-page/specs/open-data-tables/spec.md#requirement-an-editor-adds-a-table-chart-or-map-view-to-a-published-table-req-dsv-001
	 */
	public function save(array $view, array $table): array;

	/**
	 * The views of a table, by position.
	 *
	 * @param string $tableId The table.
	 *
	 * @return array<int, array<string, mixed>> The views.
	 *
	 * @spec openspec/changes/dataset-views-on-the-public-page/specs/open-data-tables/spec.md#requirement-anyone-reads-a-view-s-data-while-the-publication-is-public-req-dsv-002
	 */
	public function viewsOf(string $tableId): array;

	/**
	 * One view, or null when there is none.
	 *
	 * @param string $viewId The view.
	 *
	 * @return array<string, mixed>|null The view.
	 *
	 * @spec openspec/changes/dataset-views-on-the-public-page/specs/open-data-tables/spec.md#requirement-anyone-reads-a-view-s-data-while-the-publication-is-public-req-dsv-002
	 */
	public function findView(string $viewId): ?array;

	/**
	 * Remove a view.
	 *
	 * @param string $viewId The view.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/dataset-views-on-the-public-page/specs/open-data-tables/spec.md#requirement-an-editor-adds-a-table-chart-or-map-view-to-a-published-table-req-dsv-001
	 */
	public function remove(string $viewId): void;

	/**
	 * The data of a view: rows for a table, grouped values for a chart, GeoJSON for a map.
	 *
	 * The caller has checked that the publication is readable.
	 *
	 * @param array<string, mixed> $view  The view.
	 * @param array<string, mixed> $table The published table it shows.
	 *
	 * @return array<string, mixed> The data.
	 *
	 * @spec openspec/changes/dataset-views-on-the-public-page/specs/open-data-tables/spec.md#requirement-anyone-reads-a-view-s-data-while-the-publication-is-public-req-dsv-002
	 */
	public function data(array $view, array $table): array;

	/**
	 * The public publication answer's tables, each with its views and their data URLs.
	 *
	 * @param array<int, array<string, mixed>>                  $tables The tables as the publication answer lists them.
	 * @param array{catalogSlug: string, publicationId: string} $where  The catalogue and the publication of the URLs.
	 *
	 * @return array<int, array<string, mixed>> The tables, each with `views`.
	 *
	 * @spec openspec/changes/dataset-views-on-the-public-page/specs/open-data-tables/spec.md#requirement-anyone-reads-a-view-s-data-while-the-publication-is-public-req-dsv-002
	 */
	public function withViews(array $tables, array $where): array;
}//end interface
