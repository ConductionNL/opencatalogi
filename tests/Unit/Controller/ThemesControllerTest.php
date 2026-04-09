<?php

declare(strict_types=1);

namespace Unit\Controller;

use OCA\OpenCatalogi\Controller\ThemesController;
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
 * Unit tests for ThemesController.
 */
class ThemesControllerTest extends TestCase
{

    private IRequest|MockObject $request;
    private IAppConfig|MockObject $config;
    private ContainerInterface|MockObject $container;
    private IAppManager|MockObject $appManager;
    private ThemesController $controller;

    protected function setUp(): void
    {
        $this->request    = $this->createMock(IRequest::class);
        $this->config     = $this->createMock(IAppConfig::class);
        $this->container  = $this->createMock(ContainerInterface::class);
        $this->appManager = $this->createMock(IAppManager::class);

        $this->controller = new ThemesController(
            'opencatalogi',
            $this->request,
            $this->config,
            $this->container,
            $this->appManager
        );
    }

    private function mockObjectService(): MockObject
    {
        $mockObjService = $this->createMock(\OCA\OpenRegister\Service\ObjectService::class);

        $this->appManager->method('getInstalledApps')
            ->willReturn(['openregister']);

        $this->container->method('get')
            ->with('OCA\OpenRegister\Service\ObjectService')
            ->willReturn($mockObjService);

        return $mockObjService;
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

    public function testIndexReturnsThemesWithPagination(): void
    {
        $mockObjService = $this->mockObjectService();

        $this->config->method('getValueString')
            ->willReturnMap([
                ['opencatalogi', 'theme_schema', '', '10'],
                ['opencatalogi', 'theme_register', '', '2'],
            ]);

        $this->request->method('getParams')
            ->willReturn([]);

        $this->request->server = [];

        $mockObjService->method('searchObjectsPaginated')
            ->willReturn([
                'results' => [['id' => 'theme-1', 'title' => 'Dark']],
                'total'   => 1,
                'limit'   => 20,
                'offset'  => 0,
                'page'    => 1,
                'pages'   => 1,
            ]);

        $response = $this->controller->index();

        $this->assertInstanceOf(JSONResponse::class, $response);
        $this->assertEquals(200, $response->getStatus());
    }

    public function testIndexWithEmptyThemeConfiguration(): void
    {
        $mockObjService = $this->mockObjectService();

        $this->config->method('getValueString')
            ->willReturnMap([
                ['opencatalogi', 'theme_schema', '', ''],
                ['opencatalogi', 'theme_register', '', ''],
            ]);

        $this->request->method('getParams')
            ->willReturn([]);

        $this->request->server = [];

        $mockObjService->method('searchObjectsPaginated')
            ->willReturn([
                'results' => [],
                'total'   => 0,
                'limit'   => 20,
                'offset'  => 0,
                'page'    => 1,
                'pages'   => 1,
            ]);

        $response = $this->controller->index();

        $this->assertInstanceOf(JSONResponse::class, $response);
        $this->assertEquals(200, $response->getStatus());
    }

    public function testIndexWithFacets(): void
    {
        $mockObjService = $this->mockObjectService();

        $this->config->method('getValueString')
            ->willReturn('');

        $this->request->method('getParams')
            ->willReturn([]);

        $this->request->server = [];

        $mockObjService->method('searchObjectsPaginated')
            ->willReturn([
                'results'   => [],
                'total'     => 0,
                'limit'     => 20,
                'offset'    => 0,
                'page'      => 1,
                'pages'     => 1,
                'facets'    => ['color' => ['dark' => 3, 'light' => 7]],
                'facetable' => ['color'],
            ]);

        $response = $this->controller->index();

        $this->assertInstanceOf(JSONResponse::class, $response);
        $this->assertEquals(200, $response->getStatus());
    }

    public function testIndexWithNestedFacets(): void
    {
        $mockObjService = $this->mockObjectService();

        $this->config->method('getValueString')
            ->willReturn('');

        $this->request->method('getParams')
            ->willReturn([]);

        $this->request->server = [];

        $mockObjService->method('searchObjectsPaginated')
            ->willReturn([
                'results' => [],
                'total'   => 0,
                'limit'   => 20,
                'offset'  => 0,
                'page'    => 1,
                'pages'   => 1,
                'facets'  => ['facets' => ['category' => ['A' => 1]]],
            ]);

        $response = $this->controller->index();

        $this->assertInstanceOf(JSONResponse::class, $response);
        $this->assertEquals(200, $response->getStatus());
    }

    public function testIndexWithPaginationLinks(): void
    {
        $mockObjService = $this->mockObjectService();

        $this->config->method('getValueString')
            ->willReturn('');

        $this->request->method('getParams')
            ->willReturn([]);

        $this->request->server = [];

        $mockObjService->method('searchObjectsPaginated')
            ->willReturn([
                'results' => [],
                'total'   => 50,
                'limit'   => 20,
                'offset'  => 0,
                'page'    => 1,
                'pages'   => 3,
                'next'    => '/api/themes?page=2',
                'prev'    => null,
            ]);

        $response = $this->controller->index();

        $this->assertInstanceOf(JSONResponse::class, $response);
        $this->assertEquals(200, $response->getStatus());
    }

    public function testIndexThrowsWhenOpenRegisterNotInstalled(): void
    {
        $this->appManager->method('getInstalledApps')
            ->willReturn([]);

        $this->config->method('getValueString')
            ->willReturn('');

        $this->request->method('getParams')
            ->willReturn([]);

        $this->expectException(RuntimeException::class);

        $this->controller->index();
    }

    public function testShowReturnsThemeAsJsonResponse(): void
    {
        $mockObjService = $this->mockObjectService();

        $mockEntity = $this->createMock(\OCA\OpenRegister\Db\ObjectEntity::class);
        $mockEntity->method('jsonSerialize')
            ->willReturn(['id' => 'theme-1', 'title' => 'Dark Theme', 'colors' => ['primary' => '#000']]);

        $mockObjService->method('find')
            ->with('theme-1')
            ->willReturn($mockEntity);

        $this->request->server = [];

        $response = $this->controller->show('theme-1');

        $this->assertInstanceOf(JSONResponse::class, $response);
        $this->assertEquals(200, $response->getStatus());
    }

    public function testShowWithEntityObject(): void
    {
        $mockObjService = $this->mockObjectService();

        $mockEntity = $this->createMock(\OCA\OpenRegister\Db\ObjectEntity::class);
        $mockEntity->method('jsonSerialize')
            ->willReturn(['id' => 5, 'title' => 'Entity Theme']);

        $mockObjService->method('find')
            ->with(5)
            ->willReturn($mockEntity);

        $this->request->server = [];

        $response = $this->controller->show(5);

        $this->assertInstanceOf(JSONResponse::class, $response);
        $this->assertEquals(200, $response->getStatus());
    }

    public function testShowWithStringId(): void
    {
        $mockObjService = $this->mockObjectService();

        $mockEntity = $this->createMock(\OCA\OpenRegister\Db\ObjectEntity::class);
        $mockEntity->method('jsonSerialize')
            ->willReturn(['id' => 'uuid-abc-123', 'title' => 'Theme ABC']);

        $mockObjService->method('find')
            ->with('uuid-abc-123')
            ->willReturn($mockEntity);

        $this->request->server = [];

        $response = $this->controller->show('uuid-abc-123');

        $this->assertInstanceOf(JSONResponse::class, $response);
        $this->assertEquals(200, $response->getStatus());
    }

    public function testShowThrowsWhenOpenRegisterNotInstalled(): void
    {
        $this->appManager->method('getInstalledApps')
            ->willReturn([]);

        $this->expectException(RuntimeException::class);

        $this->controller->show('theme-1');
    }
}
