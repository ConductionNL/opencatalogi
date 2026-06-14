<?php

declare(strict_types=1);

namespace Unit\Controller;

use OCA\OpenCatalogi\Controller\PublicationsController;
use OCA\OpenCatalogi\Service\CatalogiService;
use OCA\OpenCatalogi\Service\PublicationQueryService;
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
    private CatalogiService|MockObject $catalogiService;
    private PublicationQueryService|MockObject $queryService;
    private ContainerInterface|MockObject $container;
    private IAppManager|MockObject $appManager;
    private LoggerInterface|MockObject $logger;
    private IDBConnection|MockObject $db;
    private IL10N|MockObject $l10n;
    private IAppConfig|MockObject $appConfig;
    private \OCA\OpenCatalogi\Service\UsageCounterService|MockObject $usageCounterService;
    private PublicationsController $controller;

    protected function setUp(): void
    {
        $this->request            = $this->createMock(IRequest::class);
        $this->publicationService = $this->createMock(PublicationService::class);
        $this->catalogiService    = $this->createMock(CatalogiService::class);
        $this->queryService       = $this->createMock(PublicationQueryService::class);
        $this->container          = $this->createMock(ContainerInterface::class);
        $this->appManager         = $this->createMock(IAppManager::class);
        $this->logger             = $this->createMock(LoggerInterface::class);
        $this->db                 = $this->createMock(IDBConnection::class);
        $this->l10n               = $this->createMock(IL10N::class);
        $this->appConfig          = $this->createMock(IAppConfig::class);
        $this->usageCounterService = $this->createMock(\OCA\OpenCatalogi\Service\UsageCounterService::class);

        $this->l10n->method('t')
            ->willReturnCallback(fn(string $text, array $params = []) => $text);

        // CORS allowlist defaults to '*' (wildcard) unless a test overrides it.
        $this->appConfig->method('getValueString')
            ->willReturnCallback(fn(string $app, string $key, string $default = '') => $default);

        // Default query-service behaviour: the search query and result shaping are
        // pass-throughs and schema/register resolution is empty. Object visibility is
        // enforced by OpenRegister RBAC (not by the controller), so there is no
        // published-predicate collaborator to stub here.
        $this->queryService->method('buildCatalogSearchQuery')->willReturn([]);
        $this->queryService->method('stripEmptyValues')
            ->willReturnCallback(fn(array $data) => $data);
        $this->queryService->method('resolveSchemaAndRegisterObjects')
            ->willReturn(['schemas' => [], 'registers' => []]);
        $this->queryService->method('findObjectLocation')->willReturn(null);
        // findObjectInCatalog defaults to "found" so the attachments/download happy
        // paths proceed; the not-found/exception tests rebuild the controller with a
        // query service that returns null (or whose collaborators throw).
        $this->queryService->method('findObjectInCatalog')
            ->willReturn($this->createMock(\OCA\OpenRegister\Db\ObjectEntity::class));

        $this->controller = new PublicationsController(
            'opencatalogi',
            $this->request,
            $this->publicationService,
            $this->catalogiService,
            $this->queryService,
            $this->container,
            $this->appManager,
            $this->logger,
            $this->l10n,
            $this->usageCounterService,
            $this->appConfig
        );
    }

    /**
     * Rebuilds $this->queryService and the controller so findObjectInCatalog either
     * returns null (object not in catalog) or throws the supplied exception. Used by
     * the attachments/download not-found and error-path tests, whose object lookup
     * now lives in PublicationQueryService rather than the controller.
     *
     * @param \Throwable|null $throw When set, findObjectInCatalog throws it; otherwise it returns null.
     */
    private function stubFindObjectInCatalog(?\Throwable $throw = null): void
    {
        $this->queryService = $this->createMock(PublicationQueryService::class);
        $this->queryService->method('buildCatalogSearchQuery')->willReturn([]);
        $this->queryService->method('stripEmptyValues')
            ->willReturnCallback(fn(array $data) => $data);
        $this->queryService->method('resolveSchemaAndRegisterObjects')
            ->willReturn(['schemas' => [], 'registers' => []]);
        $this->queryService->method('findObjectLocation')->willReturn(null);
        if ($throw !== null) {
            $this->queryService->method('findObjectInCatalog')->willThrowException($throw);
        } else {
            $this->queryService->method('findObjectInCatalog')->willReturn(null);
        }

        $this->controller = $this->newControllerWithQueryService();
    }

    /**
     * Rebuilds the controller using the current $this->queryService mock. Used by
     * tests that need to re-stub query-service behaviour after setUp().
     */
    private function newControllerWithQueryService(): PublicationsController
    {
        return new PublicationsController(
            'opencatalogi',
            $this->request,
            $this->publicationService,
            $this->catalogiService,
            $this->queryService,
            $this->container,
            $this->appManager,
            $this->logger,
            $this->l10n,
            $this->usageCounterService,
            $this->appConfig
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

        // WF2 guard (wave-12): find() must return a non-null, public object so the
        // published-predicate check passes and control reaches getObjectUses().
        $rootObject = $this->createFindResultMock(['id' => 'pub-123', '@self' => ['published' => '2024-01-01T00:00:00+00:00']]);
        $mockObjService->method('find')
            ->willReturn($rootObject);

        $mockObjService->method('getObjectUses')
            ->willReturn(['results' => [], 'total' => 0]);

        $this->request->method('getParams')
            ->willReturn([]);

        $this->request->server = [];

        $response = $this->controller->uses('test-catalog', 'pub-123');

        $this->assertInstanceOf(JSONResponse::class, $response);
        $this->assertEquals(200, $response->getStatus());
    }

    public function testUsesReturnsNotFoundWhenObjectIsNull(): void
    {
        $mockObjService = $this->mockObjectService();

        // find() returns null → guard returns 404 before reaching getObjectUses().
        $mockObjService->method('find')->willReturn(null);

        $this->request->method('getParams')->willReturn([]);

        $response = $this->controller->uses('test-catalog', 'pub-123');

        $this->assertInstanceOf(JSONResponse::class, $response);
        $this->assertEquals(404, $response->getStatus());
    }

    public function testUsesReturnsNotFoundWhenAnonymousAndUnpublished(): void
    {
        $mockObjService = $this->mockObjectService();

        // find() returns an unpublished object; caller is anonymous.
        $rootObject = $this->createFindResultMock(['id' => 'pub-123', '@self' => []]);
        $mockObjService->method('find')->willReturn($rootObject);

        $this->queryService = $this->createMock(PublicationQueryService::class);
        $this->queryService->method('findObjectLocation')->willReturn(null);
        $this->queryService->method('isAnonymous')->willReturn(true);
        $this->queryService->method('isObjectPublic')->willReturn(false);
        $controller = $this->newControllerWithQueryService();

        $this->request->method('getParams')->willReturn([]);

        $response = $controller->uses('test-catalog', 'pub-123');

        $this->assertInstanceOf(JSONResponse::class, $response);
        $this->assertEquals(404, $response->getStatus());
    }

    public function testUsesReturns500OnException(): void
    {
        $mockObjService = $this->mockObjectService();

        // find() returns a valid object so the guard passes; getObjectUses throws.
        $rootObject = $this->createFindResultMock(['id' => 'pub-123', '@self' => ['published' => '2024-01-01T00:00:00+00:00']]);
        $mockObjService->method('find')->willReturn($rootObject);

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

        // WF2 guard (wave-12): find() must return a non-null, public object so the
        // published-predicate check passes and control reaches getObjectUsedBy().
        $rootObject = $this->createFindResultMock(['id' => 'pub-123', '@self' => ['published' => '2024-01-01T00:00:00+00:00']]);
        $mockObjService->method('find')
            ->willReturn($rootObject);

        $mockObjService->method('getObjectUsedBy')
            ->willReturn(['results' => [], 'total' => 0]);

        $this->request->method('getParams')
            ->willReturn([]);

        $this->request->server = [];

        $response = $this->controller->used('test-catalog', 'pub-123');

        $this->assertInstanceOf(JSONResponse::class, $response);
        $this->assertEquals(200, $response->getStatus());
    }

    public function testUsedReturnsNotFoundWhenObjectIsNull(): void
    {
        $mockObjService = $this->mockObjectService();

        // find() returns null → guard returns 404 before reaching getObjectUsedBy().
        $mockObjService->method('find')->willReturn(null);

        $this->request->method('getParams')->willReturn([]);

        $response = $this->controller->used('test-catalog', 'pub-123');

        $this->assertInstanceOf(JSONResponse::class, $response);
        $this->assertEquals(404, $response->getStatus());
    }

    public function testUsedReturnsNotFoundWhenAnonymousAndUnpublished(): void
    {
        $mockObjService = $this->mockObjectService();

        // find() returns an unpublished object; caller is anonymous.
        $rootObject = $this->createFindResultMock(['id' => 'pub-123', '@self' => []]);
        $mockObjService->method('find')->willReturn($rootObject);

        $this->queryService = $this->createMock(PublicationQueryService::class);
        $this->queryService->method('findObjectLocation')->willReturn(null);
        $this->queryService->method('isAnonymous')->willReturn(true);
        $this->queryService->method('isObjectPublic')->willReturn(false);
        $controller = $this->newControllerWithQueryService();

        $this->request->method('getParams')->willReturn([]);

        $response = $controller->used('test-catalog', 'pub-123');

        $this->assertInstanceOf(JSONResponse::class, $response);
        $this->assertEquals(404, $response->getStatus());
    }

    public function testUsedReturns500OnException(): void
    {
        $mockObjService = $this->mockObjectService();

        // find() returns a valid object so the guard passes; getObjectUsedBy throws.
        $rootObject = $this->createFindResultMock(['id' => 'pub-123', '@self' => ['published' => '2024-01-01T00:00:00+00:00']]);
        $mockObjService->method('find')->willReturn($rootObject);

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
     * Helper to create an ObjectEntity mock whose jsonSerialize() returns a given payload.
     *
     * ObjectService::find() declares a return type of ?ObjectEntity, so the WF2
     * published-predicate guard in uses()/used() receives an ObjectEntity (not a raw
     * array). The controller calls jsonSerialize() on it before passing it to the
     * isObjectPublic() check, so the mock must surface the desired array there.
     *
     * @param array $payload The array jsonSerialize() should return.
     *
     * @return \OCA\OpenRegister\Db\ObjectEntity
     */
    private function createFindResultMock(array $payload): \OCA\OpenRegister\Db\ObjectEntity
    {
        $mockObj = $this->getMockBuilder(\OCA\OpenRegister\Db\ObjectEntity::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['jsonSerialize'])
            ->getMock();
        $mockObj->method('jsonSerialize')->willReturn($payload);
        return $mockObj;
    }

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

        // The query service resolves the object's register/schema across the magic
        // tables; the controller then re-queries ObjectService with that location.
        $this->queryService = $this->createMock(PublicationQueryService::class);
        $this->queryService->method('buildCatalogSearchQuery')->willReturn([]);
        $this->queryService->method('stripEmptyValues')
            ->willReturnCallback(fn(array $data) => $data);
        $this->queryService->method('resolveSchemaAndRegisterObjects')
            ->willReturn(['schemas' => [], 'registers' => []]);
        $this->queryService->method('findObjectLocation')
            ->willReturn(['register' => 2, 'schema' => 3]);
        $this->controller = $this->newControllerWithQueryService();

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

    /**
     * Security (#737): an anonymous caller must not be able to retrieve an object that
     * OpenRegister RBAC does not grant them. Visibility is enforced inside the _rbac: true
     * searches, so a denied object (e.g. an unpublished publication) resolves to an empty
     * result and the controller reports 404 — it never discloses the object.
     */
    public function testShowDeniesAnonymousAccessToRbacDeniedObject(): void
    {
        $mockObjService = $this->mockObjectService();

        $this->catalogiService->method('getCatalogBySlug')
            ->willReturn([
                'title'     => 'Test',
                'schemas'   => [1],
                'registers' => [1],
            ]);

        // RBAC denies the object to this (anonymous) caller, so the _rbac: true search
        // returns nothing — the controller must treat it as not found.
        $mockObjService->method('searchObjects')
            ->willReturn([]);

        $this->queryService = $this->createMock(PublicationQueryService::class);
        $this->queryService->method('buildCatalogSearchQuery')->willReturn([]);
        $this->queryService->method('stripEmptyValues')
            ->willReturnCallback(fn(array $data) => $data);
        $this->queryService->method('resolveSchemaAndRegisterObjects')
            ->willReturn(['schemas' => [], 'registers' => []]);
        $this->queryService->method('findObjectLocation')->willReturn(null);
        $this->controller = $this->newControllerWithQueryService();

        $this->request->method('getParams')->willReturn([]);
        $this->request->server = [];

        $response = $this->controller->show('test-catalog', 'pub-secret');

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

        $this->stubFindObjectInCatalog(new \OCP\AppFramework\Db\DoesNotExistException('Not found'));

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

        $this->stubFindObjectInCatalog(new \Exception('Unexpected error'));

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

        // findObjectInCatalog throws DoesNotExist (object absent from every schema).
        $this->stubFindObjectInCatalog(new \OCP\AppFramework\Db\DoesNotExistException('Not found'));

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

        $this->stubFindObjectInCatalog();

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

        $this->stubFindObjectInCatalog(new \OCP\AppFramework\Db\DoesNotExistException('Not found'));

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

        $this->stubFindObjectInCatalog(new \Exception('Unexpected'));

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

        $this->stubFindObjectInCatalog(new \OCP\AppFramework\Db\DoesNotExistException('Not found'));

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

        // WF2 guard (wave-12): find() must return a published object so control
        // reaches getObjectUses() where the param stripping under test happens.
        $mockObjService->method('find')
            ->willReturn($this->createFindResultMock(['id' => 'pub-123', '@self' => ['published' => '2024-01-01T00:00:00+00:00']]));

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

        // WF2 guard (wave-12): find() must return a published object so control
        // reaches getObjectUsedBy() where the param stripping under test happens.
        $mockObjService->method('find')
            ->willReturn($this->createFindResultMock(['id' => 'pub-123', '@self' => ['published' => '2024-01-01T00:00:00+00:00']]));

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
            $this->queryService,
            $this->container,
            $this->appManager,
            $this->logger,
            $this->l10n,
            $this->usageCounterService,
            $this->appConfig,
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

    // =======================================================================
    // #732 — extend allowlist + breadth cap on public show()
    // =======================================================================

    /**
     * Security (#732): a non-'@self.'-prefixed extend entry must be stripped before
     * reaching ObjectService::renderEntity. The public endpoint must only traverse
     * relations under @self.* — never arbitrary properties.
     */
    public function testShowStripsNonSelfExtendOnPublicEndpoint(): void
    {
        $mockObjService = $this->mockObjectService();

        $this->catalogiService->method('getCatalogBySlug')
            ->willReturn(['title' => 'T', 'schemas' => [1], 'registers' => [1]]);

        $mockObj = $this->createObjectEntityMock(register: 1, schema: 1);
        $mockObjService->method('searchObjects')->willReturn([$mockObj]);

        $captured = [];
        $mockObjService->method('renderEntity')
            ->willReturnCallback(function (...$args) use (&$captured) {
                // Named args land as a single assoc array on PHP positional call.
                $captured[] = func_get_args();
                return ['id' => 'pub-123'];
            });

        $this->request->method('getParams')
            ->willReturn(['_extend' => ['@self.files', 'parent', 'children', '@self.metadata']]);

        $this->request->server = [];

        $response = $this->controller->show('test-catalog', 'pub-123');

        $this->assertEquals(200, $response->getStatus());
        // Inspect the rendered-entity call: only '@self.'-prefixed entries should survive.
        $this->assertGreaterThan(0, count($captured));
        $renderArgs = $captured[0];
        // The _extend arg is the 2nd positional one.
        $extendArg = $renderArgs[1];
        $this->assertContains('@self.files', $extendArg);
        $this->assertContains('@self.metadata', $extendArg);
        $this->assertNotContains('parent', $extendArg);
        $this->assertNotContains('children', $extendArg);
    }

    /**
     * Security (#732): extend breadth is capped to MAX_PUBLIC_EXTEND (5) to prevent
     * N+1 amplification on the public endpoint.
     */
    public function testShowCapsExtendBreadthAtFive(): void
    {
        $mockObjService = $this->mockObjectService();

        $this->catalogiService->method('getCatalogBySlug')
            ->willReturn(['title' => 'T', 'schemas' => [1], 'registers' => [1]]);

        $mockObj = $this->createObjectEntityMock(register: 1, schema: 1);
        $mockObjService->method('searchObjects')->willReturn([$mockObj]);

        $captured = [];
        $mockObjService->method('renderEntity')
            ->willReturnCallback(function (...$args) use (&$captured) {
                $captured[] = $args;
                return ['id' => 'pub-123'];
            });

        // Twelve valid '@self.' entries — only five should survive the cap.
        $extend = [];
        for ($i = 0; $i < 12; $i++) {
            $extend[] = '@self.field'.$i;
        }

        $this->request->method('getParams')->willReturn(['_extend' => $extend]);
        $this->request->server = [];

        $response = $this->controller->show('test-catalog', 'pub-123');

        $this->assertEquals(200, $response->getStatus());
        $this->assertCount(5, $captured[0][1]);
    }

    // =======================================================================
    // #733 — catalog-membership validation on show()
    // =======================================================================

    /**
     * Security (#733): when the resolved object's register/schema is NOT in the
     * requested catalog's scope, show() MUST return 404 — never disclose the object.
     */
    public function testShowReturns404WhenObjectOutsideCatalogScope(): void
    {
        $mockObjService = $this->mockObjectService();

        $this->catalogiService->method('getCatalogBySlug')
            ->willReturn([
                'title'     => 'Catalog A',
                'schemas'   => [10],
                'registers' => [20],
            ]);

        // Object belongs to a DIFFERENT register/schema than the catalog's scope.
        $foreignObject = $this->createObjectEntityMock(register: 99, schema: 99);

        // Fast-path searchObjects returns nothing (since the catalog scope is 20/10).
        // The fallback then finds the foreign object via findObjectLocation.
        $callCount = 0;
        $mockObjService->method('searchObjects')
            ->willReturnCallback(function () use (&$callCount, $foreignObject) {
                $callCount++;
                // Fast path empty; fallback returns the foreign-scope object.
                return ($callCount === 1 ? [] : [$foreignObject]);
            });

        // Stub findObjectLocation to claim the object is in the catalog's scope so we
        // exercise the post-lookup membership check (not the upstream constraint).
        $this->queryService = $this->createMock(PublicationQueryService::class);
        $this->queryService->method('buildCatalogSearchQuery')->willReturn([]);
        $this->queryService->method('stripEmptyValues')
            ->willReturnCallback(fn(array $d) => $d);
        $this->queryService->method('resolveSchemaAndRegisterObjects')
            ->willReturn(['schemas' => [], 'registers' => []]);
        $this->queryService->method('findObjectLocation')
            ->willReturn(['register' => 20, 'schema' => 10]);
        $this->controller = $this->newControllerWithQueryService();

        $this->request->method('getParams')->willReturn([]);
        $this->request->server = [];

        $response = $this->controller->show('catalog-a', 'foreign-uuid');

        $this->assertEquals(404, $response->getStatus());
    }

    // =======================================================================
    // #734 — findObjectLocation must NOT be called platform-wide on show()
    // =======================================================================

    /**
     * Security (#734): findObjectLocation must be invoked WITH catalog-scope
     * constraints, never as an unbounded platform-wide lookup.
     */
    public function testShowCallsFindObjectLocationWithCatalogScope(): void
    {
        $mockObjService = $this->mockObjectService();

        $this->catalogiService->method('getCatalogBySlug')
            ->willReturn([
                'title'     => 'Catalog',
                'schemas'   => [11, 12],
                'registers' => [21],
            ]);

        $mockObjService->method('searchObjects')->willReturn([]);

        $this->queryService = $this->createMock(PublicationQueryService::class);
        $this->queryService->method('buildCatalogSearchQuery')->willReturn([]);
        $this->queryService->method('stripEmptyValues')
            ->willReturnCallback(fn(array $d) => $d);
        $this->queryService->method('resolveSchemaAndRegisterObjects')
            ->willReturn(['schemas' => [], 'registers' => []]);

        // The controller MUST call findObjectLocation with the catalog's scope
        // (allowedRegisters + allowedSchemas), never with just $uuid alone.
        $this->queryService->expects($this->atLeastOnce())
            ->method('findObjectLocation')
            ->with(
                $this->equalTo('missing-id'),
                $this->equalTo([21]),
                $this->equalTo([11, 12])
            )
            ->willReturn(null);

        $this->controller = $this->newControllerWithQueryService();

        $this->request->method('getParams')->willReturn([]);
        $this->request->server = [];

        $response = $this->controller->show('any-slug', 'missing-id');

        // Object not found — 404. The point is the call-shape assertion above.
        $this->assertEquals(404, $response->getStatus());
    }

    /**
     * Security (#734): show() must NOT invoke findObjectLocation at all when the
     * catalog has no configured registers/schemas — an unscoped catalog cannot be
     * used as a platform-wide namespace.
     */
    public function testShowSkipsFindObjectLocationForUnscopedCatalog(): void
    {
        $mockObjService = $this->mockObjectService();

        $this->catalogiService->method('getCatalogBySlug')
            ->willReturn([
                'title'     => 'Empty',
                'schemas'   => [],
                'registers' => [],
            ]);

        $mockObjService->method('searchObjects')->willReturn([]);

        $this->queryService = $this->createMock(PublicationQueryService::class);
        $this->queryService->method('buildCatalogSearchQuery')->willReturn([]);
        $this->queryService->method('stripEmptyValues')
            ->willReturnCallback(fn(array $d) => $d);
        $this->queryService->method('resolveSchemaAndRegisterObjects')
            ->willReturn(['schemas' => [], 'registers' => []]);

        // findObjectLocation MUST NOT be called for an unscoped catalog.
        $this->queryService->expects($this->never())
            ->method('findObjectLocation');

        $this->controller = $this->newControllerWithQueryService();

        $this->request->method('getParams')->willReturn([]);
        $this->request->server = [];

        $response = $this->controller->show('unscoped-catalog', 'any-uuid');

        $this->assertEquals(404, $response->getStatus());
    }

    // =======================================================================
    // #735 — CORS Origin allowlist + generic error responses
    // =======================================================================

    /**
     * Security (#735): when the allowlist is configured (non-'*'), an attacker-
     * controlled Origin must NOT be reflected back in Access-Control-Allow-Origin.
     */
    public function testIndexDoesNotReflectArbitraryOriginWhenAllowlistConfigured(): void
    {
        $this->appConfig = $this->createMock(IAppConfig::class);
        $this->appConfig->method('getValueString')
            ->willReturnCallback(function (string $app, string $key, string $default = '') {
                return match ($key) {
                    'cors_allowed_origins' => 'https://trusted.example',
                    default                => $default,
                };
            });
        $this->controller = $this->newControllerWithQueryService();

        $mockObjService = $this->mockObjectService();
        $this->catalogiService->method('getCatalogBySlug')
            ->willReturn(['title' => 'T', 'schemas' => [1], 'registers' => [1]]);
        $mockObjService->method('buildSearchQuery')->willReturn([]);
        $mockObjService->method('searchObjectsPaginated')
            ->willReturn(['results' => [], 'total' => 0]);
        $this->request->method('getParams')->willReturn([]);
        $this->request->method('getHeader')
            ->with('Origin')
            ->willReturn('https://evil.attacker.test');
        $this->request->server = ['HTTP_ORIGIN' => 'https://evil.attacker.test'];

        $response = $this->controller->index('test');

        $this->assertSame(
            'https://trusted.example',
            $response->getHeaders()['Access-Control-Allow-Origin']
        );
    }

    /**
     * Security (#735): a public 500 response must NOT leak the raw exception message.
     */
    public function testShowReturns500WithoutLeakingExceptionMessage(): void
    {
        $secretMessage = 'PDOException: SQLSTATE[42S22] internal_table.column at /var/www/secret.php:99';

        $this->catalogiService->method('getCatalogBySlug')
            ->willThrowException(new \Exception($secretMessage));

        $response = $this->controller->show('test-catalog', 'pub-123');

        $this->assertSame(500, $response->getStatus());
        $body = json_encode($response->getData());
        $this->assertStringNotContainsString($secretMessage, (string) $body);
        $this->assertStringNotContainsString('PDOException', (string) $body);
        $this->assertStringNotContainsString('/var/www', (string) $body);
    }

    /**
     * Security (#735): a public 500 on index() must NOT leak the raw exception message.
     */
    public function testIndexReturns500WithoutLeakingExceptionMessage(): void
    {
        $secretMessage = 'PDOException: internal-server-hostname:1234 leaked';

        $this->catalogiService->method('getCatalogBySlug')
            ->willThrowException(new \Exception($secretMessage));

        $response = $this->controller->index('test-catalog');

        $this->assertSame(500, $response->getStatus());
        $body = json_encode($response->getData());
        $this->assertStringNotContainsString($secretMessage, (string) $body);
        $this->assertStringNotContainsString('internal-server-hostname', (string) $body);
    }
}
