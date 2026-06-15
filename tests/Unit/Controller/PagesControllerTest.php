<?php

declare(strict_types=1);

namespace Unit\Controller;

use OCA\OpenCatalogi\Controller\PagesController;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\Http\Response;
use OCP\IAppConfig;
use OCP\IL10N;
use OCP\IRequest;
use OCP\App\IAppManager;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use RuntimeException;

/**
 * Unit tests for PagesController.
 */
class PagesControllerTest extends TestCase
{

    private IRequest|MockObject $request;
    private IAppConfig|MockObject $config;
    private ContainerInterface|MockObject $container;
    private IAppManager|MockObject $appManager;
    private IL10N|MockObject $l10n;
    private PagesController $controller;

    protected function setUp(): void
    {
        $this->request      = $this->createMock(IRequest::class);
        $this->config       = $this->createMock(IAppConfig::class);
        $this->container    = $this->createMock(ContainerInterface::class);
        $this->appManager   = $this->createMock(IAppManager::class);
        $this->l10n         = $this->createMock(IL10N::class);

        $this->l10n->method('t')
            ->willReturnCallback(fn(string $text) => $text);

        $this->controller = new PagesController(
            'opencatalogi',
            $this->request,
            $this->config,
            $this->container,
            $this->appManager,
            $this->l10n
        );
    }

    /**
     * Build a JsonSerializable result row mirroring an OpenRegister entity.
     *
     * Production results from the magic-mapper backend are entity objects; the SOLR
     * backend returns plain arrays. show() now checks is_array() before calling
     * jsonSerialize() (#736), so both shapes are handled. This helper exercises the
     * entity path; testShowAcceptsArrayShape exercises the SOLR array path.
     *
     * @param array<string,mixed> $data Page payload.
     *
     * @return \JsonSerializable
     */
    private function serializableObject(array $data): \JsonSerializable
    {
        return new class($data) implements \JsonSerializable {
            public function __construct(private array $data)
            {
            }

            public function jsonSerialize(): array
            {
                return $this->data;
            }
        };
    }

    /**
     * Build a test double for OpenRegister's (final) RegisterResolverService.
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

        // The controller resolves register/schema via RegisterResolverService
        // before hitting ObjectService; the resolver reads the same app-config
        // keys (throwing MissingConfigException on empty).
        // RegisterResolverService is declared final and cannot be mocked, so we
        // pass a hand-rolled double mirroring its contract.
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

    public function testPreflightedCorsReturnsResponse(): void
    {
        $this->request->method('getHeader')
            ->with('Origin')
            ->willReturn('https://example.com');

        $response = $this->controller->preflightedCors();

        $this->assertInstanceOf(Response::class, $response);
    }

    public function testPreflightedCorsWildcardWhenNoOrigin(): void
    {
        $this->request->method('getHeader')
            ->with('Origin')
            ->willReturn('');

        $response = $this->controller->preflightedCors();

        $this->assertInstanceOf(Response::class, $response);
    }

    public function testIndexReturnsJsonResponse(): void
    {
        $mockObjService = $this->mockObjectService();

        $mockObjService->method('searchObjectsPaginated')
            ->willReturn(['results' => [['id' => 1, 'title' => 'About']], 'total' => 1]);

        $this->config->method('getValueString')
            ->willReturnCallback($this->pageConfigStub());

        $this->request->method('getParams')
            ->willReturn([]);

        $this->request->method('getHeader')
            ->willReturn('');

        $this->request->server = [];

        $response = $this->controller->index();

        $this->assertInstanceOf(JSONResponse::class, $response);
        $this->assertEquals(200, $response->getStatus());
    }

    /**
     * Config stub returning a configured page register/schema (so the resolver
     * succeeds) while echoing the default for any other key.
     *
     * @return callable
     */
    private function pageConfigStub(): callable
    {
        return static function (string $app, string $key, string $default = '') {
            return match ($key) {
                'page_register' => '1',
                'page_schema'   => '3',
                default         => $default,
            };
        };
    }

