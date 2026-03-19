<?php

declare(strict_types=1);

namespace Unit\Controller;

use OCA\OpenCatalogi\Controller\PublicationsController;
use OCA\OpenCatalogi\Service\CatalogiService;
use OCA\OpenCatalogi\Service\DirectoryService;
use OCA\OpenCatalogi\Service\PublicationService;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\Http\Response;
use OCP\IAppConfig;
use OCP\IDBConnection;
use OCP\IL10N;
use OCP\IRequest;
use OCP\App\IAppManager;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Unit tests for PublicationsController.
 */
class PublicationsControllerTest extends TestCase
{

    private IRequest|MockObject $request;
    private PublicationService|MockObject $publicationService;
    private DirectoryService|MockObject $directoryService;
    private CatalogiService|MockObject $catalogiService;
    private IAppConfig|MockObject $config;
    private ContainerInterface|MockObject $container;
    private IAppManager|MockObject $appManager;
    private LoggerInterface|MockObject $logger;
    private IDBConnection|MockObject $db;
    private IL10N|MockObject $l10n;
    private PublicationsController $controller;

    protected function setUp(): void
    {
        $this->request            = $this->createMock(IRequest::class);
        $this->publicationService = $this->createMock(PublicationService::class);
        $this->directoryService   = $this->createMock(DirectoryService::class);
        $this->catalogiService    = $this->createMock(CatalogiService::class);
        $this->config             = $this->createMock(IAppConfig::class);
        $this->container          = $this->createMock(ContainerInterface::class);
        $this->appManager         = $this->createMock(IAppManager::class);
        $this->logger             = $this->createMock(LoggerInterface::class);
        $this->db                 = $this->createMock(IDBConnection::class);
        $this->l10n               = $this->createMock(IL10N::class);

        $this->l10n->method('t')
            ->willReturnCallback(fn(string $text, array $params = []) => $text);

        $this->controller = new PublicationsController(
            'opencatalogi',
            $this->request,
            $this->publicationService,
            $this->directoryService,
            $this->catalogiService,
            $this->config,
            $this->container,
            $this->appManager,
            $this->logger,
            $this->db,
            $this->l10n
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

    public function testIndexReturns404WhenCatalogNotFound(): void
    {
        $this->catalogiService->method('getCatalogBySlug')
            ->with('nonexistent')
            ->willReturn(null);

        $response = $this->controller->index('nonexistent');

        $this->assertInstanceOf(JSONResponse::class, $response);
        $this->assertEquals(404, $response->getStatus());
    }

    public function testIndexReturnsPublicationsForCatalog(): void
    {
        $mockObjService = $this->mockObjectService();

        $this->catalogiService->method('getCatalogBySlug')
            ->with('test-catalog')
            ->willReturn([
                'title'     => 'Test Catalog',
                'schemas'   => [1, 2],
                'registers' => [1],
            ]);

        $mockObjService->method('buildSearchQuery')
            ->willReturn([]);

        $mockObjService->method('searchObjectsPaginated')
            ->willReturn(['results' => [['id' => 'pub-1']], 'total' => 1]);

        $this->request->method('getParams')
            ->willReturn([]);

        $this->request->server = [];

        $response = $this->controller->index('test-catalog');

        $this->assertInstanceOf(JSONResponse::class, $response);
        $this->assertEquals(200, $response->getStatus());
    }

    public function testIndexReturns500OnException(): void
    {
        $this->catalogiService->method('getCatalogBySlug')
            ->willThrowException(new \Exception('DB error'));

        $response = $this->controller->index('test-catalog');

        $this->assertInstanceOf(JSONResponse::class, $response);
        $this->assertEquals(500, $response->getStatus());
    }

    public function testShowReturns404WhenCatalogNotFound(): void
    {
        $this->catalogiService->method('getCatalogBySlug')
            ->with('nonexistent')
            ->willReturn(null);

        $response = $this->controller->show('nonexistent', 'pub-123');

        $this->assertInstanceOf(JSONResponse::class, $response);
        $this->assertEquals(404, $response->getStatus());
    }

    public function testShowReturns404WhenPublicationNotFound(): void
    {
        $mockObjService = $this->mockObjectService();

        $this->catalogiService->method('getCatalogBySlug')
            ->willReturn([
                'title'     => 'Test',
                'schemas'   => [1],
                'registers' => [1],
            ]);

        $mockObjService->method('searchObjects')
            ->willReturn([]);

        // Mock findObjectLocation returning null (no magic tables found)
        $result = $this->createMock(\OCP\DB\IResult::class);
        $result->method('fetch')->willReturn(false);
        $result->method('closeCursor')->willReturn(true);

        $this->db->method('executeQuery')
            ->willReturn($result);

        $this->request->method('getParams')
            ->willReturn([]);

        $this->request->server = [];

        $response = $this->controller->show('test-catalog', 'nonexistent-id');

        $this->assertInstanceOf(JSONResponse::class, $response);
        $this->assertEquals(404, $response->getStatus());
    }

    public function testShowReturns500OnGenericException(): void
    {
        $this->catalogiService->method('getCatalogBySlug')
            ->willThrowException(new \Exception('Unexpected error'));

        $response = $this->controller->show('test-catalog', 'pub-123');

        $this->assertInstanceOf(JSONResponse::class, $response);
        $this->assertEquals(500, $response->getStatus());
    }

    public function testAttachmentsReturns404WhenCatalogNotFound(): void
    {
        $this->catalogiService->method('getCatalogBySlug')
            ->willReturn(null);

        $response = $this->controller->attachments('nonexistent', 'pub-123');

        $this->assertInstanceOf(JSONResponse::class, $response);
        $this->assertEquals(404, $response->getStatus());
    }

    public function testDownloadReturns404WhenCatalogNotFound(): void
    {
        $this->catalogiService->method('getCatalogBySlug')
            ->willReturn(null);

        $response = $this->controller->download('nonexistent', 'pub-123');

        $this->assertInstanceOf(JSONResponse::class, $response);
        $this->assertEquals(404, $response->getStatus());
    }

    public function testUsesReturnsJsonResponse(): void
    {
        $mockObjService = $this->mockObjectService();

        $mockObjService->method('getObjectUses')
            ->willReturn(['results' => [], 'total' => 0]);

        $this->request->method('getParams')
            ->willReturn([]);

        $this->request->server = [];

        $response = $this->controller->uses('test-catalog', 'pub-123');

        $this->assertInstanceOf(JSONResponse::class, $response);
        $this->assertEquals(200, $response->getStatus());
    }

    public function testUsesReturns500OnException(): void
    {
        $mockObjService = $this->mockObjectService();

        $mockObjService->method('getObjectUses')
            ->willThrowException(new \Exception('Error'));

        $this->request->method('getParams')
            ->willReturn([]);

        $response = $this->controller->uses('test-catalog', 'pub-123');

        $this->assertInstanceOf(JSONResponse::class, $response);
        $this->assertEquals(500, $response->getStatus());
    }

    public function testUsedReturnsJsonResponse(): void
    {
        $mockObjService = $this->mockObjectService();

        $mockObjService->method('getObjectUsedBy')
            ->willReturn(['results' => [], 'total' => 0]);

        $this->request->method('getParams')
            ->willReturn([]);

        $this->request->server = [];

        $response = $this->controller->used('test-catalog', 'pub-123');

        $this->assertInstanceOf(JSONResponse::class, $response);
        $this->assertEquals(200, $response->getStatus());
    }

    public function testUsedReturns500OnException(): void
    {
        $mockObjService = $this->mockObjectService();

        $mockObjService->method('getObjectUsedBy')
            ->willThrowException(new \Exception('Error'));

        $this->request->method('getParams')
            ->willReturn([]);

        $response = $this->controller->used('test-catalog', 'pub-123');

        $this->assertInstanceOf(JSONResponse::class, $response);
        $this->assertEquals(500, $response->getStatus());
    }

    public function testIndexWithSingleSchemaCatalog(): void
    {
        $mockObjService = $this->mockObjectService();

        $this->catalogiService->method('getCatalogBySlug')
            ->willReturn([
                'title'     => 'Single Schema Catalog',
                'schemas'   => [5],
                'registers' => [1],
            ]);

        $mockObjService->method('buildSearchQuery')
            ->willReturn([]);

        $mockObjService->method('searchObjectsPaginated')
            ->willReturn(['results' => [], 'total' => 0]);

        $this->request->method('getParams')
            ->willReturn([]);

        $this->request->server = [];

        $response = $this->controller->index('single-schema');

        $this->assertInstanceOf(JSONResponse::class, $response);
        $this->assertEquals(200, $response->getStatus());
    }

    public function testIndexWithMultiSchemaCatalog(): void
    {
        $mockObjService = $this->mockObjectService();

        $this->catalogiService->method('getCatalogBySlug')
            ->willReturn([
                'title'     => 'Multi Schema Catalog',
                'schemas'   => [1, 2, 3],
                'registers' => [1, 2],
            ]);

        $mockObjService->method('buildSearchQuery')
            ->willReturn([]);

        $mockObjService->method('searchObjectsPaginated')
            ->willReturn(['results' => [], 'total' => 0]);

        $this->request->method('getParams')
            ->willReturn([]);

        $this->request->server = [];

        $response = $this->controller->index('multi-schema');

        $this->assertInstanceOf(JSONResponse::class, $response);
        $this->assertEquals(200, $response->getStatus());
    }
}
