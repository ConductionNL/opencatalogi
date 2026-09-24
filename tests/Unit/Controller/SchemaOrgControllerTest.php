<?php

/**
 * Unit tests for SchemaOrgController.
 *
 * Verifies the public schema.org DataCatalog endpoint: a visible catalog returns
 * a single JSON-LD document with `Content-Type: application/ld+json` and CORS
 * headers; an unknown catalog 404s.
 *
 * @category Test
 * @package  Unit\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2025 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2025 Conduction B.V. <info@conduction.nl>
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenCatalogi.nl
 */

declare(strict_types=1);

namespace Unit\Controller;

use OCA\OpenCatalogi\Controller\SchemaOrgController;
use OCA\OpenCatalogi\Service\CatalogiService;
use OCA\OpenCatalogi\Service\PublicationQueryService;
use OCA\OpenCatalogi\Service\SchemaOrgService;
use OCP\AppFramework\Http;
use OCP\IL10N;
use OCP\IRequest;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Unit tests for SchemaOrgController.
 */
class SchemaOrgControllerTest extends TestCase {

	private IRequest|MockObject $request;

	private SchemaOrgService|MockObject $schemaOrgService;

	private CatalogiService|MockObject $catalogiService;

	private PublicationQueryService|MockObject $queryService;

	/** @var \Closure(array): array The read-rule guard the query-service mock applies. */
	private \Closure $guard;

	private IL10N|MockObject $l10n;

	private LoggerInterface|MockObject $logger;

	private SchemaOrgController $controller;

	/**
	 * Set up fixtures.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->request = $this->createMock(IRequest::class);
		$this->schemaOrgService = $this->createMock(SchemaOrgService::class);
		$this->catalogiService = $this->createMock(CatalogiService::class);
		$this->l10n = $this->createMock(IL10N::class);
		$this->logger = $this->createMock(LoggerInterface::class);

		$this->request->method('getHeader')->willReturn('');
		$this->l10n->method('t')->willReturnArgument(0);

		// Pass-through by default; the WOO-581 tests below swap $this->guard.
		$this->guard = static fn (array $catalog): array => $catalog;
		$this->queryService = $this->createMock(PublicationQueryService::class);
		$this->queryService->method('applyCatalogReadRuleGuard')
			->willReturnCallback(fn (array $catalog): array => ($this->guard)($catalog));

		$this->controller = new SchemaOrgController(
			'opencatalogi',
			$this->request,
			$this->schemaOrgService,
			$this->catalogiService,
			$this->queryService,
			$this->l10n,
			$this->logger,
			null
		);

	}//end setUp()

	/**
	 * A visible catalog returns a JSON-LD DataCatalog node with CORS + ld+json.
	 *
	 * @return void
	 */
	public function testCatalogReturnsJsonLd(): void {
		$this->catalogiService->method('getCatalogBySlug')->with('woo')->willReturn(['title' => 'WOO']);
		$this->schemaOrgService->method('buildCatalogNode')->willReturn(
			['@context' => 'https://schema.org', '@type' => 'DataCatalog', 'name' => 'WOO']
		);

		$response = $this->controller->catalog('woo');

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$headers = $response->getHeaders();
		$this->assertSame('application/ld+json', $headers['Content-Type']);
		$this->assertArrayHasKey('Access-Control-Allow-Origin', $headers);
		$this->assertSame('DataCatalog', $response->getData()['@type']);

	}//end testCatalogReturnsJsonLd()

	/**
	 * WOO-581: the DataCatalog is built from the GUARDED catalog, so a schema
	 * the SCH-PFTS-CAT-002 guard drops never gets its rows listed as `dataset`.
	 *
	 * @return void
	 */
	public function testCatalogBuildsTheNodeFromTheReadRuleGuardedCatalog(): void {
		$this->catalogiService->method('getCatalogBySlug')->willReturn(['title' => 'WOO', 'registers' => [20], 'schemas' => [28, 56]]);
		$this->guard = static fn (array $catalog): array => array_merge($catalog, ['schemas' => [28]]);

		$this->schemaOrgService->expects($this->once())
			->method('buildCatalogNode')
			->with($this->callback(static fn (array $catalog): bool => $catalog['schemas'] === [28]), 'woo')
			->willReturn(['@context' => 'https://schema.org', '@type' => 'DataCatalog', 'dataset' => []]);

		$this->assertSame(Http::STATUS_OK, $this->controller->catalog('woo')->getStatus());
	}//end testCatalogBuildsTheNodeFromTheReadRuleGuardedCatalog()

	/**
	 * An unknown catalog 404s without invoking the renderer.
	 *
	 * @return void
	 */
	public function testUnknownCatalog404s(): void {
		$this->catalogiService->method('getCatalogBySlug')->willReturn(null);
		$this->schemaOrgService->expects($this->never())->method('buildCatalogNode');

		$response = $this->controller->catalog('missing');

		$this->assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());

	}//end testUnknownCatalog404s()
}//end class
