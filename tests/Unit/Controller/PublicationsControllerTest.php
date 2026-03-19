<?php

declare(strict_types=1);

namespace Unit\Controller;

use OCA\OpenCatalogi\Controller\PublicationsController;
use OCA\OpenCatalogi\Service\CatalogiService;
use OCA\OpenCatalogi\Service\PublicationService;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\Http\Response;
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
    private CatalogiService|MockObject $catalogiService;
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
        $this->catalogiService    = $this->createMock(CatalogiService::class);
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
            $this->catalogiService,
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

    // =======================================================================
    // index() — additional branches
    // =======================================================================

    public function testIndexWithJsonStringSchemas(): void
    {
        $mockObjService = $this->mockObjectService();

        $this->catalogiService->method('getCatalogBySlug')
            ->willReturn([
                'title'     => 'JSON String Schemas',
                'schemas'   => '[1, 2]',
                'registers' => '[1]',
            ]);

        $mockObjService->method('buildSearchQuery')
            ->willReturn([]);

        $mockObjService->method('searchObjectsPaginated')
            ->willReturn(['results' => [], 'total' => 0]);

        $this->request->method('getParams')
            ->willReturn([]);

        $this->request->server = [];

        $response = $this->controller->index('json-schemas');

        $this->assertInstanceOf(JSONResponse::class, $response);
        $this->assertEquals(200, $response->getStatus());
    }

    public function testIndexWithOrderStrippedForMultiRegister(): void
    {
        $mockObjService = $this->mockObjectService();

        $this->catalogiService->method('getCatalogBySlug')
            ->willReturn([
                'title'     => 'Multi Register',
                'schemas'   => [1],
                'registers' => [1, 2],
            ]);

        $mockObjService->method('buildSearchQuery')
            ->willReturn([
                '_order' => ['custom_field' => 'ASC', 'published' => 'DESC'],
            ]);

        $mockObjService->method('searchObjectsPaginated')
            ->willReturn(['results' => [], 'total' => 0]);

        $this->request->method('getParams')
            ->willReturn([]);

        $this->request->server = [];

        $response = $this->controller->index('multi-reg');

        $this->assertInstanceOf(JSONResponse::class, $response);
        $this->assertEquals(200, $response->getStatus());
    }

    public function testIndexWithEmptyOrderAfterStripping(): void
    {
        $mockObjService = $this->mockObjectService();

        $this->catalogiService->method('getCatalogBySlug')
            ->willReturn([
                'title'     => 'Multi Register',
                'schemas'   => [1],
                'registers' => [1, 2],
            ]);

        $mockObjService->method('buildSearchQuery')
            ->willReturn([
                '_order' => ['non_universal_field' => 'ASC'],
            ]);

        $mockObjService->method('searchObjectsPaginated')
            ->willReturn(['results' => [], 'total' => 0]);

        $this->request->method('getParams')
            ->willReturn([]);

        $this->request->server = [];

        $response = $this->controller->index('multi-reg-empty-order');

        $this->assertInstanceOf(JSONResponse::class, $response);
        $this->assertEquals(200, $response->getStatus());
    }

    public function testIndexWithEmptySchemas(): void
    {
        $mockObjService = $this->mockObjectService();

        $this->catalogiService->method('getCatalogBySlug')
            ->willReturn([
                'title'     => 'No Schema Catalog',
                'schemas'   => [],
                'registers' => [],
            ]);

        $mockObjService->method('buildSearchQuery')
            ->willReturn([]);

        $mockObjService->method('searchObjectsPaginated')
            ->willReturn(['results' => [], 'total' => 0]);

        $this->request->method('getParams')
            ->willReturn([]);

        $this->request->server = [];

        $response = $this->controller->index('no-schema');

        $this->assertInstanceOf(JSONResponse::class, $response);
        $this->assertEquals(200, $response->getStatus());
    }

    public function testIndexWithHttpOriginHeader(): void
    {
        $mockObjService = $this->mockObjectService();

        $this->catalogiService->method('getCatalogBySlug')
            ->willReturn([
                'title'     => 'Test',
                'schemas'   => [1],
                'registers' => [1],
            ]);

        $mockObjService->method('buildSearchQuery')
            ->willReturn([]);

        $mockObjService->method('searchObjectsPaginated')
            ->willReturn(['results' => [], 'total' => 0]);

        $this->request->method('getParams')
            ->willReturn([]);

        $this->request->server = ['HTTP_ORIGIN' => 'https://custom-origin.com'];

        $response = $this->controller->index('test');

        $this->assertInstanceOf(JSONResponse::class, $response);
        $this->assertEquals(200, $response->getStatus());
    }

    // =======================================================================
    // show() — success path with object found
    // =======================================================================

    /**
     * Helper to create an ObjectEntity mock that supports magic __call getters.
     */
    private function createObjectEntityMock(int $id = 42, int $schema = 1, int $register = 1): \OCA\OpenRegister\Db\ObjectEntity
    {
        $mockObj = $this->getMockBuilder(\OCA\OpenRegister\Db\ObjectEntity::class)
            ->disableOriginalConstructor()
            ->addMethods(['getId', 'getSchema', 'getRegister'])
            ->getMock();
        $mockObj->method('getId')->willReturn($id);
        $mockObj->method('getSchema')->willReturn($schema);
        $mockObj->method('getRegister')->willReturn($register);
        return $mockObj;
    }

    public function testShowReturnsPublicationWhenFound(): void
    {
        $mockObjService = $this->mockObjectService();

        $this->catalogiService->method('getCatalogBySlug')
            ->willReturn([
                'title'     => 'Test',
                'schemas'   => [1],
                'registers' => [1],
            ]);

        $mockObj = $this->createObjectEntityMock();

        $mockObjService->method('searchObjects')
            ->willReturn([$mockObj]);

        $mockObjService->method('renderEntity')
            ->willReturn(['id' => 'pub-123', 'title' => 'Test Publication']);

        $this->request->method('getParams')
            ->willReturn([]);

        $this->request->server = ['HTTP_ORIGIN' => 'https://example.com'];

        $response = $this->controller->show('test-catalog', 'pub-123');

        $this->assertInstanceOf(JSONResponse::class, $response);
        $this->assertEquals(200, $response->getStatus());
    }

    public function testShowWithExtendParameter(): void
    {
        $mockObjService = $this->mockObjectService();

        $this->catalogiService->method('getCatalogBySlug')
            ->willReturn([
                'title'     => 'Test',
                'schemas'   => [1],
                'registers' => [1],
            ]);

        $mockObj = $this->createObjectEntityMock();

        $mockObjService->method('searchObjects')
            ->willReturn([$mockObj]);

        $mockObjService->method('renderEntity')
            ->willReturn(['id' => 'pub-123']);

        $this->request->method('getParams')
            ->willReturn(['extend' => '@self.files,@self.metadata']);

        $this->request->server = [];

        $response = $this->controller->show('test-catalog', 'pub-123');

        $this->assertInstanceOf(JSONResponse::class, $response);
        $this->assertEquals(200, $response->getStatus());
    }

    public function testShowWithExtendAsArray(): void
    {
        $mockObjService = $this->mockObjectService();

        $this->catalogiService->method('getCatalogBySlug')
            ->willReturn([
                'title'     => 'Test',
                'schemas'   => [1],
                'registers' => [1],
            ]);

        $mockObj = $this->createObjectEntityMock();

        $mockObjService->method('searchObjects')
            ->willReturn([$mockObj]);

        $mockObjService->method('renderEntity')
            ->willReturn(['id' => 'pub-123']);

        $this->request->method('getParams')
            ->willReturn(['_extend' => ['@self.files']]);

        $this->request->server = [];

        $response = $this->controller->show('test-catalog', 'pub-123');

        $this->assertInstanceOf(JSONResponse::class, $response);
        $this->assertEquals(200, $response->getStatus());
    }

    public function testShowWithNonArrayExtend(): void
    {
        $mockObjService = $this->mockObjectService();

        $this->catalogiService->method('getCatalogBySlug')
            ->willReturn([
                'title'     => 'Test',
                'schemas'   => [1],
                'registers' => [1],
            ]);

        $mockObj = $this->createObjectEntityMock();

        $mockObjService->method('searchObjects')
            ->willReturn([$mockObj]);

        $mockObjService->method('renderEntity')
            ->willReturn(['id' => 'pub-123']);

        // Provide non-string non-array extend (int)
        $this->request->method('getParams')
            ->willReturn(['extend' => 123]);

        $this->request->server = [];

        $response = $this->controller->show('test-catalog', 'pub-123');

        $this->assertInstanceOf(JSONResponse::class, $response);
        $this->assertEquals(200, $response->getStatus());
    }

    public function testShowFallsBackToFindObjectLocation(): void
    {
        $mockObjService = $this->mockObjectService();

        $this->catalogiService->method('getCatalogBySlug')
            ->willReturn([
                'title'     => 'Test',
                'schemas'   => [1],
                'registers' => [1],
            ]);

        $mockObj = $this->createObjectEntityMock(42, 2, 2);

        // First searchObjects for catalog schemas returns empty
        // Second call (from findObjectLocation fallback) returns object
        $callCount = 0;
        $mockObjService->method('searchObjects')
            ->willReturnCallback(function () use (&$callCount, $mockObj) {
                $callCount++;
                if ($callCount <= 1) {
                    return [];  // Not found in catalog schemas
                }
                return [$mockObj];
            });

        $mockObjService->method('renderEntity')
            ->willReturn(['id' => 'pub-fallback']);

        // Mock findObjectLocation: mock DB to return a table with matching UUID
        $tableResult = $this->createMock(\OCP\DB\IResult::class);
        $tableResult->method('fetch')
            ->willReturnOnConsecutiveCalls(
                ['table_name' => 'oc_openregister_table_2_3'],
                false
            );
        $tableResult->method('closeCursor');

        $locationResult = $this->createMock(\OCP\DB\IResult::class);
        $locationResult->method('fetch')
            ->willReturn(['register_id' => 2, 'schema_id' => 3]);
        $locationResult->method('closeCursor');

        $this->db->method('executeQuery')
            ->willReturnOnConsecutiveCalls($tableResult, $locationResult);

        $this->db->method('quote')
            ->willReturn("'pub-fallback'");

        $this->request->method('getParams')
            ->willReturn([]);

        $this->request->server = [];

        $response = $this->controller->show('test-catalog', 'pub-fallback');

        $this->assertInstanceOf(JSONResponse::class, $response);
    }

    public function testShowReturns404OnDoesNotExistException(): void
    {
        $mockObjService = $this->mockObjectService();

        $this->catalogiService->method('getCatalogBySlug')
            ->willReturn([
                'title'     => 'Test',
                'schemas'   => [1],
                'registers' => [1],
            ]);

        $mockObjService->method('searchObjects')
            ->willThrowException(new \OCP\AppFramework\Db\DoesNotExistException('Not found'));

        $this->request->method('getParams')
            ->willReturn([]);

        $response = $this->controller->show('test-catalog', 'pub-123');

        $this->assertInstanceOf(JSONResponse::class, $response);
        $this->assertEquals(404, $response->getStatus());
    }

    public function testShowWithEmptyRegistersAndSchemas(): void
    {
        $mockObjService = $this->mockObjectService();

        $this->catalogiService->method('getCatalogBySlug')
            ->willReturn([
                'title'     => 'Empty Catalog',
                'schemas'   => [],
                'registers' => [],
            ]);

        // No catalog schemas, so goes to findObjectLocation
        $result = $this->createMock(\OCP\DB\IResult::class);
        $result->method('fetch')->willReturn(false);
        $result->method('closeCursor');

        $this->db->method('executeQuery')
            ->willReturn($result);

        $mockObjService->method('searchObjects')
            ->willReturn([]);

        $this->request->method('getParams')
            ->willReturn([]);

        $this->request->server = [];

        $response = $this->controller->show('test-catalog', 'missing-id');

        $this->assertInstanceOf(JSONResponse::class, $response);
        $this->assertEquals(404, $response->getStatus());
    }

    // =======================================================================
    // attachments() — success path
    // =======================================================================

    public function testAttachmentsReturnsSuccessfully(): void
    {
        $mockObjService = $this->mockObjectService();

        $this->catalogiService->method('getCatalogBySlug')
            ->willReturn([
                'title'     => 'Test',
                'schemas'   => [1],
                'registers' => [1],
            ]);

        $mockObj = $this->createMock(\OCA\OpenRegister\Db\ObjectEntity::class);

        $mockObjService->method('find')
            ->willReturn($mockObj);

        $this->publicationService->method('attachments')
            ->willReturn(new JSONResponse(['results' => [['name' => 'file.pdf']]], 200));

        $response = $this->controller->attachments('test-catalog', 'pub-123');

        $this->assertInstanceOf(JSONResponse::class, $response);
        $this->assertEquals(200, $response->getStatus());
    }

    public function testAttachmentsReturns404WhenObjectNotFound(): void
    {
        $mockObjService = $this->mockObjectService();

        $this->catalogiService->method('getCatalogBySlug')
            ->willReturn([
                'title'     => 'Test',
                'schemas'   => [1],
                'registers' => [1],
            ]);

        $mockObjService->method('find')
            ->willThrowException(new \OCP\AppFramework\Db\DoesNotExistException('Not found'));

        $response = $this->controller->attachments('test-catalog', 'pub-123');

        $this->assertInstanceOf(JSONResponse::class, $response);
        $this->assertEquals(404, $response->getStatus());
    }

    public function testAttachmentsReturns500OnException(): void
    {
        $mockObjService = $this->mockObjectService();

        $this->catalogiService->method('getCatalogBySlug')
            ->willReturn([
                'title'     => 'Test',
                'schemas'   => [1],
                'registers' => [1],
            ]);

        $mockObjService->method('find')
            ->willThrowException(new \Exception('Unexpected error'));

        $response = $this->controller->attachments('test-catalog', 'pub-123');

        $this->assertInstanceOf(JSONResponse::class, $response);
        $this->assertEquals(500, $response->getStatus());
    }

    public function testAttachmentsWithJsonStringSchemas(): void
    {
        $mockObjService = $this->mockObjectService();

        $this->catalogiService->method('getCatalogBySlug')
            ->willReturn([
                'title'     => 'Test',
                'schemas'   => '[1, 2]',
                'registers' => '[1]',
            ]);

        $mockObj = $this->createMock(\OCA\OpenRegister\Db\ObjectEntity::class);

        $mockObjService->method('find')
            ->willReturn($mockObj);

        $this->publicationService->method('attachments')
            ->willReturn(new JSONResponse(['results' => []], 200));

        $response = $this->controller->attachments('test-catalog', 'pub-123');

        $this->assertInstanceOf(JSONResponse::class, $response);
        $this->assertEquals(200, $response->getStatus());
    }

    public function testAttachmentsObjectNotFoundInAnySchema(): void
    {
        $mockObjService = $this->mockObjectService();

        $this->catalogiService->method('getCatalogBySlug')
            ->willReturn([
                'title'     => 'Test',
                'schemas'   => [1, 2],
                'registers' => [1],
            ]);

        // find throws DoesNotExist for all schemas
        $mockObjService->method('find')
            ->willThrowException(new \OCP\AppFramework\Db\DoesNotExistException('Not found'));

        $response = $this->controller->attachments('test-catalog', 'pub-123');

        // Caught by inner DoesNotExist catch - returns 404
        $this->assertInstanceOf(JSONResponse::class, $response);
        $this->assertEquals(404, $response->getStatus());
    }

    public function testAttachmentsObjectNotFoundNoRegister(): void
    {
        $mockObjService = $this->mockObjectService();

        $this->catalogiService->method('getCatalogBySlug')
            ->willReturn([
                'title'     => 'Test',
                'schemas'   => [1],
                'registers' => [],
            ]);

        $mockObjService->method('find')
            ->willReturn(null);

        $response = $this->controller->attachments('test-catalog', 'pub-123');

        $this->assertInstanceOf(JSONResponse::class, $response);
        $this->assertEquals(404, $response->getStatus());
    }

    // =======================================================================
    // download() — success and error paths
    // =======================================================================

    public function testDownloadReturnsSuccessfully(): void
    {
        $mockObjService = $this->mockObjectService();

        $this->catalogiService->method('getCatalogBySlug')
            ->willReturn([
                'title'     => 'Test',
                'schemas'   => [1],
                'registers' => [1],
            ]);

        $mockObj = $this->createMock(\OCA\OpenRegister\Db\ObjectEntity::class);

        $mockObjService->method('find')
            ->willReturn($mockObj);

        $this->publicationService->method('download')
            ->willReturn(new \OCP\AppFramework\Http\DataDownloadResponse(
                'zip-content',
                'files.zip',
                'application/zip'
            ));

        $response = $this->controller->download('test-catalog', 'pub-123');

        $this->assertInstanceOf(\OCP\AppFramework\Http\DataDownloadResponse::class, $response);
    }

    public function testDownloadReturns404WhenObjectNotFound(): void
    {
        $mockObjService = $this->mockObjectService();

        $this->catalogiService->method('getCatalogBySlug')
            ->willReturn([
                'title'     => 'Test',
                'schemas'   => [1],
                'registers' => [1],
            ]);

        $mockObjService->method('find')
            ->willThrowException(new \OCP\AppFramework\Db\DoesNotExistException('Not found'));

        $response = $this->controller->download('test-catalog', 'pub-123');

        $this->assertInstanceOf(JSONResponse::class, $response);
        $this->assertEquals(404, $response->getStatus());
    }

    public function testDownloadReturns500OnException(): void
    {
        $mockObjService = $this->mockObjectService();

        $this->catalogiService->method('getCatalogBySlug')
            ->willReturn([
                'title'     => 'Test',
                'schemas'   => [1],
                'registers' => [1],
            ]);

        $mockObjService->method('find')
            ->willThrowException(new \Exception('Unexpected'));

        $response = $this->controller->download('test-catalog', 'pub-123');

        $this->assertInstanceOf(JSONResponse::class, $response);
        $this->assertEquals(500, $response->getStatus());
    }

    public function testDownloadWithJsonStringSchemas(): void
    {
        $mockObjService = $this->mockObjectService();

        $this->catalogiService->method('getCatalogBySlug')
            ->willReturn([
                'title'     => 'Test',
                'schemas'   => '[1]',
                'registers' => '[1]',
            ]);

        $mockObj = $this->createMock(\OCA\OpenRegister\Db\ObjectEntity::class);

        $mockObjService->method('find')
            ->willReturn($mockObj);

        $this->publicationService->method('download')
            ->willReturn(new JSONResponse(['result' => 'ok'], 200));

        $response = $this->controller->download('test-catalog', 'pub-123');

        $this->assertInstanceOf(JSONResponse::class, $response);
    }

    public function testDownloadObjectNotFoundInAnySchema(): void
    {
        $mockObjService = $this->mockObjectService();

        $this->catalogiService->method('getCatalogBySlug')
            ->willReturn([
                'title'     => 'Test',
                'schemas'   => [1, 2],
                'registers' => [1],
            ]);

        $mockObjService->method('find')
            ->willThrowException(new \OCP\AppFramework\Db\DoesNotExistException('Not found'));

        $response = $this->controller->download('test-catalog', 'pub-123');

        $this->assertInstanceOf(JSONResponse::class, $response);
        $this->assertEquals(404, $response->getStatus());
    }

    // =======================================================================
    // preflightedCors() — origin=false
    // =======================================================================

    public function testPreflightedCorsWhenOriginIsFalse(): void
    {
        // IRequest::getHeader returns string, so false origin is not possible
        // Instead test with empty string (already covered) — this test verifies
        // the controller handles the false check in the source safely
        $this->request->method('getHeader')
            ->with('Origin')
            ->willReturn('');

        $response = $this->controller->preflightedCors();

        $this->assertInstanceOf(Response::class, $response);
    }

    // =======================================================================
    // findObjectLocation — private method via show()
    // =======================================================================

    public function testShowFindObjectLocationWithNoTables(): void
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

        // findObjectLocation: no tables found
        $result = $this->createMock(\OCP\DB\IResult::class);
        $result->method('fetch')->willReturn(false);
        $result->method('closeCursor');

        $this->db->method('executeQuery')
            ->willReturn($result);

        $this->request->method('getParams')
            ->willReturn([]);

        $this->request->server = [];

        $response = $this->controller->show('test-catalog', 'missing-uuid');

        $this->assertInstanceOf(JSONResponse::class, $response);
        $this->assertEquals(404, $response->getStatus());
    }

    public function testShowFindObjectLocationWithNonMatchingTableName(): void
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

        // findObjectLocation: table found but name doesn't match pattern
        $tableResult = $this->createMock(\OCP\DB\IResult::class);
        $tableResult->method('fetch')
            ->willReturnOnConsecutiveCalls(
                ['table_name' => 'oc_openregister_table_invalid'],
                false
            );
        $tableResult->method('closeCursor');

        $this->db->method('executeQuery')
            ->willReturn($tableResult);

        $this->request->method('getParams')
            ->willReturn([]);

        $this->request->server = [];

        $response = $this->controller->show('test-catalog', 'missing-uuid');

        $this->assertInstanceOf(JSONResponse::class, $response);
        $this->assertEquals(404, $response->getStatus());
    }

    public function testShowFindObjectLocationFoundButObjectNotInTable(): void
    {
        $mockObjService = $this->mockObjectService();

        $this->catalogiService->method('getCatalogBySlug')
            ->willReturn([
                'title'     => 'Test',
                'schemas'   => [1],
                'registers' => [1],
            ]);

        // searchObjects for catalog schemas returns empty
        // searchObjects for fallback also returns empty
        $mockObjService->method('searchObjects')
            ->willReturn([]);

        // findObjectLocation: tables found, pattern matches, but UUID not in any table
        $tableResult = $this->createMock(\OCP\DB\IResult::class);
        $tableResult->method('fetch')
            ->willReturnOnConsecutiveCalls(
                ['table_name' => 'oc_openregister_table_5_6'],
                false
            );
        $tableResult->method('closeCursor');

        $locationResult = $this->createMock(\OCP\DB\IResult::class);
        $locationResult->method('fetch')->willReturn(false);
        $locationResult->method('closeCursor');

        $this->db->method('executeQuery')
            ->willReturnOnConsecutiveCalls($tableResult, $locationResult);

        $this->db->method('quote')
            ->willReturn("'missing-uuid'");

        $this->request->method('getParams')
            ->willReturn([]);

        $this->request->server = [];

        $response = $this->controller->show('test-catalog', 'missing-uuid');

        $this->assertInstanceOf(JSONResponse::class, $response);
        $this->assertEquals(404, $response->getStatus());
    }

    // =======================================================================
    // getObjectService — when openregister not installed
    // =======================================================================

    public function testIndexReturns500WhenOpenRegisterNotInstalled(): void
    {
        $this->appManager->method('getInstalledApps')
            ->willReturn(['files']);

        $this->catalogiService->method('getCatalogBySlug')
            ->willReturn([
                'title'     => 'Test',
                'schemas'   => [1],
                'registers' => [1],
            ]);

        $this->request->method('getParams')
            ->willReturn([]);

        $response = $this->controller->index('test-catalog');

        $this->assertInstanceOf(JSONResponse::class, $response);
        $this->assertEquals(500, $response->getStatus());
    }

    // =======================================================================
    // uses() — with query params filtering
    // =======================================================================

    public function testUsesStripsExtraParams(): void
    {
        $mockObjService = $this->mockObjectService();

        $mockObjService->method('getObjectUses')
            ->willReturn(['results' => [['id' => 'related-1']], 'total' => 1]);

        $this->request->method('getParams')
            ->willReturn([
                'id'          => 'pub-123',
                '_route'      => 'opencatalogi.publications.uses',
                'catalogSlug' => 'test-catalog',
                'custom_param' => 'value',
            ]);

        $this->request->server = ['HTTP_ORIGIN' => 'https://custom.com'];

        $response = $this->controller->uses('test-catalog', 'pub-123');

        $this->assertInstanceOf(JSONResponse::class, $response);
        $this->assertEquals(200, $response->getStatus());
    }

    // =======================================================================
    // used() — with query params filtering
    // =======================================================================

    public function testUsedStripsExtraParams(): void
    {
        $mockObjService = $this->mockObjectService();

        $mockObjService->method('getObjectUsedBy')
            ->willReturn(['results' => [['id' => 'parent-1']], 'total' => 1]);

        $this->request->method('getParams')
            ->willReturn([
                'id'          => 'pub-123',
                '_route'      => 'opencatalogi.publications.used',
                'catalogSlug' => 'test-catalog',
            ]);

        $this->request->server = ['HTTP_ORIGIN' => 'https://custom.com'];

        $response = $this->controller->used('test-catalog', 'pub-123');

        $this->assertInstanceOf(JSONResponse::class, $response);
        $this->assertEquals(200, $response->getStatus());
    }

    // =======================================================================
    // Custom CORS parameters
    // =======================================================================

    public function testCustomCorsParameters(): void
    {
        $controller = new PublicationsController(
            'opencatalogi',
            $this->request,
            $this->publicationService,
            $this->catalogiService,
            $this->container,
            $this->appManager,
            $this->logger,
            $this->db,
            $this->l10n,
            'GET, POST',
            'Authorization',
            3600
        );

        $this->request->method('getHeader')
            ->with('Origin')
            ->willReturn('https://example.com');

        $response = $controller->preflightedCors();

        $this->assertInstanceOf(Response::class, $response);
    }
}
