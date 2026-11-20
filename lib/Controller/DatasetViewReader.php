<?php

/**
 * The public read of a dataset view: the readable table and a view's data.
 *
 * @category Controller
 * @package  OCA\OpenCatalogi\Controller
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

namespace OCA\OpenCatalogi\Controller;

use OCA\OpenCatalogi\Contract\CatalogiServiceInterface;
use OCA\OpenCatalogi\Contract\DatasetViewServiceInterface;
use OCA\OpenCatalogi\Contract\PublishedTableServiceInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Decides what a public view read may see, before any view is read.
 *
 * A table is readable only when the catalogue exists, the table belongs to the
 * publication in the URL and is ready, and the caller may read that
 * publication at this moment. A view is readable only when it shows that table.
 *
 * @spec openspec/changes/dataset-views-on-the-public-page/specs/open-data-tables/spec.md#requirement-anyone-reads-a-view-s-data-while-the-publication-is-public-req-dsv-002
 */
final class DatasetViewReader {

	/**
	 * Constructor.
	 *
	 * @param CatalogiServiceInterface       $catalogs Resolves the catalogue slug.
	 * @param PublishedTableServiceInterface $tables   The tables and the publication read check.
	 * @param DatasetViewServiceInterface    $views    The views and their data.
	 * @param LoggerInterface                $logger   Logs a view whose data could not be read.
	 */
	public function __construct(
		private readonly CatalogiServiceInterface $catalogs,
		private readonly PublishedTableServiceInterface $tables,
		public readonly DatasetViewServiceInterface $views,
		private readonly LoggerInterface $logger,
	) {

	}//end __construct()

	/**
	 * The table, when the catalogue exists, the table is the publication's and
	 * ready, and the caller may read the publication now.
	 *
	 * @param array{catalogSlug: string, publicationId: string, tableId: string} $where The route.
	 *
	 * @return array<string, mixed>|null The table record, or null.
	 *
	 * @spec openspec/changes/dataset-views-on-the-public-page/specs/open-data-tables/spec.md#requirement-anyone-reads-a-view-s-data-while-the-publication-is-public-req-dsv-002
	 */
	public function readableTable(array $where): ?array {
		if ($this->catalogs->getCatalogBySlug($where['catalogSlug']) === null) {
			return null;
		}

		$table = $this->tables->findTable(tableId: $where['tableId']);
		if ($table === null || (string)($table['publication'] ?? '') !== $where['publicationId'] || ($table['status'] ?? null) !== 'ready') {
			return null;
		}

		if ($this->tables->canReadPublication(publicationId: $where['publicationId']) === false) {
			return null;
		}

		return $table;
	}//end readableTable()

	/**
	 * A view of the table, or null when it does not exist or shows another table.
	 *
	 * @param string $viewId  The view.
	 * @param string $tableId The table it must show.
	 *
	 * @return array<string, mixed>|null The view.
	 *
	 * @spec openspec/changes/dataset-views-on-the-public-page/specs/open-data-tables/spec.md#requirement-anyone-reads-a-view-s-data-while-the-publication-is-public-req-dsv-002
	 */
	public function viewOf(string $viewId, string $tableId): ?array {
		$view = $this->views->findView(viewId: $viewId);
		if ($view === null || (string)($view['table'] ?? '') !== $tableId) {
			return null;
		}

		return $view;
	}//end viewOf()

	/**
	 * The data of a view, or null when it cannot be read (logged).
	 *
	 * @param array<string, mixed> $view  The view.
	 * @param array<string, mixed> $table The table it shows.
	 *
	 * @return array<string, mixed>|null The data.
	 *
	 * @spec openspec/changes/dataset-views-on-the-public-page/specs/open-data-tables/spec.md#requirement-anyone-reads-a-view-s-data-while-the-publication-is-public-req-dsv-002
	 */
	public function dataOf(array $view, array $table): ?array {
		try {
			return $this->views->data(view: $view, table: $table);
		} catch (Throwable $e) {
			$this->logger->error('[DatasetViewController] The data of a view could not be read', ['view' => ($view['id'] ?? ''), 'error' => $e->getMessage()]);
			return null;
		}
	}//end dataOf()
}//end class
