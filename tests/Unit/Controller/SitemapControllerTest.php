<?php

declare(strict_types=1);

namespace Unit\Controller;

use OCA\OpenCatalogi\Controller\SitemapController;
use OCA\OpenCatalogi\Http\XMLResponse;
use OCA\OpenCatalogi\Service\SitemapService;
use OCP\IRequest;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for SitemapController.
 */
class SitemapControllerTest extends TestCase
{

    private IRequest|MockObject $request;
    private SitemapService|MockObject $sitemapService;
    private SitemapController $controller;

    protected function setUp(): void
    {
        $this->request        = $this->createMock(IRequest::class);
        $this->sitemapService = $this->createMock(SitemapService::class);

        $this->controller = new SitemapController(
            'opencatalogi',
            $this->request,
            $this->sitemapService
        );
    }

    public function testIndexReturnsSitemapIndex(): void
    {
        $expectedResponse = $this->createMock(XMLResponse::class);

        $this->sitemapService->expects($this->once())
            ->method('buildSitemapIndex')
            ->with('test-catalog', 'sitemapindex-diwoo-infocat001.xml')
            ->willReturn($expectedResponse);

        $response = $this->controller->index('test-catalog', 'sitemapindex-diwoo-infocat001.xml');

        $this->assertSame($expectedResponse, $response);
    }

    public function testIndexWithDifferentCategory(): void
    {
        $expectedResponse = $this->createMock(XMLResponse::class);

        $this->sitemapService->expects($this->once())
            ->method('buildSitemapIndex')
            ->with('my-catalog', 'sitemapindex-diwoo-infocat005.xml')
            ->willReturn($expectedResponse);

        $response = $this->controller->index('my-catalog', 'sitemapindex-diwoo-infocat005.xml');

        $this->assertSame($expectedResponse, $response);
    }

    public function testIndexPassesCatalogSlugCorrectly(): void
    {
        $expectedResponse = $this->createMock(XMLResponse::class);

        $this->sitemapService->expects($this->once())
            ->method('buildSitemapIndex')
            ->with('catalog-with-dashes', 'sitemapindex-diwoo-infocat010.xml')
            ->willReturn($expectedResponse);

        $response = $this->controller->index('catalog-with-dashes', 'sitemapindex-diwoo-infocat010.xml');

        $this->assertSame($expectedResponse, $response);
    }

    public function testSitemapReturnsSitemapPage(): void
    {
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

    public function testSitemapWithSpecificPage(): void
    {
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

    public function testSitemapDefaultsToPage1WhenNoPageParam(): void
    {
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

    public function testSitemapWithStringPageParam(): void
    {
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

    public function testSitemapWithZeroPageParam(): void
    {
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
}
