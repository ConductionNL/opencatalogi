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
        $this->request      = $this->createMock(IRequest::class);
        $this->config       = $this->createMock(IAppConfig::class);
        $this->container    = $this->createMock(ContainerInterface::class);
        $this->appManager   = $this->createMock(IAppManager::class);

        $this->controller = new ThemesController(
            'opencatalogi',
            $this->request,
            $this->config,
            $this->container,
            $this->appManager
        );
    }

    /**
     * Build a test double for OpenRegister's (final) RegisterResolverService.
     *
     * Mirrors resolveRegisterId()/resolveSchemaId(): read the IAppConfig key and
     * throw MissingConfigException when it is empty.
     *
     * @param IAppConfig $config The (mocked) app config the double reads from.
     *
     * @return object A double exposing resolveRegisterId / resolveSchemaId.
     */
    private function makeResolverDouble(IAppConfig $config): object
    {
        return new class($config) {
            public function __construct(private IAppConfig $config)
            {
            }

            public function resolveRegisterId(string $appId, string $key, ?string $default = null): string
            {
                return $this->resolve($appId, $key);
            }

            public function resolveSchemaId(string $appId, string $key, ?string $default = null): string
            {
                return $this->resolve($appId, $key);
            }

            private function resolve(string $appId, string $key): string
            {
                $value = $this->config->getValueString($appId, $key, '');
                if ($value === '') {
                    throw new \OCA\OpenRegister\Service\Resolver\Exception\MissingConfigException($appId, $key);
                }
                return $value;
            }
        };
    }

    private function mockObjectService(): MockObject
    {
        $mockObjService = $this->createMock(\OCA\OpenRegister\Service\ObjectService::class);

        $this->appManager->method('getInstalledApps')
            ->willReturn(['openregister']);

        // The controller now resolves register/schema via RegisterResolverService
        // before hitting ObjectService; the resolver reads the same app-config
        // keys (throwing MissingConfigException on empty) so tests keep driving
        // behaviour through the getValueString map below.
        // RegisterResolverService is declared final and cannot be mocked, so we
        // pass a hand-rolled double that mirrors the real resolveRegisterId /
        // resolveSchemaId contract (read app config; throw on empty).
        $resolver = $this->makeResolverDouble($this->config);

        $this->container->method('get')
            ->willReturnCallback(
                static function (string $id) use ($mockObjService, $resolver) {
                    if ($id === 'OCA\OpenRegister\Service\RegisterResolverService') {
                        return $resolver;
                    }
                    return $mockObjService;
                }
            );

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
                ['opencatalogi', 'cors_allowed_origins', '*', '*'],
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

    public function testIndexWithEmptyThemeConfigurationReturns503(): void
    {
        // An unconfigured theme_register/theme_schema now surfaces an
        // operator-actionable 503 instead of silently returning an empty list
        // (RegisterResolverService adoption — no empty-string fallback).
        $this->mockObjectService();

        $this->config->method('getValueString')
            ->willReturnMap([
                ['opencatalogi', 'theme_schema', '', ''],
                ['opencatalogi', 'theme_register', '', ''],
                ['opencatalogi', 'cors_allowed_origins', '*', '*'],
            ]);

        $this->request->method('getParams')
            ->willReturn([]);

        $this->request->server = [];

        $response = $this->controller->index();

        $this->assertInstanceOf(JSONResponse::class, $response);
        $this->assertEquals(503, $response->getStatus());
        $this->assertEquals('register_not_configured', $response->getData()['error']);
    }

    public function testIndexWithFacets(): void
    {
        $mockObjService = $this->mockObjectService();

        $this->config->method('getValueString')
            ->willReturnMap([
                ['opencatalogi', 'theme_schema', '', '10'],
                ['opencatalogi', 'theme_register', '', '2'],
                ['opencatalogi', 'cors_allowed_origins', '*', '*'],
            ]);

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
            ->willReturnMap([
                ['opencatalogi', 'theme_schema', '', '10'],
                ['opencatalogi', 'theme_register', '', '2'],
                ['opencatalogi', 'cors_allowed_origins', '*', '*'],
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
            ->willReturnMap([
                ['opencatalogi', 'theme_schema', '', '10'],
                ['opencatalogi', 'theme_register', '', '2'],
                ['opencatalogi', 'cors_allowed_origins', '*', '*'],
            ]);

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

    public function testIndexReturns503WhenOpenRegisterNotInstalled(): void
    {
        // With OpenRegister unavailable the resolver cannot be obtained; the
        // controller now degrades gracefully to a 503 instead of letting a
        // RuntimeException bubble up as an opaque 500.
        $this->appManager->method('getInstalledApps')
            ->willReturn([]);
        $this->container->method('get')
            ->willThrowException(new RuntimeException('not available'));

        $this->config->method('getValueString')
            ->willReturn('');

        $this->request->method('getParams')
            ->willReturn([]);

        $response = $this->controller->index();

        $this->assertInstanceOf(JSONResponse::class, $response);
        $this->assertEquals(503, $response->getStatus());
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
