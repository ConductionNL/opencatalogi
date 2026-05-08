<?php

declare(strict_types=1);

namespace Unit\Controller;

use OCA\OpenCatalogi\Controller\CatalogiController;
use OCA\OpenCatalogi\Service\CatalogiService;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\Http\Response;
use OCP\IAppConfig;
use OCP\IRequest;
use OCP\App\IAppManager;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use RuntimeException;

/**
 * Unit tests for CatalogiController.
 */
class CatalogiControllerTest extends TestCase
{

    private IRequest|MockObject $request;
    private CatalogiService|MockObject $catalogiService;
    private IAppConfig|MockObject $config;
    private ContainerInterface|MockObject $container;
    private IAppManager|MockObject $appManager;
    private CatalogiController $controller;

    protected function setUp(): void
    {
        $this->request         = $this->createMock(IRequest::class);
        $this->catalogiService = $this->createMock(CatalogiService::class);
        $this->config          = $this->createMock(IAppConfig::class);
        $this->container       = $this->createMock(ContainerInterface::class);
        $this->appManager      = $this->createMock(IAppManager::class);

        $this->controller = new CatalogiController(
            'opencatalogi',
            $this->request,
            $this->catalogiService,
            $this->config,
            $this->container,
            $this->appManager
        );
    }

    public function testPreflightedCorsReturnsResponseWithOriginHeader(): void
    {
        $this->request->method('getHeader')
            ->with('Origin')
            ->willReturn('https://example.com');

        $response = $this->controller->preflightedCors();

        $this->assertInstanceOf(Response::class, $response);
    }

    public function testPreflightedCorsUsesWildcardWhenNoOrigin(): void
    {
        $this->request->method('getHeader')
            ->with('Origin')
            ->willReturn('');

        $response = $this->controller->preflightedCors();

        $this->assertInstanceOf(Response::class, $response);
    }

    public function testIndexReturnsJsonResponse(): void
    {
        // Mock getObjectService via appManager + container
        $this->appManager->method('getInstalledApps')
            ->willReturn(['openregister']);

        $mockObjService = $this->createMock(\OCA\OpenRegister\Service\ObjectService::class);

        $mockObjService->method('buildSearchQuery')
            ->willReturn([]);

        $mockObjService->method('searchObjectsPaginated')
            ->willReturn(['results' => [], 'total' => 0]);

        $this->container->method('get')
            ->with('OCA\OpenRegister\Service\ObjectService')
            ->willReturn($mockObjService);

        $this->config->method('getValueString')
            ->willReturn('');

        $this->request->method('getParams')
            ->willReturn([]);

        $this->request->server = [];

        $response = $this->controller->index();

        $this->assertInstanceOf(JSONResponse::class, $response);
    }

    public function testIndexWithCatalogConfiguration(): void
    {
        $this->appManager->method('getInstalledApps')
            ->willReturn(['openregister']);

        $mockObjService = $this->createMock(\OCA\OpenRegister\Service\ObjectService::class);

        $mockObjService->method('buildSearchQuery')
            ->willReturn([]);

        $mockObjService->method('searchObjectsPaginated')
            ->willReturn(['results' => [['id' => 1]], 'total' => 1]);

        $this->container->method('get')
            ->willReturn($mockObjService);

        $this->config->method('getValueString')
            ->willReturnMap([
                ['opencatalogi', 'catalog_schema', '', '5'],
                ['opencatalogi', 'catalog_register', '', '3'],
            ]);

        $this->request->method('getParams')
            ->willReturn([]);

        $this->request->server = ['HTTP_ORIGIN' => 'https://test.com'];

        $response = $this->controller->index();

        $this->assertInstanceOf(JSONResponse::class, $response);
    }

    public function testIndexThrowsWhenOpenRegisterNotInstalled(): void
    {
        $this->appManager->method('getInstalledApps')
            ->willReturn([]);

        $this->config->method('getValueString')
            ->willReturn('');

        $this->request->method('getParams')
            ->willReturn([]);

        $this->request->server = [];

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('OpenRegister service is not available.');

        $this->controller->index();
    }

    public function testShowReturnsJsonResponse(): void
    {
        $expectedResponse = new JSONResponse(['id' => '123', 'title' => 'Test']);

        $this->catalogiService->method('index')
            ->with('123')
            ->willReturn($expectedResponse);

        $this->request->method('getHeader')
            ->with('Origin')
            ->willReturn('https://example.com');

        $response = $this->controller->show('123');

        $this->assertInstanceOf(JSONResponse::class, $response);
    }

    public function testShowWithNoOriginUsesWildcard(): void
    {
        $expectedResponse = new JSONResponse(['id' => '456']);

        $this->catalogiService->method('index')
            ->with('456')
            ->willReturn($expectedResponse);

        $this->request->method('getHeader')
            ->with('Origin')
            ->willReturn('');

        $response = $this->controller->show('456');

        $this->assertInstanceOf(JSONResponse::class, $response);
    }

    public function testShowWithIntegerId(): void
    {
        $expectedResponse = new JSONResponse(['id' => 42]);

        $this->catalogiService->method('index')
            ->with(42)
            ->willReturn($expectedResponse);

        $this->request->method('getHeader')
            ->with('Origin')
            ->willReturn('');

        $response = $this->controller->show(42);

        $this->assertInstanceOf(JSONResponse::class, $response);
    }
}
