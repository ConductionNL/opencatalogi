<?php

namespace OCA\OpenCatalogi\Tests\Controller;

use OCA\OpenCatalogi\Controller\CatalogiController;
use OCA\OpenCatalogi\Service\CatalogiService;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IAppConfig;
use OCP\IRequest;
use OCP\App\IAppManager;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\MockObject\MockObject;
use Psr\Container\ContainerInterface;

class CatalogiControllerTest extends TestCase
{
    /** @var MockObject&IRequest */
    private $request;

    /** @var MockObject&CatalogiService */
    private $catalogiService;

    /** @var MockObject&IAppConfig */
    private $config;

    /** @var MockObject&ContainerInterface */
    private $container;

    /** @var MockObject&IAppManager */
    private $appManager;

    /** @var CatalogiController */
    private $controller;

    protected function setUp(): void
    {
        parent::setUp();

        $this->request = $this->createMock(IRequest::class);
        $this->catalogiService = $this->createMock(CatalogiService::class);
        $this->config = $this->createMock(IAppConfig::class);
        $this->container = $this->createMock(ContainerInterface::class);
        $this->appManager = $this->createMock(IAppManager::class);

        $this->controller = new CatalogiController(
            'opencatalogi',
            $this->request,
            $this->catalogiService,
            $this->config,
            $this->container,
            $this->appManager
        );
    }

    public function testConstructor(): void
    {
        $this->assertInstanceOf(CatalogiController::class, $this->controller);
    }

    public function testPreflightedCorsWithOrigin(): void
    {
        $this->request->server = ['HTTP_ORIGIN' => 'https://example.com'];

        $response = $this->controller->preflightedCors();

        $headers = $response->getHeaders();
        $this->assertEquals('https://example.com', $headers['Access-Control-Allow-Origin']);
        $this->assertStringContainsString('PUT', $headers['Access-Control-Allow-Methods']);
    }

    public function testPreflightedCorsWithoutOrigin(): void
    {
        $this->request->server = [];

        $response = $this->controller->preflightedCors();

        $headers = $response->getHeaders();
        $this->assertEquals('*', $headers['Access-Control-Allow-Origin']);
    }

    public function testShow(): void
    {
        $expectedResponse = new JSONResponse(['id' => '123', 'title' => 'Test Catalog']);
        $this->catalogiService->method('index')
            ->with('123')
            ->willReturn($expectedResponse);

        $this->request->server = [];

        $response = $this->controller->show('123');

        $this->assertInstanceOf(JSONResponse::class, $response);
        $this->assertEquals(['id' => '123', 'title' => 'Test Catalog'], $response->getData());
    }

    public function testShowWithCorsHeaders(): void
    {
        $expectedResponse = new JSONResponse(['id' => '456']);
        $this->catalogiService->method('index')
            ->with('456')
            ->willReturn($expectedResponse);

        $this->request->server = ['HTTP_ORIGIN' => 'https://example.com'];

        $response = $this->controller->show('456');

        $headers = $response->getHeaders();
        $this->assertEquals('https://example.com', $headers['Access-Control-Allow-Origin']);
    }
}
