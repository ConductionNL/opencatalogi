<?php

/**
 * Unit tests for DatasetViewController and the data its views answer.
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
 * @spec openspec/changes/dataset-views-on-the-public-page/specs/open-data-tables/spec.md#requirement-anyone-reads-a-view-s-data-while-the-publication-is-public-req-dsv-002
 */

declare(strict_types=1);

namespace Unit\Controller;

use OCA\OpenCatalogi\Contract\CatalogiServiceInterface;
use OCA\OpenCatalogi\Contract\OpenRegisterLookupInterface;
use OCA\OpenCatalogi\Contract\PublishedTableServiceInterface;
use OCA\OpenCatalogi\Controller\DatasetViewController;
use OCA\OpenCatalogi\Service\Table\DatasetViewData;
use OCA\OpenCatalogi\Service\Table\DatasetViewReader;
use OCA\OpenCatalogi\Service\Table\DatasetViewService;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Search\Service\Aggregation\AggregationQuery;
use OCA\OpenRegister\Service\Aggregation\AggregationRunnerInterface;
use OCA\OpenRegister\Service\ObjectService;
use OCP\IAppConfig;
use OCP\IL10N;
use OCP\IRequest;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * The public view endpoints answer only while the publication is readable, and
 * shape each kind of view as design D3 says.
 *
 * @covers \OCA\OpenCatalogi\Controller\DatasetViewController
 * @covers \OCA\OpenCatalogi\Service\Table\DatasetViewData
 * @covers \OCA\OpenCatalogi\Service\Table\MapPoints
 * @covers \OCA\OpenCatalogi\Service\Table\DatasetViewReader
 * @uses   \OCA\OpenCatalogi\Service\Table\DatasetViewService
 * @uses   \OCA\OpenCatalogi\Service\SchemaObjects
 * @uses   \OCA\OpenCatalogi\Controller\CorsAllowlist
 */
class DatasetViewControllerTest extends TestCase {

	private const TABLE = ['id' => 't1', 'publication' => 'p1', 'status' => 'ready', 'title' => 'speeltuinen.csv', 'schema' => '42'];

	/** @var CatalogiServiceInterface&MockObject */
	private CatalogiServiceInterface $catalogs;

	/** @var PublishedTableServiceInterface&MockObject */
	private PublishedTableServiceInterface $tables;

	/** @var ObjectService&MockObject */
	private ObjectService $objects;

	/** @var AggregationRunnerInterface&MockObject */
	private AggregationRunnerInterface $aggregation;

	private DatasetViewController $controller;

	/**
	 * Wire the controller to the real view service over mocked OpenRegister boundaries.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$request = $this->createMock(IRequest::class);
		$request->method('getHeader')->willReturn('');
		$this->catalogs = $this->createMock(CatalogiServiceInterface::class);
		$this->catalogs->method('getCatalogBySlug')->willReturnCallback(static fn (string $slug): ?array => $slug === 'c' ? ['id' => 'c'] : null);
		$this->tables = $this->createMock(PublishedTableServiceInterface::class);
		$this->tables->method('findTable')->willReturnCallback(static fn (string $id): ?array => $id === 't1' ? self::TABLE : null);
		$this->objects = $this->createMock(ObjectService::class);
		$registers = $this->createMock(OpenRegisterLookupInterface::class);
		$registers->method('objectService')->willReturn($this->objects);
		$registers->method('registerConfiguration')->willReturn(['register' => 'reg', 'schema' => 'tv']);
		$this->aggregation = $this->createMock(AggregationRunnerInterface::class);
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(static fn (string $text, array $params = []): string => vsprintf($text, $params));
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturn('*');

		$this->controller = new DatasetViewController(
			appName: 'opencatalogi',
			request: $request,
			reader: new DatasetViewReader(
				catalogs: $this->catalogs,
				tables: $this->tables,
				views: new DatasetViewService(
					registers: $registers,
					tables: $this->tables,
					shapes: new DatasetViewData(tables: $this->tables, aggregation: $this->aggregation)
				),
				logger: $this->createMock(LoggerInterface::class)
			),
			l10n: $l10n,
			appConfig: $appConfig
		);
	}//end setUp()

	/**
	 * Store one view for the find() double.
	 *
	 * @param array<string, mixed> $view The view.
	 *
	 * @return void
	 */
	private function storedView(array $view): void {
		$entity = $this->createMock(ObjectEntity::class);
		$entity->method('jsonSerialize')->willReturn($view);
		$this->objects->method('find')->willReturn($entity);
	}//end storedView()

