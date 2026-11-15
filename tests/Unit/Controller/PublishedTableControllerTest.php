<?php

/**
 * Unit tests for PublishedTableController.
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
 */

declare(strict_types=1);

namespace Unit\Controller;

use OCA\OpenCatalogi\Contract\CatalogiServiceInterface;
use OCA\OpenCatalogi\Contract\PublishedTableServiceInterface;
use OCA\OpenCatalogi\Controller\PublishedTableController;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IAppConfig;
use OCP\IL10N;
use OCP\IRequest;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * The public rows and dictionary answers of a published table.
 *
 * @covers \OCA\OpenCatalogi\Controller\PublishedTableController
 * @uses   \OCA\OpenCatalogi\Controller\CorsAllowlist
 */
class PublishedTableControllerTest extends TestCase {

	private const TABLE = ['id' => 't1', 'publication' => 'p1', 'status' => 'ready', 'title' => 'speeltuinen.csv', 'schema' => '42'];

	/** @var IRequest&MockObject */
	private IRequest $request;

	/** @var CatalogiServiceInterface&MockObject */
	private CatalogiServiceInterface $catalogs;

	/** @var PublishedTableServiceInterface&MockObject */
	private PublishedTableServiceInterface $tables;

	private PublishedTableController $controller;

	/**
	 * Wire the controller to mocks.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->request = $this->createMock(IRequest::class);
		$this->request->method('getHeader')->willReturn('');
		$this->catalogs = $this->createMock(CatalogiServiceInterface::class);
		$this->tables = $this->createMock(PublishedTableServiceInterface::class);
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(static fn (string $text, array $params = []): string => vsprintf($text, $params));
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturn('*');

		$this->controller = new PublishedTableController(
			appName: 'opencatalogi',
			request: $this->request,
			catalogs: $this->catalogs,
			tables: $this->tables,
			l10n: $l10n,
			appConfig: $appConfig
		);
	}//end setUp()

	/**
	 * A readable table in a known catalogue.
	 *
	 * @param array<string, mixed> $table The table record.
	 *
	 * @return void
	 */
	private function readable(array $table = self::TABLE): void {
		$this->catalogs->method('getCatalogBySlug')->with('speeltuinen')->willReturn(['id' => 'c1']);
		$this->tables->method('findTable')->with('t1')->willReturn($table);
		$this->tables->method('canReadPublication')->with('p1')->willReturn(true);
	}//end readable()

	/**
	 * Rows of a public table pass the request's query and carry CORS headers.
	 *
	 * @return void
	 */
	public function testRowsOfAReadableTable(): void {
		$this->readable();
		$query = ['wijk' => 'Centrum', '_limit' => '10'];
		$this->request->method('getParams')->willReturn($query);
		$this->tables->method('undeclaredFilters')->with($query, self::TABLE)->willReturn([]);
		$this->tables->expects($this->once())->method('rows')->with(self::TABLE, $query)
			->willReturn(['results' => [['wijk' => 'Centrum']], 'total' => 1, 'page' => 1, 'pages' => 1]);

		$response = $this->controller->rows(catalogSlug: 'speeltuinen', publicationId: 'p1', tableId: 't1');

		$this->assertInstanceOf(JSONResponse::class, $response);
		$this->assertSame(200, $response->getStatus());
		$this->assertSame(1, $response->getData()['total']);
		$this->assertSame('*', $response->getHeaders()['Access-Control-Allow-Origin']);
		$this->assertSame('GET, OPTIONS', $response->getHeaders()['Access-Control-Allow-Methods']);
	}//end testRowsOfAReadableTable()

