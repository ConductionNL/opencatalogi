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

        // Configure catalog scope so the RegisterResolverService resolves it.
        $this->config->method('getValueString')
            ->willReturnCallback(
                static fn (string $app, string $key, string $default = '') => match ($key) {
                    'catalog_schema'   => '5',
                    'catalog_register' => '3',
                    default            => $default,
                }
            );

        $this->container->method('get')
            ->willReturnCallback(
                function (string $id) use ($mockObjService) {
                    if ($id === 'OCA\OpenRegister\Service\RegisterResolverService') {
                        // RegisterResolverService is final; use a hand-rolled double.
                        return new class {
                            public function resolveRegisterId(string $a, string $k, ?string $d = null): string
                            {
                                return '3';
                            }

                            public function resolveSchemaId(string $a, string $k, ?string $d = null): string
                            {
                                return '5';
                            }
                        };
                    }
                    return $mockObjService;
                }
            );

        $this->request->method('getParams')
            ->willReturn([]);

        $this->request->server = [];

        $response = $this->controller->index();

        $this->assertInstanceOf(JSONResponse::class, $response);
        $this->assertEquals(200, $response->getStatus());
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
            ->willReturnCallback(
                function (string $id) use ($mockObjService) {
                    if ($id === 'OCA\OpenRegister\Service\RegisterResolverService') {
                        // RegisterResolverService is final; use a hand-rolled double.
                        return new class {
                            public function resolveRegisterId(string $a, string $k, ?string $d = null): string
                            {
                                return '3';
                            }

                            public function resolveSchemaId(string $a, string $k, ?string $d = null): string
                            {
                                return '5';
                            }
                        };
                    }
                    return $mockObjService;
                }
            );

        // Configure catalog scope and leave CORS allowlist at its default '*'.
        $this->config->method('getValueString')
            ->willReturnCallback(function (string $app, string $key, string $default = '') {
                return match ($key) {
                    'catalog_schema'       => '5',
                    'catalog_register'     => '3',
                    'cors_allowed_origins' => $default,
                    default                => $default,
                };
            });

        $this->request->method('getParams')
            ->willReturn([]);

        $this->request->server = ['HTTP_ORIGIN' => 'https://test.com'];

        $response = $this->controller->index();

        $this->assertInstanceOf(JSONResponse::class, $response);
    }

    public function testIndexReturns503WhenOpenRegisterNotInstalled(): void
    {
        // With OpenRegister unavailable the resolver cannot be obtained; the
        // controller degrades gracefully to a 503 instead of a raw 500.
        $this->appManager->method('getInstalledApps')
            ->willReturn([]);
        $this->container->method('get')
            ->willThrowException(new RuntimeException('not available'));

        $this->config->method('getValueString')
            ->willReturnCallback(fn(string $app, string $key, string $default = '') => $default);

        $this->request->method('getParams')
            ->willReturn([]);

        $this->request->server = [];

        $response = $this->controller->index();

        $this->assertInstanceOf(JSONResponse::class, $response);
        $this->assertEquals(503, $response->getStatus());
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

    /**
     * Security (#735): an attacker-controlled Origin header must NOT be reflected
     * back in Access-Control-Allow-Origin when the allowlist is not '*'. The
     * controller must fall back to the configured allowlist entry instead.
     */
    public function testShowDoesNotReflectArbitraryOriginWhenAllowlistConfigured(): void
    {
        $expectedResponse = new JSONResponse(['id' => '123']);

        $this->catalogiService->method('index')
            ->with('123')
            ->willReturn($expectedResponse);

        $this->config->method('getValueString')
            ->willReturnCallback(function (string $app, string $key, string $default = '') {
                return match ($key) {
                    'cors_allowed_origins' => 'https://trusted.example',
                    default                => $default,
                };
            });

        $this->request->method('getHeader')
            ->with('Origin')
            ->willReturn('https://evil.attacker.test');

        $response = $this->controller->show('123');

        $this->assertSame(
            'https://trusted.example',
            $response->getHeaders()['Access-Control-Allow-Origin']
        );
    }

    /**
     * When the configured allowlist contains the caller's Origin, that exact value
     * may be echoed back (CORS by-design); otherwise the first allowlist entry wins.
     */
    public function testShowEchoesOriginOnlyWhenOnAllowlist(): void
    {
        $expectedResponse = new JSONResponse(['id' => '123']);

        $this->catalogiService->method('index')
            ->with('123')
            ->willReturn($expectedResponse);

        $this->config->method('getValueString')
            ->willReturnCallback(function (string $app, string $key, string $default = '') {
                return match ($key) {
                    'cors_allowed_origins' => 'https://trusted.example,https://other.example',
                    default                => $default,
                };
            });

        $this->request->method('getHeader')
            ->with('Origin')
            ->willReturn('https://other.example');

        $response = $this->controller->show('123');

        $this->assertSame(
            'https://other.example',
            $response->getHeaders()['Access-Control-Allow-Origin']
        );
    }
}
