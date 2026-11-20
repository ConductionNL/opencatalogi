<?php

/**
 * Unit tests for DatasetViewEditController.
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

namespace Unit\Controller;

use InvalidArgumentException;
use OCA\OpenCatalogi\Contract\DatasetViewServiceInterface;
use OCA\OpenCatalogi\Contract\PublishedTableServiceInterface;
use OCA\OpenCatalogi\Controller\DatasetViewEditController;
use OCA\OpenCatalogi\Controller\PublicationWriteGuard;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IL10N;
use OCP\IRequest;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Every editor action passes the publication's write guard and acts only on that publication's ready table.
 *
 * @covers \OCA\OpenCatalogi\Controller\DatasetViewEditController
 */
class DatasetViewEditControllerTest extends TestCase {

	/** @var IRequest&MockObject */
	private IRequest $request;

	/** @var PublicationWriteGuard&MockObject */
	private PublicationWriteGuard $guard;

	/** @var DatasetViewServiceInterface&MockObject */
	private DatasetViewServiceInterface $views;

	private DatasetViewEditController $controller;

	/**
	 * Wire the controller to mocks; table t1 is publication p1's ready table.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->request = $this->createMock(IRequest::class);
		$this->guard = $this->createMock(PublicationWriteGuard::class);
		$this->views = $this->createMock(DatasetViewServiceInterface::class);
		$tables = $this->createMock(PublishedTableServiceInterface::class);
		$tables->method('findTable')->willReturnCallback(
			static fn (string $id): ?array => match ($id) {
				't1' => ['publication' => 'p1', 'status' => 'ready', 'schema' => '42'],
				't2' => ['publication' => 'p1', 'status' => 'importing', 'schema' => '43'],
				default => null,
			}
		);
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(static fn (string $text, array $params = []): string => vsprintf($text, $params));

		$this->controller = new DatasetViewEditController(
			appName: 'opencatalogi',
			request: $this->request,
			tables: $tables,
			views: $this->views,
			guard: $this->guard,
			l10n: $l10n
		);
	}//end setUp()

	/**
	 * Let the guard allow the write.
	 *
	 * @return void
	 */
	private function allowed(): void {
		$this->guard->method('guard')->willReturn([new \stdClass(), null]);
	}//end allowed()

	/**
	 * The guard's refusal is the answer, and no view is touched.
	 *
	 * @return void
	 */
	public function testTheWriteGuardsRefusalIsTheAnswer(): void {
		$refusal = new JSONResponse(['error' => 'forbidden'], 403);
		$this->guard->method('guard')->with('p1')->willReturn([null, $refusal]);
		$this->views->expects($this->never())->method('save');
		$this->views->expects($this->never())->method('remove');

		$this->assertSame($refusal, $this->controller->save(id: 'p1', tableId: 't1'));
		$this->assertSame($refusal, $this->controller->remove(id: 'p1', tableId: 't1', viewId: 'v1'));
		$this->assertSame($refusal, $this->controller->index(id: 'p1', tableId: 't1'));
	}//end testTheWriteGuardsRefusalIsTheAnswer()

	/**
	 * A table of another publication, or one not yet ready, answers 404.
	 *
	 * @return void
	 */
	public function testATableOfAnotherPublicationOrNotReadyIs404(): void {
		$this->allowed();
		$this->views->expects($this->never())->method('save');

		$this->assertSame(404, $this->controller->save(id: 'p2', tableId: 't1')->getStatus());
		$this->assertSame(404, $this->controller->save(id: 'p1', tableId: 't2')->getStatus());
		$this->assertSame(404, $this->controller->save(id: 'p1', tableId: 'nope')->getStatus());
	}//end testATableOfAnotherPublicationOrNotReadyIs404()

	/**
	 * A new view is saved from the body without the route parameters and answers 201; a refused one 400.
	 *
	 * @return void
	 */
	public function testANewViewIsSavedAndARefusedOneIs400(): void {
		$this->allowed();
		$this->request->method('getParams')->willReturn(['id' => 'p1', 'tableId' => 't1', '_route' => 'r', 'kind' => 'bar', 'title' => 'Per wijk', 'x' => 'wijk']);
		$this->views->expects($this->exactly(2))->method('save')
			->with(['kind' => 'bar', 'title' => 'Per wijk', 'x' => 'wijk'], ['publication' => 'p1', 'status' => 'ready', 'schema' => '42', 'id' => 't1'])
			->willReturnOnConsecutiveCalls(['id' => 'v1'], $this->throwException(new InvalidArgumentException('This table has no column named "wijk".')));

		$created = $this->controller->save(id: 'p1', tableId: 't1');
		$this->assertSame([201, ['id' => 'v1']], [$created->getStatus(), $created->getData()]);

		$refused = $this->controller->save(id: 'p1', tableId: 't1');
		$this->assertSame(400, $refused->getStatus());
		$this->assertSame('This table has no column named "wijk".', $refused->getData()['message']);
	}//end testANewViewIsSavedAndARefusedOneIs400()

	/**
	 * Changing or removing a view of another table answers 404; one of this table is changed or removed.
	 *
	 * @return void
	 */
	public function testOnlyAViewOfThisTableIsChangedOrRemoved(): void {
		$this->allowed();
		$this->request->method('getParams')->willReturn(['viewId' => 'v9', 'kind' => 'table', 'title' => 'T', 'columns' => ['naam']]);
		$this->views->method('findView')->willReturnCallback(
			static fn (string $id): ?array => match ($id) {
				'v1' => ['id' => 'v1', 'table' => 't1'],
				'v9' => ['id' => 'v9', 'table' => 't9'],
				default => null,
			}
		);
		$this->views->expects($this->never())->method('save');
		$this->views->expects($this->once())->method('remove')->with('v1');

		$this->assertSame(404, $this->controller->save(id: 'p1', tableId: 't1')->getStatus());
		$this->assertSame(404, $this->controller->remove(id: 'p1', tableId: 't1', viewId: 'v9')->getStatus());
		$this->assertSame(204, $this->controller->remove(id: 'p1', tableId: 't1', viewId: 'v1')->getStatus());
	}//end testOnlyAViewOfThisTableIsChangedOrRemoved()

	/**
	 * Changing a view of this table saves it under its id and answers 200; the list answers the table's views.
	 *
	 * @return void
	 */
	public function testAViewOfThisTableIsChangedUnderItsId(): void {
		$this->allowed();
		$this->request->method('getParams')->willReturn(['viewId' => 'v1', 'kind' => 'table', 'title' => 'T', 'columns' => ['naam']]);
		$this->views->method('findView')->willReturn(['id' => 'v1', 'table' => 't1']);
		$this->views->expects($this->once())->method('save')
			->with(['kind' => 'table', 'title' => 'T', 'columns' => ['naam'], 'id' => 'v1'], $this->anything())
			->willReturn(['id' => 'v1']);
		$this->views->method('viewsOf')->with('t1')->willReturn([['id' => 'v1']]);

		$this->assertSame(200, $this->controller->save(id: 'p1', tableId: 't1')->getStatus());
		$this->assertSame(['table' => 't1', 'results' => [['id' => 'v1']]], $this->controller->index(id: 'p1', tableId: 't1')->getData());
	}//end testAViewOfThisTableIsChangedUnderItsId()
}//end class