	/**
	 * A filter on an unknown column is 400 naming it, and no rows are read.
	 *
	 * @return void
	 */
	public function testAnUnknownColumnIsRefused(): void {
		$this->readable();
		$this->request->method('getParams')->willReturn(['kleur' => 'rood']);
		$this->tables->method('undeclaredFilters')->willReturn(['kleur']);
		$this->tables->expects($this->never())->method('rows');

		$response = $this->controller->rows(catalogSlug: 'speeltuinen', publicationId: 'p1', tableId: 't1');

		$this->assertSame(400, $response->getStatus());
		$this->assertSame('unknown-column', $response->getData()['error']);
		$this->assertSame(['kleur'], $response->getData()['columns']);
		$this->assertSame('This table has no column named kleur.', $response->getData()['message']);
	}//end testAnUnknownColumnIsRefused()

	/**
	 * A publication the caller cannot read hides its table's rows.
	 *
	 * @return void
	 */
	public function testAnUnreadablePublicationHidesTheRows(): void {
		$this->catalogs->method('getCatalogBySlug')->willReturn(['id' => 'c1']);
		$this->tables->method('findTable')->willReturn(self::TABLE);
		$this->tables->method('canReadPublication')->with('p1')->willReturn(false);
		$this->tables->expects($this->never())->method('rows');

		$response = $this->controller->rows(catalogSlug: 'speeltuinen', publicationId: 'p1', tableId: 't1');

		$this->assertSame(404, $response->getStatus());
		$this->assertSame('unknown-table', $response->getData()['error']);
	}//end testAnUnreadablePublicationHidesTheRows()

	/**
	 * An unknown catalogue, a missing table, another publication's table and an unfinished table are all 404.
	 *
	 * @return void
	 */
	public function testTablesThatCannotBeServedAreNotFound(): void {
		$cases = [
			[null, self::TABLE],
			[['id' => 'c1'], null],
			[['id' => 'c1'], ['publication' => 'p2'] + self::TABLE],
			[['id' => 'c1'], ['status' => 'failed'] + self::TABLE],
			[['id' => 'c1'], ['status' => 'importing'] + self::TABLE],
		];

		foreach ($cases as [$catalog, $table]) {
			$catalogs = $this->createMock(CatalogiServiceInterface::class);
			$catalogs->method('getCatalogBySlug')->willReturn($catalog);
			$tables = $this->createMock(PublishedTableServiceInterface::class);
			$tables->method('findTable')->willReturn($table);
			$tables->method('canReadPublication')->willReturn(true);
			$tables->expects($this->never())->method('rows');
			$tables->expects($this->never())->method('dictionary');
			$l10n = $this->createMock(IL10N::class);
			$l10n->method('t')->willReturnArgument(0);
			$controller = new PublishedTableController('opencatalogi', $this->request, $catalogs, $tables, $l10n);

			$this->assertSame(404, $controller->rows('speeltuinen', 'p1', 't1')->getStatus());
			$this->assertSame(404, $controller->dictionary('speeltuinen', 'p1', 't1')->getStatus());
		}
	}//end testTablesThatCannotBeServedAreNotFound()

	/**
	 * The dictionary answers the table's columns.
	 *
	 * @return void
	 */
	public function testTheDictionaryOfAReadableTable(): void {
		$this->readable();
		$columns = [['name' => 'toestellen', 'title' => 'Toestellen', 'description' => 'Number of play devices on the site', 'type' => 'integer']];
		$this->tables->method('dictionary')->with(self::TABLE)->willReturn($columns);

		$response = $this->controller->dictionary(catalogSlug: 'speeltuinen', publicationId: 'p1', tableId: 't1');

		$this->assertSame(200, $response->getStatus());
		$this->assertSame(['table' => 't1', 'title' => 'speeltuinen.csv', 'columns' => $columns], $response->getData());
	}//end testTheDictionaryOfAReadableTable()

	/**
	 * The preflight answers the read-only CORS set.
	 *
	 * @return void
	 */
	public function testThePreflightIsReadOnly(): void {
		$headers = $this->controller->preflightedCors()->getHeaders();

		$this->assertSame('GET, OPTIONS', $headers['Access-Control-Allow-Methods']);
		$this->assertSame('false', $headers['Access-Control-Allow-Credentials']);
	}//end testThePreflightIsReadOnly()
}//end class
