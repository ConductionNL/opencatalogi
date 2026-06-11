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
            ->willReturn('');

        $this->request->method('getParams')
            ->willReturn([]);

        $this->request->method('getHeader')
            ->willReturn('');

        $this->request->server = [];

        $response = $this->controller->index();

        $this->assertInstanceOf(JSONResponse::class, $response);
        $this->assertEquals(200, $response->getStatus());
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

    public function testIndexThrowsWhenOpenRegisterNotInstalled(): void
    {
        $this->appManager->method('getInstalledApps')
            ->willReturn([]);

        $this->config->method('getValueString')
            ->willReturn('');

        $this->request->method('getParams')
            ->willReturn([]);

        $this->request->method('getHeader')
            ->willReturn('');

        $this->request->server = [];

        $this->expectException(RuntimeException::class);

        $this->controller->index();
    }

    public function testShowReturnsPageBySlug(): void
    {
        $mockObjService = $this->mockObjectService();

        $mockObjService->method('searchObjectsPaginated')
            ->willReturn([
                'results' => [$this->serializableObject(['id' => 1, 'slug' => 'about-us', 'title' => 'About Us'])],
            ]);

        $this->config->method('getValueString')
            ->willReturn('');

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
            ->willReturn('');

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
            ->willReturn('');

        $this->request->method('getHeader')
            ->willReturn('');

        $response = $this->controller->show('solr-page');

        $this->assertInstanceOf(JSONResponse::class, $response);
        $this->assertEquals(200, $response->getStatus());
    }
}
