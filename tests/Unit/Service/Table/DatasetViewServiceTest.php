<?php

/**
 * Unit tests for DatasetViewService and its column rules.
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

namespace Unit\Service\Table;

use InvalidArgumentException;
use OCA\OpenCatalogi\Contract\OpenRegisterLookupInterface;
use OCA\OpenCatalogi\Contract\PublishedTableServiceInterface;
use OCA\OpenCatalogi\Service\Table\DatasetViewData;
use OCA\OpenCatalogi\Service\Table\DatasetViewService;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Kernel\Contracts\Value\ObjectSaveOptions;
use OCA\OpenRegister\Service\Aggregation\AggregationRunnerInterface;
use OCA\OpenRegister\Service\ObjectService;
use OCP\IURLGenerator;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Saving checks a view against its table's columns (design D2) and lists views by position.
 *
 * @covers \OCA\OpenCatalogi\Service\Table\DatasetViewService
 * @covers \OCA\OpenCatalogi\Service\Table\ViewColumnRules
 * @uses   \OCA\OpenCatalogi\Service\Table\DatasetViewData
 * @uses   \OCA\OpenCatalogi\Service\SchemaObjects
 */
class DatasetViewServiceTest extends TestCase {

	private const TABLE = ['id' => 't1', 'publication' => 'p1', 'status' => 'ready', 'schema' => '42'];

	private const COLUMNS = [
		['name' => 'naam', 'type' => 'string'],
		['name' => 'wijk', 'type' => 'string'],
		['name' => 'oppervlakte', 'type' => 'number'],
		['name' => 'lat', 'type' => 'number'],
		['name' => 'lon', 'type' => 'number'],
	];

	/** @var ObjectService&MockObject */
	private ObjectService $objects;

	/** @var IURLGenerator&MockObject */
	private IURLGenerator $urls;

	private DatasetViewService $service;

	/**
	 * Wire the service to an OpenRegister ObjectService double and a table with five columns.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->objects = $this->createMock(ObjectService::class);
		$registers = $this->createMock(OpenRegisterLookupInterface::class);
		$registers->method('objectService')->willReturn($this->objects);
		$registers->method('registerConfiguration')->willReturn(['register' => 'reg', 'schema' => 'tv']);
		$tables = $this->createMock(PublishedTableServiceInterface::class);
		$tables->method('dictionary')->willReturn(self::COLUMNS);
		$this->urls = $this->createMock(IURLGenerator::class);

		$this->service = new DatasetViewService(
			registers: $registers,
			tables: $tables,
			shapes: new DatasetViewData(tables: $tables, aggregation: $this->createMock(AggregationRunnerInterface::class)),
			urls: $this->urls
		);
	}//end setUp()

	/**
	 * An ObjectEntity double that serialises to the data.
	 *
	 * @param array<string, mixed> $data The data.
	 *
	 * @return ObjectEntity&MockObject The entity.
	 */
	private function entity(array $data): ObjectEntity {
		$entity = $this->createMock(ObjectEntity::class);
		$entity->method('jsonSerialize')->willReturn($data);

		return $entity;
	}//end entity()

	/**
	 * A bar view over a known column is stored with only the chart fields, as the system.
	 *
	 * @return void
	 */
	public function testABarViewOverAKnownColumnIsSaved(): void {
		$this->objects->expects($this->once())->method('saveObject')
			->with(
				['table' => 't1', 'kind' => 'bar', 'title' => 'Speeltuinen per wijk', 'position' => 0, 'x' => 'wijk', 'y' => '', 'aggregate' => 'count'],
				$this->callback(static fn (ObjectSaveOptions $options): bool => $options->uuid === null && $options->target->schema === 'tv'),
				false,
				false
			)
			->willReturn($this->entity(data: ['id' => 'v1', 'kind' => 'bar']));

		$saved = $this->service->save(
			view: ['kind' => 'bar', 'title' => ' Speeltuinen per wijk ', 'x' => 'wijk', 'aggregate' => 'count', 'latitude' => 'lat'],
			table: self::TABLE
		);

		$this->assertSame(['id' => 'v1', 'kind' => 'bar'], $saved);
	}//end testABarViewOverAKnownColumnIsSaved()

	/**
	 * A view over a column the table does not have is refused, and the message names it.
	 *
	 * @return void
	 */
	public function testAViewOverAnUnknownColumnIsRefusedWithItsName(): void {
		$this->objects->expects($this->never())->method('saveObject');

		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessage('This table has no column named "buurt".');

		$this->service->save(view: ['kind' => 'bar', 'title' => 'Per buurt', 'x' => 'buurt'], table: self::TABLE);
	}//end testAViewOverAnUnknownColumnIsRefusedWithItsName()

	/**
	 * A sum over a text column is refused, naming the column.
	 *
	 * @return void
	 */
	public function testASumOverATextColumnIsRefused(): void {
		$this->objects->expects($this->never())->method('saveObject');

		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessage('The column "naam" does not hold numbers.');

		$this->service->save(view: ['kind' => 'line', 'title' => 'Som', 'x' => 'wijk', 'y' => 'naam', 'aggregate' => 'sum'], table: self::TABLE);
	}//end testASumOverATextColumnIsRefused()

