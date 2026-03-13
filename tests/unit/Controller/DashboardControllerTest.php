<?php

namespace OCA\OpenCatalogi\Tests\Controller;

use OCA\OpenCatalogi\Controller\DashboardController;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\MockObject\MockObject;

class DashboardControllerTest extends TestCase
{
    /** @var MockObject&IRequest */
    private $request;

    /** @var DashboardController */
    private $controller;

    protected function setUp(): void
    {
        parent::setUp();

        $this->request = $this->createMock(IRequest::class);
        $this->controller = new DashboardController('opencatalogi', $this->request);
    }

    public function testConstructor(): void
    {
        $this->assertInstanceOf(DashboardController::class, $this->controller);
    }

    public function testPage(): void
    {
        $response = $this->controller->page('testParam');
        $this->assertInstanceOf(TemplateResponse::class, $response);
    }

    public function testPageWithNull(): void
    {
        $response = $this->controller->page(null);
        $this->assertInstanceOf(TemplateResponse::class, $response);
    }

    public function testIndex(): void
    {
        $response = $this->controller->index();
        $this->assertInstanceOf(JSONResponse::class, $response);

        $data = $response->getData();
        $this->assertIsArray($data);
        $this->assertArrayHasKey('results', $data);
    }
}