	/**
	 * A view of a publication the caller may no longer read answers 404, and nothing is read.
	 *
	 * @return void
	 */
	public function testAViewOfANonPublicPublicationIs404(): void {
		$this->tables->method('canReadPublication')->with('p1')->willReturn(false);
		$this->storedView(view: ['id' => 'v1', 'table' => 't1', 'kind' => 'bar', 'x' => 'wijk']);
		$this->aggregation->expects($this->never())->method('runAdhocByRef');
		$this->tables->expects($this->never())->method('rows');

		$this->assertSame(404, $this->controller->data(catalogSlug: 'c', publicationId: 'p1', tableId: 't1', viewId: 'v1')->getStatus());
		$this->assertSame(404, $this->controller->index(catalogSlug: 'c', publicationId: 'p1', tableId: 't1')->getStatus());
	}//end testAViewOfANonPublicPublicationIs404()

	/**
	 * A view of another table, a table of another publication or an unknown catalogue answers 404.
	 *
	 * @return void
	 */
	public function testAViewOutsideItsTableOrPublicationIs404(): void {
		$this->tables->method('canReadPublication')->willReturn(true);
		$this->storedView(view: ['id' => 'v9', 'table' => 't9', 'kind' => 'bar', 'x' => 'wijk']);
		$this->aggregation->expects($this->never())->method('runAdhocByRef');

		$this->assertSame(404, $this->controller->data(catalogSlug: 'c', publicationId: 'p1', tableId: 't1', viewId: 'v9')->getStatus());
		$this->assertSame(404, $this->controller->data(catalogSlug: 'c', publicationId: 'p2', tableId: 't1', viewId: 'v9')->getStatus());
		$this->assertSame(404, $this->controller->data(catalogSlug: 'x', publicationId: 'p1', tableId: 't1', viewId: 'v9')->getStatus());
	}//end testAViewOutsideItsTableOrPublicationIs404()

	/**
	 * A bar view answers one value per group from OpenRegister's grouped aggregation on the table's schema.
	 *
	 * @return void
	 */
	public function testABarViewAnswersOneValuePerGroup(): void {
		$this->tables->method('canReadPublication')->willReturn(true);
		$this->storedView(view: ['id' => 'v1', 'table' => 't1', 'kind' => 'bar', 'title' => 'Per wijk', 'x' => 'wijk', 'y' => '', 'aggregate' => 'count']);
		$this->aggregation->expects($this->once())->method('runAdhocByRef')
			->with(
				'open-data-tables',
				'42',
				$this->callback(static fn (AggregationQuery $query): bool => $query->metric === 'count' && $query->field === null),
				true
			)
			->willReturn(['groups' => [['key' => 'Noord', 'value' => 7], ['key' => 'Zuid', 'value' => 3]]]);

		$response = $this->controller->data(catalogSlug: 'c', publicationId: 'p1', tableId: 't1', viewId: 'v1');

		$this->assertSame(200, $response->getStatus());
		$this->assertSame(
			[
				'view' => 'v1',
				'title' => 'Per wijk',
				'kind' => 'bar',
				'x' => 'wijk',
				'y' => '',
				'aggregate' => 'count',
				'points' => [['x' => 'Noord', 'value' => 7], ['x' => 'Zuid', 'value' => 3]],
				'truncated' => false,
			],
			$response->getData()
		);
	}//end testABarViewAnswersOneValuePerGroup()

	/**
	 * More than 200 groups answers the first 200 and says it truncated.
	 *
	 * @return void
	 */
	public function testMoreThan200GroupsAnswersTruncated(): void {
		$this->tables->method('canReadPublication')->willReturn(true);
		$this->storedView(view: ['id' => 'v1', 'table' => 't1', 'kind' => 'line', 'x' => 'jaar', 'y' => 'bedrag', 'aggregate' => 'sum']);
		$groups = array_map(static fn (int $i): array => ['key' => (string)$i, 'value' => $i], range(1, 201));
		$this->aggregation->method('runAdhocByRef')
			->with($this->anything(), $this->anything(), $this->callback(static fn (AggregationQuery $query): bool => $query->metric === 'sum' && $query->field === 'bedrag'))
			->willReturn(['groups' => $groups]);

		$data = $this->controller->data(catalogSlug: 'c', publicationId: 'p1', tableId: 't1', viewId: 'v1')->getData();

		$this->assertCount(200, $data['points']);
		$this->assertSame(['x' => '200', 'value' => 200], $data['points'][199]);
		$this->assertTrue($data['truncated']);
	}//end testMoreThan200GroupsAnswersTruncated()

