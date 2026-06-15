<?php

declare(strict_types=1);

namespace Unit\Controller;

use OCA\OpenCatalogi\Controller\GlossaryController;
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
 * Unit tests for GlossaryController.
 */
class GlossaryControllerTest extends TestCase
{

    private IRequest|MockObject $request;
    private IAppConfig|MockObject $config;
    private ContainerInterface|MockObject $container;
    private IAppManager|MockObject $appManager;
    private IL10N|MockObject $l10n;
    private GlossaryController $controller;

    protected function setUp(): void
    {
        $this->request      = $this->createMock(IRequest::class);
        $this->config       = $this->createMock(IAppConfig::class);
        $this->container    = $this->createMock(ContainerInterface::class);
        $this->appManager   = $this->createMock(IAppManager::class);
        $this->l10n         = $this->createMock(IL10N::class);

        $this->l10n->method('t')
            ->willReturnCallback(fn(string $text) => $text);

        $this->controller = new GlossaryController(
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
     * @param array<string,mixed> $data Term payload.
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
        $mockObjService = $this->mockObjectService();

        $mockObjService->method('searchObjectsPaginated')
            ->willReturn([
                'results' => [['id' => 1, 'term' => 'API']],
                'total'   => 1,
                'limit'   => 20,
                'offset'  => 0,
                'page'    => 1,
                'pages'   => 1,
            ]);

        $this->config->method('getValueString')
            ->willReturnMap([
                ['opencatalogi', 'glossary_schema', '', '5'],
                ['opencatalogi', 'glossary_register', '', '1'],
                ['opencatalogi', 'cors_allowed_origins', '*', '*'],
            ]);

        $this->request->method('getParams')
            ->willReturn([]);

        $this->request->server = [];

        $response = $this->controller->index();

        $this->assertInstanceOf(JSONResponse::class, $response);
        $this->assertEquals(200, $response->getStatus());
    }

    public function testIndexWithPaginationLinks(): void
    {
        $mockObjService = $this->mockObjectService();

        $mockObjService->method('searchObjectsPaginated')
            ->willReturn([
                'results' => [],
                'total'   => 100,
                'limit'   => 10,
                'offset'  => 0,
                'page'    => 1,
                'pages'   => 10,
                'next'    => '/api/glossary?page=2',
                'prev'    => null,
            ]);

        $this->config->method('getValueString')
            ->willReturn('');

        $this->request->method('getParams')
            ->willReturn([]);

        $this->request->server = [];

        $response = $this->controller->index();

        $this->assertInstanceOf(JSONResponse::class, $response);
    }

    public function testIndexWithFacets(): void
    {
        $mockObjService = $this->mockObjectService();

        $mockObjService->method('searchObjectsPaginated')
            ->willReturn([
                'results'   => [],
                'total'     => 0,
                'facets'    => ['category' => ['A' => 5]],
                'facetable' => ['category'],
            ]);

        $this->config->method('getValueString')
            ->willReturn('');

        $this->request->method('getParams')
            ->willReturn([]);

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

        $this->request->server = [];

        $response = $this->controller->index();

        $this->assertInstanceOf(JSONResponse::class, $response);
        $this->assertEquals(503, $response->getStatus());
    }

    public function testShowReturnsGlossaryTerm(): void
    {
        $mockObjService = $this->mockObjectService();

        $mockObjService->method('searchObjectsPaginated')
            ->willReturn([
                'results' => [$this->serializableObject(['id' => 1, 'term' => 'API', 'definition' => 'Application Programming Interface'])],
            ]);

        $this->request->server = [];

        $response = $this->controller->show('1');

        $this->assertInstanceOf(JSONResponse::class, $response);
        $this->assertEquals(200, $response->getStatus());
    }

    public function testShowReturns404WhenNotFound(): void
    {
        $mockObjService = $this->mockObjectService();

        $mockObjService->method('searchObjectsPaginated')
            ->willReturn(['results' => []]);

        $response = $this->controller->show('nonexistent');

        $this->assertInstanceOf(JSONResponse::class, $response);
        $this->assertEquals(404, $response->getStatus());
    }

    public function testShowWithIntegerId(): void
    {
        $mockObjService = $this->mockObjectService();

        $mockObjService->method('searchObjectsPaginated')
            ->willReturn([
                'results' => [$this->serializableObject(['id' => 42, 'term' => 'Test'])],
            ]);

        $this->request->server = [];

        $response = $this->controller->show(42);

        $this->assertInstanceOf(JSONResponse::class, $response);
        $this->assertEquals(200, $response->getStatus());
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
                'results' => [['id' => 7, 'term' => 'SOLR', 'definition' => 'Search backend']],
            ]);

        $this->request->server = [];

        $response = $this->controller->show('7');

        $this->assertInstanceOf(JSONResponse::class, $response);
        $this->assertEquals(200, $response->getStatus());
    }
}
