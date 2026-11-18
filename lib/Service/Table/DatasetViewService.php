<?php

/**
 * Stores the views of a published table and answers their data.
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
use JsonSerializable;
use OCA\OpenCatalogi\Contract\DatasetViewServiceInterface;
use OCA\OpenCatalogi\Contract\OpenRegisterLookupInterface;
use OCA\OpenCatalogi\Contract\PublishedTableServiceInterface;
use OCA\OpenCatalogi\Service\SchemaObjects;
use OCA\OpenRegister\Kernel\Contracts\Value\ObjectFindOptions;
use OCA\OpenRegister\Kernel\Contracts\Value\ObjectFindTarget;
use OCA\OpenRegister\Kernel\Contracts\Value\ObjectSaveOptions;
use OCP\IURLGenerator;

/**
 * A view is a small `tableView` object next to its table (design D1). Saving
 * checks it against the table's columns (D2); its data is shaped by
 * {@see DatasetViewData} (D3).
 *
 * @spec openspec/changes/dataset-views-on-the-public-page/specs/open-data-tables/spec.md#requirement-an-editor-adds-a-table-chart-or-map-view-to-a-published-table-req-dsv-001
 */
class DatasetViewService implements DatasetViewServiceInterface {

	/**
	 * The kinds a view can be.
	 *
	 * @var array<int, string>
	 */
	public const KINDS = ['table', 'bar', 'line', 'map'];

	/**
	 * The aggregates a chart can use.
	 *
	 * @var array<int, string>
	 */
	public const AGGREGATES = ['count', 'sum', 'avg'];

	/**
	 * The column types that hold numbers.
	 *
	 * @var array<int, string>
	 */
	private const NUMERIC = ['number', 'integer'];

	/**
	 * The fields a view stores.
	 *
	 * @var array<int, string>
	 */
	private const FIELDS = ['table', 'kind', 'title', 'columns', 'x', 'y', 'aggregate', 'latitude', 'longitude', 'label', 'position'];

	/**
	 * Constructor.
	 *
	 * @param OpenRegisterLookupInterface    $registers The OpenRegister ObjectService and register configuration.
	 * @param PublishedTableServiceInterface $tables    The published tables, their dictionaries and rows.
	 * @param DatasetViewData                $shapes    Shapes the data of each kind of view.
	 * @param IURLGenerator|null             $urls      The URL generator for the data URLs; null leaves them empty.
	 */
	public function __construct(
		private readonly OpenRegisterLookupInterface $registers,
		private readonly PublishedTableServiceInterface $tables,
		private readonly DatasetViewData $shapes,
		private readonly ?IURLGenerator $urls = null,
	) {
	}//end __construct()

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
	public function save(array $view, array $table): array {
		$record = $this->checked(view: $view, table: $table);
		$config = $this->config();
		$uuid = null;
		if (self::idOf(record: $view) !== '') {
			$uuid = self::idOf(record: $view);
		}

		$saved = $this->registers->objectService()->saveObject(
			object: $record,
			options: new ObjectSaveOptions(
				target: new ObjectFindTarget(register: $config['register'], schema: $config['schema']),
				uuid: $uuid
			),
			_rbac: false,
			_multitenancy: false
		);

		return (array)self::plain(value: $saved);
	}//end save()

	/**
	 * The views of a table, by position.
	 *
	 * @param string $tableId The table.
	 *
	 * @return array<int, array<string, mixed>> The views.
	 *
	 * @spec openspec/changes/dataset-views-on-the-public-page/specs/open-data-tables/spec.md#requirement-anyone-reads-a-view-s-data-while-the-publication-is-public-req-dsv-002
	 */
	public function viewsOf(string $tableId): array {
		if ($tableId === '') {
			return [];
		}

		$found = self::plain(value: $this->views()->search(filters: ['table' => $tableId, '_limit' => 100]));
		$views = [];
		foreach ((array)($found['results'] ?? []) as $record) {
			$view = self::plain(value: $record);
			if (is_array($view) === true && (string)($view['table'] ?? '') === $tableId) {
				$views[] = $view;
			}
		}

		usort(
			$views,
			static fn (array $left, array $right): int => [(int)($left['position'] ?? PHP_INT_MAX), (string)($left['title'] ?? '')]
				<=> [(int)($right['position'] ?? PHP_INT_MAX), (string)($right['title'] ?? '')]
		);

		return $views;
	}//end viewsOf()

	/**
	 * One view, or null when there is none.
	 *
	 * @param string $viewId The view.
	 *
	 * @return array<string, mixed>|null The view.
	 *
	 * @spec openspec/changes/dataset-views-on-the-public-page/specs/open-data-tables/spec.md#requirement-anyone-reads-a-view-s-data-while-the-publication-is-public-req-dsv-002
	 */
	public function findView(string $viewId): ?array {
		$config = $this->config();
		try {
			$view = $this->registers->objectService()->find(
				id: $viewId,
				options: new ObjectFindOptions(register: $config['register'], schema: $config['schema']),
				_rbac: false,
				_multitenancy: false
			);
		} catch (\Throwable $e) {
			return null;
		}

		$view = self::plain(value: $view);
		if (is_array($view) === false) {
			return null;
		}

		return $view;
	}//end findView()

