<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace Unit\Controller;

use OCA\OpenCatalogi\Contract\SitemapServiceInterface;
use OCA\OpenCatalogi\Controller\SitemapController;
use OCA\OpenCatalogi\Http\XMLResponse;
use OCP\IRequest;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for SitemapController.
 */
class SitemapControllerTest extends TestCase {

	private IRequest|MockObject $request;
	private SitemapServiceInterface|MockObject $sitemapService;
	private SitemapController $controller;

	protected function setUp(): void {
		$this->request = $this->createMock(IRequest::class);
		$this->sitemapService = $this->createMock(SitemapServiceInterface::class);

		$this->controller = new SitemapController(
			'opencatalogi',
			$this->request,
			$this->sitemapService
		);
	}

	public function testIndexReturnsSitemapIndex(): void {
		$expectedResponse = $this->createMock(XMLResponse::class);

		$this->sitemapService->expects($this->once())
			->method('buildSitemapIndex')
			->with('test-catalog', 'sitemapindex-diwoo-infocat001.xml')
			->willReturn($expectedResponse);

		$response = $this->controller->index('test-catalog', 'sitemapindex-diwoo-infocat001.xml');

		$this->assertSame($expectedResponse, $response);
	}

	public function testIndexWithDifferentCategory(): void {
		$expectedResponse = $this->createMock(XMLResponse::class);

		$this->sitemapService->expects($this->once())
			->method('buildSitemapIndex')
			->with('my-catalog', 'sitemapindex-diwoo-infocat005.xml')
			->willReturn($expectedResponse);

		$response = $this->controller->index('my-catalog', 'sitemapindex-diwoo-infocat005.xml');

		$this->assertSame($expectedResponse, $response);
	}

	public function testIndexPassesCatalogSlugCorrectly(): void {
		$expectedResponse = $this->createMock(XMLResponse::class);

		$this->sitemapService->expects($this->once())
			->method('buildSitemapIndex')
			->with('catalog-with-dashes', 'sitemapindex-diwoo-infocat010.xml')
			->willReturn($expectedResponse);

		$response = $this->controller->index('catalog-with-dashes', 'sitemapindex-diwoo-infocat010.xml');

		$this->assertSame($expectedResponse, $response);
	}

	public function testSitemapReturnsSitemapPage(): void {
		$expectedResponse = $this->createMock(XMLResponse::class);

		$this->request->method('getParams')
			->willReturn(['page' => 1]);

		$this->sitemapService->expects($this->once())
			->method('buildSitemap')
			->with('test-catalog', 'sitemapindex-diwoo-infocat001.xml', 1)
			->willReturn($expectedResponse);

		$response = $this->controller->sitemap('test-catalog', 'sitemapindex-diwoo-infocat001.xml');

		$this->assertSame($expectedResponse, $response);
	}

	public function testSitemapWithSpecificPage(): void {
		$expectedResponse = $this->createMock(XMLResponse::class);

		$this->request->method('getParams')
			->willReturn(['page' => 3]);

		$this->sitemapService->expects($this->once())
			->method('buildSitemap')
			->with('test-catalog', 'sitemapindex-diwoo-infocat002.xml', 3)
			->willReturn($expectedResponse);

		$response = $this->controller->sitemap('test-catalog', 'sitemapindex-diwoo-infocat002.xml');

		$this->assertSame($expectedResponse, $response);
	}

	public function testSitemapDefaultsToPage1WhenNoPageParam(): void {
		$expectedResponse = $this->createMock(XMLResponse::class);

		$this->request->method('getParams')
			->willReturn([]);

		$this->sitemapService->expects($this->once())
			->method('buildSitemap')
			->with('test-catalog', 'sitemapindex-diwoo-infocat001.xml', 1)
			->willReturn($expectedResponse);

		$response = $this->controller->sitemap('test-catalog', 'sitemapindex-diwoo-infocat001.xml');

		$this->assertSame($expectedResponse, $response);
	}

	public function testSitemapWithStringPageParam(): void {
		$expectedResponse = $this->createMock(XMLResponse::class);

		$this->request->method('getParams')
			->willReturn(['page' => '5']);

		$this->sitemapService->expects($this->once())
			->method('buildSitemap')
			->with('test-catalog', 'sitemapindex-diwoo-infocat003.xml', 5)
			->willReturn($expectedResponse);

		$response = $this->controller->sitemap('test-catalog', 'sitemapindex-diwoo-infocat003.xml');

		$this->assertSame($expectedResponse, $response);
	}

	public function testSitemapWithZeroPageParam(): void {
		$expectedResponse = $this->createMock(XMLResponse::class);

		$this->request->method('getParams')
			->willReturn(['page' => 0]);

		$this->sitemapService->expects($this->once())
			->method('buildSitemap')
			->with('test-catalog', 'sitemapindex-diwoo-infocat001.xml', 0)
			->willReturn($expectedResponse);

		$response = $this->controller->sitemap('test-catalog', 'sitemapindex-diwoo-infocat001.xml');

		$this->assertSame($expectedResponse, $response);
	}

	/**
	 * REQ-DWP-004: the validate route answers the report of a held-back document,
	 * with its location, the publisher field and the reason.
	 *
	 * @spec openspec/changes/diwoo-metadata-on-the-publication/specs/woo-compliance/spec.md#requirement-the-sitemap-emits-the-stored-diwoo-values-req-dwp-004
	 */
	public function testTheValidateRouteReportsAHeldBackDocument(): void {
		$routes = (string)file_get_contents(__DIR__ . '/../../../appinfo/routes.php');
		$this->assertStringContainsString("['name' => 'sitemap#diwooReport', 'url' => '/api/{catalogSlug}/sitemaps/{categoryCode}/validate', 'verb' => 'GET']", $routes);

		$violation = ['documentLoc' => 'https://example.com/zonder-tooi.pdf', 'axis' => 'publisher', 'reason' => 'organisation has no TOOI identifier'];
		$report = ['catalogSlug' => 'woo', 'categoryCode' => 'sitemapindex-diwoo-infocat012.xml', 'valid' => false, 'violations' => [$violation]];
		$this->request->method('getParams')->willReturn(['page' => '2']);
		$this->sitemapService->expects($this->once())
			->method('validateDiwooOutput')
			->with('woo', 'sitemapindex-diwoo-infocat012.xml', 2)
			->willReturn($report);

		$response = $this->controller->diwooReport('woo', 'sitemapindex-diwoo-infocat012.xml');

		$this->assertSame(200, $response->getStatus());
		$this->assertSame([$violation], $response->getData()['violations']);
	}
}
