<?php

declare(strict_types=1);

namespace Unit\Controller;

use OCA\OpenCatalogi\Controller\DashboardController;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\IRequest;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for DashboardController.
 */
class DashboardControllerTest extends TestCase
{

    private IRequest|MockObject $request;
    private DashboardController $controller;

    protected function setUp(): void
    {
        $this->request    = $this->createMock(IRequest::class);
        $this->controller = new DashboardController('opencatalogi', $this->request);
    }

    public function testPageReturnsTemplateResponse(): void
    {
        $response = $this->controller->page(null);

        $this->assertInstanceOf(TemplateResponse::class, $response);
    }

    public function testPageWithParameterReturnsTemplateResponse(): void
    {
        $response = $this->controller->page('some-parameter');

        $this->assertInstanceOf(TemplateResponse::class, $response);
    }

    public function testPageReturnsIndexTemplate(): void
    {
        $response = $this->controller->page(null);

        $this->assertInstanceOf(TemplateResponse::class, $response);
        $this->assertEquals('index', $response->getTemplateName());
    }

    public function testIndexReturnsJsonResponse(): void
    {
        $response = $this->controller->index();

        $this->assertInstanceOf(JSONResponse::class, $response);
    }

    public function testIndexReturnsEmptyResults(): void
    {
        $response = $this->controller->index();

        $this->assertInstanceOf(JSONResponse::class, $response);
        $this->assertEquals(200, $response->getStatus());
    }

    public function testIndexReturnsResultsKey(): void
    {
        $response = $this->controller->index();
        $data = $response->getData();

        $this->assertArrayHasKey('results', $data);
        $this->assertSame([], $data['results']);
    }

    public function testPageHasContentSecurityPolicy(): void
    {
        $response = $this->controller->page(null);

        $csp = $response->getContentSecurityPolicy();
        $this->assertNotNull($csp);
    }

    public function testPageReturnsStatus200(): void
    {
        $response = $this->controller->page('param');

        $this->assertEquals(200, $response->getStatus());
    }
}