	/**
	 * Remove a view.
	 *
	 * @param string $viewId The view.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/dataset-views-on-the-public-page/specs/open-data-tables/spec.md#requirement-an-editor-adds-a-table-chart-or-map-view-to-a-published-table-req-dsv-001
	 */
	public function remove(string $viewId): void {
		$this->views()->delete(id: $viewId);
	}//end remove()

	/**
	 * The data of a view: rows for a table, grouped values for a chart, GeoJSON for a map.
	 *
	 * @param array<string, mixed> $view  The view.
	 * @param array<string, mixed> $table The published table it shows.
	 *
	 * @return array<string, mixed> The data.
	 *
	 * @spec openspec/changes/dataset-views-on-the-public-page/specs/open-data-tables/spec.md#requirement-anyone-reads-a-view-s-data-while-the-publication-is-public-req-dsv-002
	 */
	public function data(array $view, array $table): array {
		return match ((string)($view['kind'] ?? '')) {
			'bar', 'line' => $this->shapes->chart(view: $view, table: $table),
			'map' => $this->shapes->map(view: $view, table: $table),
			default => $this->shapes->table(view: $view, table: $table),
		};
	}//end data()

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
	public function withViews(array $tables, array $where): array {
		return array_map(
			function (array $table) use ($where): array {
				$where['tableId'] = (string)($table['id'] ?? '');
				$table['views'] = array_map(
					fn (array $view): array => $this->publicView(view: $view, where: $where),
					$this->viewsOf(tableId: $where['tableId'])
				);
				return $table;
			},
			$tables
		);
	}//end withViews()

	/**
	 * One view as the public answer lists it: its definition and its data URL.
	 *
	 * @param array<string, mixed>                                               $view  The view.
	 * @param array{catalogSlug: string, publicationId: string, tableId: string} $where The route parameters.
	 *
	 * @return array<string, mixed> The entry.
	 */
	private function publicView(array $view, array $where): array {
		$entry = ['id' => self::idOf(record: $view)];
		foreach (self::FIELDS as $field) {
			if ($field !== 'table' && array_key_exists($field, $view) === true) {
				$entry[$field] = $view[$field];
			}
		}

		$entry['dataUrl'] = '';
		if ($this->urls !== null) {
			$entry['dataUrl'] = $this->urls->linkToRouteAbsolute('opencatalogi.datasetView.data', $where + ['viewId' => $entry['id']]);
		}

		return $entry;
	}//end publicView()

	/**
	 * The view as it is stored, after the checks of design D2.
	 *
	 * @param array<string, mixed> $view  The view as sent.
	 * @param array<string, mixed> $table The table.
	 *
	 * @return array<string, mixed> The record to store.
	 *
	 * @throws InvalidArgumentException When a check fails; the message names the column.
	 */
	private function checked(array $view, array $table): array {
		$kind = (string)($view['kind'] ?? '');
		if (in_array($kind, self::KINDS, true) === false) {
			throw new InvalidArgumentException('A view is a table, bar, line or map, not "'.$kind.'".');
		}

		if (trim((string)($view['title'] ?? '')) === '') {
			throw new InvalidArgumentException('A view needs a title.');
		}

		$columns = $this->columnTypes(table: $table);
		$record = array_intersect_key($view, array_flip(self::FIELDS));
		$record['table'] = self::idOf(record: $table);
		$record['title'] = trim((string)$view['title']);

		return match ($kind) {
			'table' => ViewColumnRules::table(record: $record, columns: $columns),
			'map' => ViewColumnRules::map(record: $record, columns: $columns),
			default => ViewColumnRules::chart(record: $record, columns: $columns),
		};
	}//end checked()

	/**
	 * The type of each column of a table, keyed by column name.
	 *
	 * @param array<string, mixed> $table The table.
	 *
	 * @return array<string, bool> Whether each column is numeric.
	 */
	private function columnTypes(array $table): array {
		$columns = [];
		foreach ($this->tables->dictionary(table: $table) as $column) {
			$columns[(string)$column['name']] = in_array((string)($column['type'] ?? ''), self::NUMERIC, true);
		}

		return $columns;
	}//end columnTypes()

	/**
	 * The register and schema the views are stored in.
	 *
	 * @return array{register: mixed, schema: mixed} The pair.
	 */
	private function config(): array {
		return $this->registers->registerConfiguration(registerKey: 'publication_register', schemaKey: 'table_view_schema');
	}//end config()

	/**
	 * The view records, read and written as the system.
	 *
	 * @return SchemaObjects The bound objects.
	 */
	private function views(): SchemaObjects {
		$config = $this->config();

		return new SchemaObjects(objects: $this->registers->objectService(), target: [$config['register'], $config['schema']]);
	}//end views()

	/**
	 * The id of a record.
	 *
	 * @param array<string, mixed> $record The record.
	 *
	 * @return string The id, or ''.
	 */
	private static function idOf(array $record): string {
		return (string)($record['id'] ?? $record['@self']['id'] ?? '');
	}//end idOf()

	/**
	 * An OpenRegister entity as its array, anything else as it is.
	 *
	 * @param mixed $value The value.
	 *
	 * @return mixed The plain value.
	 */
	private static function plain(mixed $value): mixed {
		if ($value instanceof JsonSerializable) {
			return $value->jsonSerialize();
		}

		return $value;
	}//end plain()
}//end class