    public function testIndexWithPageConfiguration(): void
    {
        $mockObjService = $this->mockObjectService();

        $mockObjService->method('searchObjectsPaginated')
            ->willReturn(['results' => [], 'total' => 0]);

        $this->config->method('getValueString')
            ->willReturnMap([
                ['opencatalogi', 'page_schema', '', '10'],
                ['opencatalogi', 'page_register', '', '2'],
                ['opencatalogi', 'cors_allowed_origins', '*', '*'],
            ]);

        $this->request->method('getParams')
            ->willReturn([]);

        $this->request->method('getHeader')
            ->willReturn('https://test.com');

        $this->request->server = [];

        $response = $this->controller->index();

        $this->assertInstanceOf(JSONResponse::class, $response);
    }

    public function testIndexReturns503WhenOpenRegisterNotInstalled(): void
    {
        $this->appManager->method('getInstalledApps')
            ->willReturn([]);
        $this->container->method('get')
            ->willThrowException(new RuntimeException('not available'));

        $this->config->method('getValueString')
            ->willReturn('');

        $this->request->method('getParams')
            ->willReturn([]);

        $this->request->method('getHeader')
            ->willReturn('');

        $this->request->server = [];

        $response = $this->controller->index();

        $this->assertInstanceOf(JSONResponse::class, $response);
        $this->assertEquals(503, $response->getStatus());
    }

    public function testShowReturnsPageBySlug(): void
    {
        $mockObjService = $this->mockObjectService();

        $mockObjService->method('searchObjectsPaginated')
            ->willReturn([
                'results' => [$this->serializableObject(['id' => 1, 'slug' => 'about-us', 'title' => 'About Us'])],
            ]);

        $this->config->method('getValueString')
            ->willReturnCallback($this->pageConfigStub());

        $this->request->method('getHeader')
            ->willReturn('');

        $response = $this->controller->show('about-us');

        $this->assertInstanceOf(JSONResponse::class, $response);
        $this->assertEquals(200, $response->getStatus());
    }

    public function testShowReturns404WhenNotFound(): void
    {
        $mockObjService = $this->mockObjectService();

        $mockObjService->method('searchObjectsPaginated')
            ->willReturn(['results' => []]);

        $this->config->method('getValueString')
            ->willReturnCallback($this->pageConfigStub());

        $this->request->method('getHeader')
            ->willReturn('');

        $response = $this->controller->show('nonexistent-page');

        $this->assertInstanceOf(JSONResponse::class, $response);
        $this->assertEquals(404, $response->getStatus());
    }

    public function testShowWithOriginHeader(): void
    {
        $mockObjService = $this->mockObjectService();

        $mockObjService->method('searchObjectsPaginated')
            ->willReturn([
                'results' => [$this->serializableObject(['id' => 1, 'slug' => 'contact'])],
            ]);

        $this->config->method('getValueString')
            ->willReturn('');

        $this->request->method('getHeader')
            ->willReturn('https://example.com');

        $response = $this->controller->show('contact');

        $this->assertInstanceOf(JSONResponse::class, $response);
    }

    /**
     * #736: the SOLR backend returns plain array shapes (no jsonSerialize()).
     * show() must accept them without fataling now that the is_array() guard
     * precedes the jsonSerialize() call.
     */
    public function testShowAcceptsArrayShape(): void
    {
        $mockObjService = $this->mockObjectService();

        $mockObjService->method('searchObjectsPaginated')
            ->willReturn([
                'results' => [['id' => 7, 'slug' => 'solr-page', 'title' => 'SOLR Page']],
            ]);

        $this->config->method('getValueString')
            ->willReturnCallback($this->pageConfigStub());

        $this->request->method('getHeader')
            ->willReturn('');

        $response = $this->controller->show('solr-page');

        $this->assertInstanceOf(JSONResponse::class, $response);
        $this->assertEquals(200, $response->getStatus());
    }
}