	/**
	 * A map needs numeric coordinate columns; a known label is kept; a table view needs known columns.
	 *
	 * @return void
	 */
	public function testAMapNeedsNumericCoordinatesAndATableKnownColumns(): void {
		$this->objects->expects($this->once())->method('saveObject')
			->with(['table' => 't1', 'kind' => 'map', 'title' => 'Kaart', 'position' => 2, 'latitude' => 'lat', 'longitude' => 'lon', 'label' => 'naam'])
			->willReturn($this->entity(data: ['id' => 'v2']));
		$this->service->save(view: ['kind' => 'map', 'title' => 'Kaart', 'latitude' => 'lat', 'longitude' => 'lon', 'label' => 'naam', 'position' => 2], table: self::TABLE);

		$refusals = [];
		foreach ([
			['kind' => 'map', 'title' => 'Kaart', 'latitude' => 'wijk', 'longitude' => 'lon'],
			['kind' => 'table', 'title' => 'Tabel', 'columns' => ['naam', 'straat']],
			['kind' => 'table', 'title' => 'Tabel', 'columns' => []],
			['kind' => 'pie', 'title' => 'Taart'],
			['kind' => 'bar', 'title' => '', 'x' => 'wijk'],
			['kind' => 'bar', 'title' => 'Mediaan', 'x' => 'wijk', 'aggregate' => 'median'],
		] as $view) {
			try {
				$this->service->save(view: $view, table: self::TABLE);
			} catch (InvalidArgumentException $e) {
				$refusals[] = $e->getMessage();
			}
		}

		$this->assertSame(
			[
				'The column "wijk" does not hold numbers.',
				'This table has no column named "straat".',
				'A table view shows at least one column.',
				'A view is a table, bar, line or map, not "pie".',
				'A view needs a title.',
				'A chart counts, sums or averages, not "median".',
			],
			$refusals
		);
	}//end testAMapNeedsNumericCoordinatesAndATableKnownColumns()

	/**
	 * Saving with an id updates that view.
	 *
	 * @return void
	 */
	public function testSavingWithAnIdUpdatesThatView(): void {
		$this->objects->expects($this->once())->method('saveObject')
			->with(
				$this->arrayHasKey('columns'),
				$this->callback(static fn (ObjectSaveOptions $options): bool => $options->uuid === 'v3')
			)
			->willReturn($this->entity(data: ['id' => 'v3']));

		$this->service->save(view: ['id' => 'v3', 'kind' => 'table', 'title' => 'Tabel', 'columns' => ['naam', 'wijk']], table: self::TABLE);
	}//end testSavingWithAnIdUpdatesThatView()

	/**
	 * The views of a table come by position, other tables' rows dropped, each listed with its data URL.
	 *
	 * @return void
	 */
	public function testTheViewsOfATableAreListedByPositionWithTheirDataUrl(): void {
		$this->objects->method('searchObjectsPaginated')->willReturn(
			[
				'results' => [
					['id' => 'v2', 'table' => 't1', 'kind' => 'map', 'title' => 'Kaart', 'position' => 2, 'latitude' => 'lat'],
					['id' => 'vX', 'table' => 't9', 'kind' => 'bar', 'title' => 'Ander', 'position' => 0],
					['id' => 'v1', 'table' => 't1', 'kind' => 'bar', 'title' => 'Per wijk', 'position' => 1, 'x' => 'wijk'],
				],
			]
		);
		$this->urls->method('linkToRouteAbsolute')->willReturnCallback(
			static fn (string $route, array $parameters): string => $route.'?'.http_build_query($parameters)
		);

		$this->assertSame(['v1', 'v2'], array_column($this->service->viewsOf(tableId: 't1'), 'id'));

		$listed = $this->service->withViews(tables: [['id' => 't1', 'title' => 'speeltuinen.csv']], where: ['catalogSlug' => 'c', 'publicationId' => 'p1']);

		$this->assertSame('speeltuinen.csv', $listed[0]['title']);
		$this->assertSame(
			['id' => 'v1', 'kind' => 'bar', 'title' => 'Per wijk', 'x' => 'wijk', 'position' => 1, 'dataUrl' => 'opencatalogi.datasetView.data?catalogSlug=c&publicationId=p1&tableId=t1&viewId=v1'],
			$listed[0]['views'][0]
		);
		$this->assertSame('map', $listed[0]['views'][1]['kind']);
	}//end testTheViewsOfATableAreListedByPositionWithTheirDataUrl()

	/**
	 * A view that cannot be found answers null.
	 *
	 * @return void
	 */
	public function testAMissingViewIsNull(): void {
		$this->objects->method('find')->willThrowException(new \RuntimeException('gone'));

		$this->assertNull($this->service->findView(viewId: 'nope'));
	}//end testAMissingViewIsNull()
}//end class