	/**
	 * A map leaves out the rows without valid coordinates and counts them in `skipped`.
	 *
	 * @return void
	 */
	public function testAMapViewSkipsRowsWithoutCoordinates(): void {
		$this->tables->method('canReadPublication')->willReturn(true);
		$this->storedView(view: ['id' => 'v2', 'table' => 't1', 'kind' => 'map', 'latitude' => 'lat', 'longitude' => 'lon', 'label' => 'naam']);
		$this->tables->expects($this->once())->method('rows')
			->with(self::TABLE, ['_limit' => 1000, '_page' => 1])
			->willReturn(
				[
					'results' => [
						['naam' => 'Plein', 'lat' => '52.1', 'lon' => '5.1'],
						['naam' => 'Leeg', 'lat' => '', 'lon' => '5.2'],
						['naam' => 'Geen', 'lat' => null, 'lon' => '5.3'],
						['naam' => 'Tekst', 'lat' => 'noord', 'lon' => '5.4'],
						['naam' => 'Buiten', 'lat' => '95', 'lon' => '5.5'],
						['naam' => 'Park', 'lat' => 52.2, 'lon' => 5.2],
					],
					'pages' => 1,
				]
			);

		$data = $this->controller->data(catalogSlug: 'c', publicationId: 'p1', tableId: 't1', viewId: 'v2')->getData();

		$this->assertSame('FeatureCollection', $data['type']);
		$this->assertSame(4, $data['skipped']);
		$this->assertFalse($data['truncated']);
		$this->assertSame(
			[
				['type' => 'Feature', 'geometry' => ['type' => 'Point', 'coordinates' => [5.1, 52.1]], 'properties' => ['label' => 'Plein']],
				['type' => 'Feature', 'geometry' => ['type' => 'Point', 'coordinates' => [5.2, 52.2]], 'properties' => ['label' => 'Park']],
			],
			$data['features']
		);
	}//end testAMapViewSkipsRowsWithoutCoordinates()

	/**
	 * A table view answers the first page of rows with only its columns.
	 *
	 * @return void
	 */
	public function testATableViewAnswersTheFirstPageWithOnlyItsColumns(): void {
		$this->tables->method('canReadPublication')->willReturn(true);
		$this->storedView(view: ['id' => 'v3', 'table' => 't1', 'kind' => 'table', 'columns' => ['naam', 'wijk']]);
		$this->tables->method('rows')->with(self::TABLE, ['_limit' => 20, '_page' => 1])->willReturn(
			['results' => [['naam' => 'Plein', 'wijk' => 'Noord', 'lat' => '52.1']], 'total' => 1, 'page' => 1, 'pages' => 1]
		);

		$data = $this->controller->data(catalogSlug: 'c', publicationId: 'p1', tableId: 't1', viewId: 'v3')->getData();

		$this->assertSame(['naam', 'wijk'], $data['columns']);
		$this->assertSame([['naam' => 'Plein', 'wijk' => 'Noord']], $data['results']);
		$this->assertSame(1, $data['total']);
	}//end testATableViewAnswersTheFirstPageWithOnlyItsColumns()

	/**
	 * A failing read answers 500 without the failure's text.
	 *
	 * @return void
	 */
	public function testAViewWhoseDataCannotBeReadAnswers500(): void {
		$this->tables->method('canReadPublication')->willReturn(true);
		$this->storedView(view: ['id' => 'v1', 'table' => 't1', 'kind' => 'bar', 'x' => 'wijk', 'aggregate' => 'count']);
		$this->aggregation->method('runAdhocByRef')->willThrowException(new \RuntimeException('database secret'));

		$response = $this->controller->data(catalogSlug: 'c', publicationId: 'p1', tableId: 't1', viewId: 'v1');

		$this->assertSame(500, $response->getStatus());
		$this->assertStringNotContainsString('secret', json_encode($response->getData(), JSON_THROW_ON_ERROR));
	}//end testAViewWhoseDataCannotBeReadAnswers500()
}//end class
