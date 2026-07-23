<?php

declare(strict_types=1);

namespace Unit\Service;

use OCA\OpenCatalogi\Service\CatalogiService;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Service\ObjectService;
use OCA\OpenRegister\Service\FileService;
use OCP\Common\Exception\NotFoundException;
use OCP\IAppConfig;
use OCP\IRequest;
use OCP\App\IAppManager;
use OCP\AppFramework\Http\JSONResponse;
use OCP\ICache;
use OCP\ICacheFactory;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Unit tests for CatalogiService.
 */
class CatalogiServiceTest extends TestCase
{

    private IAppConfig|MockObject $config;

    private IRequest|MockObject $request;

    private ContainerInterface|MockObject $container;

    private IAppManager|MockObject $appManager;

    private ICacheFactory|MockObject $cacheFactory;

    private LoggerInterface|MockObject $logger;

    private ICache|MockObject $cache;

    private IUserSession|MockObject $userSession;

    private CatalogiService $service;

    protected function setUp(): void
    {
        $this->config       = $this->createMock(IAppConfig::class);
        $this->request      = $this->createMock(IRequest::class);
        $this->container    = $this->createMock(ContainerInterface::class);
        $this->appManager   = $this->createMock(IAppManager::class);
        $this->cacheFactory = $this->createMock(ICacheFactory::class);
        $this->logger       = $this->createMock(LoggerInterface::class);
        $this->cache        = $this->createMock(ICache::class);
        $this->userSession  = $this->createMock(IUserSession::class);

        $this->cacheFactory->method('createDistributed')
            ->with('opencatalogi_catalogs')
            ->willReturn($this->cache);

        // Default to an anonymous (logged-out) session so every pre-existing test in
        // this file keeps asserting the historical stripped envelope without change.
        $this->userSession->method('getUser')->willReturn(null);

        $this->service = new CatalogiService(
            $this->config,
            $this->request,
            $this->container,
            $this->appManager,
            $this->cacheFactory,
            $this->logger,
            $this->userSession,
        );
    }//end setUp()

    // ──────────────────────────────────────────────────────────
    // getObjectService
    // ──────────────────────────────────────────────────────────
    public function testGetObjectServiceAvailable(): void
    {
        $objectService = $this->createMock(ObjectService::class);

        $this->appManager->method('getInstalledApps')
            ->willReturn(['openregister', 'opencatalogi']);

        $this->container->method('get')
            ->with('OCA\OpenRegister\Service\ObjectService')
            ->willReturn($objectService);

        $result = $this->service->getObjectService();
        $this->assertSame($objectService, $result);
    }//end testGetObjectServiceAvailable()

    public function testGetObjectServiceNotAvailable(): void
    {
        $this->appManager->method('getInstalledApps')
            ->willReturn(['opencatalogi']);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('OpenRegister service is not available.');

        $this->service->getObjectService();
    }//end testGetObjectServiceNotAvailable()

    // ──────────────────────────────────────────────────────────
    // getFileService
    // ──────────────────────────────────────────────────────────
    public function testGetFileServiceAvailable(): void
    {
        $fileService = $this->createMock(FileService::class);

        $this->appManager->method('getInstalledApps')
            ->willReturn(['openregister']);

        $this->container->method('get')
            ->with('OCA\OpenRegister\Service\FileService')
            ->willReturn($fileService);

        $result = $this->service->getFileService();
        $this->assertSame($fileService, $result);
    }//end testGetFileServiceAvailable()

    public function testGetFileServiceNotAvailable(): void
    {
        $this->appManager->method('getInstalledApps')
            ->willReturn([]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('OpenRegister service is not available.');

        $this->service->getFileService();
    }//end testGetFileServiceNotAvailable()

    // ──────────────────────────────────────────────────────────
    // getCatalogFilters
    // ──────────────────────────────────────────────────────────
    public function testGetCatalogFiltersWithoutCatalogId(): void
    {
        $this->config->method('getValueString')
            ->willReturnMap(
                    [
                        ['opencatalogi', 'catalog_schema', '', 'schema-1'],
                        ['opencatalogi', 'catalog_register', '', 'register-1'],
                    ]
                    );

        $catalogObject = $this->createMockCatalogObject(
                [
                    'registers' => ['reg-a', 'reg-b'],
                    'schemas'   => ['sch-x', 'sch-y'],
                ]
                );

        $objectService = $this->createMock(ObjectService::class);
        $objectService->method('searchObjects')
            ->willReturn([$catalogObject]);

        $this->injectObjectService($objectService);

        $result = $this->service->getCatalogFilters();

        $this->assertArrayHasKey('registers', $result);
        $this->assertArrayHasKey('schemas', $result);
        $this->assertEquals(['reg-a', 'reg-b'], $result['registers']);
        $this->assertEquals(['sch-x', 'sch-y'], $result['schemas']);
    }//end testGetCatalogFiltersWithoutCatalogId()

    public function testGetCatalogFiltersWithCatalogId(): void
    {
        $this->config->method('getValueString')
            ->willReturnMap(
                    [
                        ['opencatalogi', 'catalog_schema', '', 'schema-1'],
                        ['opencatalogi', 'catalog_register', '', 'register-1'],
                    ]
                    );

        $catalogObject = $this->createMockCatalogObject(
                [
                    'registers' => ['reg-c'],
                    'schemas'   => ['sch-z'],
                ]
                );

        $objectService = $this->createMock(ObjectService::class);
        $objectService->method('searchObjects')
            ->willReturn([$catalogObject]);

        $this->injectObjectService($objectService);

        $result = $this->service->getCatalogFilters('catalog-uuid-123');

        $this->assertEquals(['reg-c'], $result['registers']);
        $this->assertEquals(['sch-z'], $result['schemas']);
    }//end testGetCatalogFiltersWithCatalogId()

    public function testGetCatalogFiltersWithMultipleCatalogsDeduplicated(): void
    {
        $this->config->method('getValueString')
            ->willReturnMap(
                    [
                        ['opencatalogi', 'catalog_schema', '', 'schema-1'],
                        ['opencatalogi', 'catalog_register', '', 'register-1'],
                    ]
                    );

        $cat1 = $this->createMockCatalogObject(
                [
                    'registers' => ['reg-a', 'reg-b'],
                    'schemas'   => ['sch-x'],
                ]
                );
        $cat2 = $this->createMockCatalogObject(
                [
                    'registers' => ['reg-b', 'reg-c'],
                    'schemas'   => ['sch-x', 'sch-y'],
                ]
                );

        $objectService = $this->createMock(ObjectService::class);
        $objectService->method('searchObjects')
            ->willReturn([$cat1, $cat2]);

        $this->injectObjectService($objectService);

        $result = $this->service->getCatalogFilters();

        $this->assertCount(3, $result['registers']);
        $this->assertContains('reg-a', $result['registers']);
        $this->assertContains('reg-b', $result['registers']);
        $this->assertContains('reg-c', $result['registers']);
        $this->assertCount(2, $result['schemas']);
    }//end testGetCatalogFiltersWithMultipleCatalogsDeduplicated()

    public function testGetCatalogFiltersEmptyResults(): void
    {
        $this->config->method('getValueString')
            ->willReturnMap(
                    [
                        ['opencatalogi', 'catalog_schema', '', 'schema-1'],
                        ['opencatalogi', 'catalog_register', '', 'register-1'],
                    ]
                    );

        $objectService = $this->createMock(ObjectService::class);
        $objectService->method('searchObjects')
            ->willReturn([]);

        $this->injectObjectService($objectService);

        $result = $this->service->getCatalogFilters();

        $this->assertEquals([], $result['registers']);
        $this->assertEquals([], $result['schemas']);
    }//end testGetCatalogFiltersEmptyResults()

    // ──────────────────────────────────────────────────────────
    // getAvailableRegisters / getAvailableSchemas
    // ──────────────────────────────────────────────────────────
    public function testGetAvailableRegistersReturnsStoredValues(): void
    {
        $reflection = new \ReflectionClass($this->service);
        $prop       = $reflection->getProperty('availableRegisters');
        $prop->setAccessible(true);
        $prop->setValue($this->service, ['reg-1', 'reg-2']);

        $this->assertEquals(['reg-1', 'reg-2'], $this->service->getAvailableRegisters());
    }//end testGetAvailableRegistersReturnsStoredValues()

    public function testGetAvailableRegistersDefaultEmpty(): void
    {
        $this->assertEquals([], $this->service->getAvailableRegisters());
    }//end testGetAvailableRegistersDefaultEmpty()

    public function testGetAvailableSchemasReturnsStoredValues(): void
    {
        $reflection = new \ReflectionClass($this->service);
        $prop       = $reflection->getProperty('availableSchemas');
        $prop->setAccessible(true);
        $prop->setValue($this->service, ['sch-a', 'sch-b']);

        $this->assertEquals(['sch-a', 'sch-b'], $this->service->getAvailableSchemas());
    }//end testGetAvailableSchemasReturnsStoredValues()

    public function testGetAvailableSchemasDefaultEmpty(): void
    {
        $this->assertEquals([], $this->service->getAvailableSchemas());
    }//end testGetAvailableSchemasDefaultEmpty()

    // ──────────────────────────────────────────────────────────
    // getCatalogBySlug
    // ──────────────────────────────────────────────────────────
    public function testGetCatalogBySlugCacheHit(): void
    {
        $cachedData = ['id' => '1', 'slug' => 'test-catalog', 'title' => 'Test'];

        $this->cache->method('get')
            ->with('catalog_slug_test-catalog')
            ->willReturn($cachedData);

        $result = $this->service->getCatalogBySlug('test-catalog');

        $this->assertEquals($cachedData, $result);
    }//end testGetCatalogBySlugCacheHit()

    public function testGetCatalogBySlugCacheMissWithDbResult(): void
    {
        $catalogData = ['id' => '2', 'slug' => 'my-catalog', 'title' => 'My Catalog'];

        $this->cache->method('get')
            ->with('catalog_slug_my-catalog')
            ->willReturn(null);

        $this->config->method('getValueString')
            ->willReturnMap(
                    [
                        ['opencatalogi', 'catalog_schema', '', 'schema-1'],
                        ['opencatalogi', 'catalog_register', '', 'register-1'],
                    ]
                    );

        $catalogObject = $this->createMockCatalogObject($catalogData);

        $objectService = $this->createMock(ObjectService::class);
        $objectService->method('searchObjects')
            ->willReturn([$catalogObject]);

        $this->cache->expects($this->once())
            ->method('set')
            ->with('catalog_slug_my-catalog', $catalogData, 3600);

        $this->injectObjectService($objectService);

        $result = $this->service->getCatalogBySlug('my-catalog');

        $this->assertEquals($catalogData, $result);
    }//end testGetCatalogBySlugCacheMissWithDbResult()

    public function testGetCatalogBySlugNotFound(): void
    {
        $this->cache->method('get')
            ->willReturn(null);

        $this->config->method('getValueString')
            ->willReturnMap(
                    [
                        ['opencatalogi', 'catalog_schema', '', 'schema-1'],
                        ['opencatalogi', 'catalog_register', '', 'register-1'],
                    ]
                    );

        $objectService = $this->createMock(ObjectService::class);
        $objectService->method('searchObjects')
            ->willReturn([]);

        $this->injectObjectService($objectService);

        $this->logger->expects($this->atLeastOnce())
            ->method('error');

        $result = $this->service->getCatalogBySlug('nonexistent');

        $this->assertNull($result);
    }//end testGetCatalogBySlugNotFound()

    public function testGetCatalogBySlugEmptyConfig(): void
    {
        $this->cache->method('get')
            ->willReturn(null);

        $this->config->method('getValueString')
            ->willReturnMap(
                    [
                        ['opencatalogi', 'catalog_schema', '', ''],
                        ['opencatalogi', 'catalog_register', '', ''],
                    ]
                    );

        $this->logger->expects($this->atLeastOnce())
            ->method('error');

        $result = $this->service->getCatalogBySlug('some-slug');

        $this->assertNull($result);
    }//end testGetCatalogBySlugEmptyConfig()

    public function testGetCatalogBySlugExceptionReturnsNull(): void
    {
        $this->cache->method('get')
            ->willReturn(null);

        $this->config->method('getValueString')
            ->willReturnMap(
                    [
                        ['opencatalogi', 'catalog_schema', '', 'schema-1'],
                        ['opencatalogi', 'catalog_register', '', 'register-1'],
                    ]
                    );

        $objectService = $this->createMock(ObjectService::class);
        $objectService->method('searchObjects')
            ->willThrowException(new \Exception('DB error'));

        $this->injectObjectService($objectService);

        $result = $this->service->getCatalogBySlug('broken');

        $this->assertNull($result);
    }//end testGetCatalogBySlugExceptionReturnsNull()

    // ──────────────────────────────────────────────────────────
    // invalidateCatalogCache
    // ──────────────────────────────────────────────────────────
    public function testInvalidateCatalogCache(): void
    {
        $this->cache->expects($this->once())
            ->method('remove')
            ->with('catalog_slug_test-slug');

        $this->service->invalidateCatalogCache('test-slug');
    }//end testInvalidateCatalogCache()

    // ──────────────────────────────────────────────────────────
    // invalidateCatalogCacheById
    // ──────────────────────────────────────────────────────────
    public function testInvalidateCatalogCacheByIdSuccess(): void
    {
        $this->config->method('getValueString')
            ->willReturnMap(
                    [
                        ['opencatalogi', 'catalog_schema', '', 'schema-1'],
                        ['opencatalogi', 'catalog_register', '', 'register-1'],
                    ]
                    );

        $catalogEntity = new \OCA\OpenRegister\Db\ObjectEntity();
        $catalogEntity->setObject(['slug' => 'found-slug']);

        $objectService = $this->createMock(ObjectService::class);
        $objectService->method('find')
            ->willReturn($catalogEntity);

        $this->injectObjectService($objectService);

        $this->cache->expects($this->once())
            ->method('remove')
            ->with('catalog_slug_found-slug');

        $this->service->invalidateCatalogCacheById(42);
    }//end testInvalidateCatalogCacheByIdSuccess()

    public function testInvalidateCatalogCacheByIdExceptionLogsError(): void
    {
        $this->config->method('getValueString')
            ->willReturnMap(
                    [
                        ['opencatalogi', 'catalog_schema', '', 'schema-1'],
                        ['opencatalogi', 'catalog_register', '', 'register-1'],
                    ]
                    );

        $objectService = $this->createMock(ObjectService::class);
        $objectService->method('find')
            ->willThrowException(new \Exception('Not found'));

        $this->injectObjectService($objectService);

        $this->logger->expects($this->once())
            ->method('error');

        $this->service->invalidateCatalogCacheById(999);
    }//end testInvalidateCatalogCacheByIdExceptionLogsError()

    public function testInvalidateCatalogCacheByIdNoSlug(): void
    {
        $this->config->method('getValueString')
            ->willReturnMap(
                    [
                        ['opencatalogi', 'catalog_schema', '', 'schema-1'],
                        ['opencatalogi', 'catalog_register', '', 'register-1'],
                    ]
                    );

        $catalogEntity = new \OCA\OpenRegister\Db\ObjectEntity();
        $catalogEntity->setObject(['title' => 'No Slug']);

        $objectService = $this->createMock(ObjectService::class);
        $objectService->method('find')
            ->willReturn($catalogEntity);

        $this->injectObjectService($objectService);

        // Cache remove should not be called since there is no slug.
        $this->cache->expects($this->never())
            ->method('remove');

        $this->service->invalidateCatalogCacheById(42);
    }//end testInvalidateCatalogCacheByIdNoSlug()

    // ──────────────────────────────────────────────────────────
    // warmupCatalogCache
    // ──────────────────────────────────────────────────────────
    public function testWarmupCatalogCache(): void
    {
        // warmupCatalogCache invalidates, then calls getCatalogBySlug.
        $this->cache->expects($this->once())
            ->method('remove')
            ->with('catalog_slug_warm-slug');

        // getCatalogBySlug will try cache first (returns null after invalidation),
        // then query the DB.
        $this->cache->method('get')
            ->willReturn(null);

        $this->config->method('getValueString')
            ->willReturnMap(
                    [
                        ['opencatalogi', 'catalog_schema', '', 'schema-1'],
                        ['opencatalogi', 'catalog_register', '', 'register-1'],
                    ]
                    );

        $catalogData   = ['slug' => 'warm-slug', 'title' => 'Warmed'];
        $catalogObject = $this->createMockCatalogObject($catalogData);

        $objectService = $this->createMock(ObjectService::class);
        $objectService->method('searchObjects')
            ->willReturn([$catalogObject]);

        $this->injectObjectService($objectService);

        $this->service->warmupCatalogCache('warm-slug');
    }//end testWarmupCatalogCache()

    // ──────────────────────────────────────────────────────────
    // warmupCatalogCacheById
    // ──────────────────────────────────────────────────────────
    public function testWarmupCatalogCacheByIdSuccess(): void
    {
        $this->config->method('getValueString')
            ->willReturnMap(
                    [
                        ['opencatalogi', 'catalog_schema', '', 'schema-1'],
                        ['opencatalogi', 'catalog_register', '', 'register-1'],
                    ]
                    );

        $catalogData   = ['slug' => 'warmup-by-id'];
        $catalogEntity = new \OCA\OpenRegister\Db\ObjectEntity();
        $catalogEntity->setObject($catalogData);

        $catalogSearchResult = $this->createMockCatalogObject($catalogData);

        $objectService = $this->createMock(ObjectService::class);
        $objectService->method('find')
            ->willReturn($catalogEntity);
        $objectService->method('searchObjects')
            ->willReturn([$catalogSearchResult]);

        $this->injectObjectService($objectService);

        // Should call invalidateCatalogCache (remove) then getCatalogBySlug.
        $this->cache->expects($this->once())
            ->method('remove')
            ->with('catalog_slug_warmup-by-id');

        $this->cache->method('get')
            ->willReturn(null);

        $this->service->warmupCatalogCacheById(10);
    }//end testWarmupCatalogCacheByIdSuccess()

    public function testWarmupCatalogCacheByIdExceptionLogsError(): void
    {
        $this->config->method('getValueString')
            ->willReturnMap(
                    [
                        ['opencatalogi', 'catalog_schema', '', 'schema-1'],
                        ['opencatalogi', 'catalog_register', '', 'register-1'],
                    ]
                    );

        $objectService = $this->createMock(ObjectService::class);
        $objectService->method('find')
            ->willThrowException(new \Exception('Connection lost'));

        $this->injectObjectService($objectService);

        $this->logger->expects($this->once())
            ->method('error');

        $this->service->warmupCatalogCacheById(999);
    }//end testWarmupCatalogCacheByIdExceptionLogsError()

    // ──────────────────────────────────────────────────────────
    // getConfig (private, via reflection)
    // ──────────────────────────────────────────────────────────
    public function testGetConfigDefaults(): void
    {
        $this->request->method('getParams')
            ->willReturn([]);

        $method = $this->getPrivateMethod('getConfig');
        $result = $method->invoke($this->service);

        $this->assertEquals(20, $result['limit']);
        $this->assertNull($result['offset']);
        $this->assertNull($result['page']);
        $this->assertNull($result['search']);
        $this->assertNull($result['extend']);
        $this->assertNull($result['fields']);
        $this->assertNull($result['unset']);
        $this->assertEquals([], $result['queries']);
        $this->assertNull($result['ids']);
    }//end testGetConfigDefaults()

    public function testGetConfigWithLimitAndOffset(): void
    {
        $this->request->method('getParams')
            ->willReturn(
                    [
                        'limit'  => '50',
                        'offset' => '100',
                    ]
                    );

        $method = $this->getPrivateMethod('getConfig');
        $result = $method->invoke($this->service);

        $this->assertEquals(50, $result['limit']);
        $this->assertEquals(100, $result['offset']);
    }//end testGetConfigWithLimitAndOffset()

    public function testGetConfigWithUnderscorePrefixedParams(): void
    {
        $this->request->method('getParams')
            ->willReturn(
                    [
                        '_limit'   => '30',
                        '_offset'  => '60',
                        '_page'    => '3',
                        '_search'  => 'test query',
                        '_extend'  => ['relation1'],
                        '_fields'  => ['title', 'description'],
                        '_unset'   => ['internal'],
                        '_queries' => 'some-query',
                    ]
                    );

        $method = $this->getPrivateMethod('getConfig');
        $result = $method->invoke($this->service);

        $this->assertEquals(30, $result['limit']);
        $this->assertEquals(60, $result['offset']);
        $this->assertEquals(3, $result['page']);
        $this->assertEquals('test query', $result['search']);
        $this->assertEquals(['relation1'], $result['extend']);
        $this->assertEquals(['title', 'description'], $result['fields']);
        $this->assertEquals(['internal'], $result['unset']);
        $this->assertEquals(['some-query'], $result['queries']);
    }//end testGetConfigWithUnderscorePrefixedParams()

    public function testGetConfigPageCalculatesOffset(): void
    {
        $this->request->method('getParams')
            ->willReturn(
                    [
                        'page'  => '3',
                        'limit' => '10',
                    ]
                    );

        $method = $this->getPrivateMethod('getConfig');
        $result = $method->invoke($this->service);

        $this->assertEquals(3, $result['page']);
        // offset = (3 - 1) * 10 = 20
        $this->assertEquals(20, $result['offset']);
    }//end testGetConfigPageCalculatesOffset()

    public function testGetConfigPageDoesNotOverrideExplicitOffset(): void
    {
        $this->request->method('getParams')
            ->willReturn(
                    [
                        'page'   => '5',
                        'offset' => '10',
                        'limit'  => '20',
                    ]
                    );

        $method = $this->getPrivateMethod('getConfig');
        $result = $method->invoke($this->service);

        // Explicit offset takes precedence.
        $this->assertEquals(10, $result['offset']);
    }//end testGetConfigPageDoesNotOverrideExplicitOffset()

    public function testGetConfigStripsIdAndRoute(): void
    {
        $this->request->method('getParams')
            ->willReturn(
                    [
                        'id'     => '123',
                        '_route' => 'some.route',
                        'title'  => 'Test',
                    ]
                    );

        $method = $this->getPrivateMethod('getConfig');
        $result = $method->invoke($this->service);

        $this->assertArrayNotHasKey('id', $result['filters']);
        $this->assertArrayNotHasKey('_route', $result['filters']);
        $this->assertEquals('Test', $result['filters']['title']);
    }//end testGetConfigStripsIdAndRoute()

    public function testGetConfigQueriesArrayPassthrough(): void
    {
        $this->request->method('getParams')
            ->willReturn(
                    [
                        'queries' => ['q1', 'q2'],
                    ]
                    );

        $method = $this->getPrivateMethod('getConfig');
        $result = $method->invoke($this->service);

        $this->assertEquals(['q1', 'q2'], $result['queries']);
    }//end testGetConfigQueriesArrayPassthrough()

    // ──────────────────────────────────────────────────────────
    // index
    // ──────────────────────────────────────────────────────────
    public function testIndexWithFilters(): void
    {
        $this->request->method('getParams')
            ->willReturn(['limit' => '10']);

        $this->config->method('getValueString')
            ->willReturnMap(
                    [
                        ['opencatalogi', 'catalog_schema', '', 'schema-1'],
                        ['opencatalogi', 'catalog_register', '', 'register-1'],
                    ]
                    );

        $catalogObject = $this->createMockCatalogObject(
                [
                    'registers' => ['reg-1'],
                    'schemas'   => ['sch-1'],
                ]
                );

        $resultObject = $this->createMockResultObject(
                [
                    'id'    => 'obj-1',
                    'title' => 'Test',
                    '@self' => [
                        'register'      => 'reg-1',
                        'schema'        => 'sch-1',
                        'owner'         => 'admin',
                        'deleted'       => false,
                        'schemaVersion' => '1.0',
                    ],
                ]
                );

        $objectService = $this->createMock(ObjectService::class);
        $objectService->method('searchObjects')
            ->willReturn([$catalogObject]);
        $objectService->method('searchObjectsPaginated')
            ->willReturn(
                    [
                        'results' => [$resultObject],
                        'total'   => 1,
                        'page'    => 1,
                        'pages'   => 1,
                    ]
                    );

        $this->injectObjectService($objectService);

        $response = $this->service->index();

        $this->assertInstanceOf(JSONResponse::class, $response);

        $data = $response->getData();
        $this->assertCount(1, $data['results']);
        // Verify unwanted properties stripped from @self.
        $firstResult = $data['results'][0];
        $this->assertArrayNotHasKey('owner', $firstResult['@self']);
        $this->assertArrayNotHasKey('deleted', $firstResult['@self']);
        $this->assertArrayNotHasKey('schemaVersion', $firstResult['@self']);
        // Verify kept properties.
        $this->assertArrayHasKey('register', $firstResult['@self']);
        $this->assertArrayHasKey('schema', $firstResult['@self']);
    }//end testIndexWithFilters()

    public function testIndexWithMultipleRegistersAndSchemas(): void
    {
        $this->request->method('getParams')
            ->willReturn([]);

        $this->config->method('getValueString')
            ->willReturnMap(
                    [
                        ['opencatalogi', 'catalog_schema', '', 'schema-1'],
                        ['opencatalogi', 'catalog_register', '', 'register-1'],
                    ]
                    );

        $catalogObject = $this->createMockCatalogObject(
                [
                    'registers' => ['reg-1', 'reg-2'],
                    'schemas'   => ['sch-1', 'sch-2'],
                ]
                );

        $objectService = $this->createMock(ObjectService::class);
        $objectService->method('searchObjects')
            ->willReturn([$catalogObject]);
        $objectService->method('searchObjectsPaginated')
            ->willReturn(
                    [
                        'results' => [],
                        'total'   => 0,
                        'page'    => 1,
                        'pages'   => 0,
                    ]
                    );

        $this->injectObjectService($objectService);

        $response = $this->service->index();

        $this->assertInstanceOf(JSONResponse::class, $response);
        $data = $response->getData();
        $this->assertCount(0, $data['results']);
    }//end testIndexWithMultipleRegistersAndSchemas()

    public function testIndexWithCatalogId(): void
    {
        $this->request->method('getParams')
            ->willReturn([]);

        $this->config->method('getValueString')
            ->willReturnMap(
                    [
                        ['opencatalogi', 'catalog_schema', '', 'schema-1'],
                        ['opencatalogi', 'catalog_register', '', 'register-1'],
                    ]
                    );

        $catalogObject = $this->createMockCatalogObject(
                [
                    'registers' => ['reg-1'],
                    'schemas'   => ['sch-1'],
                ]
                );

        $resultObject = $this->createMockResultObject(
                [
                    'id'    => 'obj-1',
                    'title' => 'Filtered',
                    '@self' => ['register' => 'reg-1', 'schema' => 'sch-1'],
                ]
                );

        $objectService = $this->createMock(ObjectService::class);
        $objectService->method('searchObjects')
            ->willReturn([$catalogObject]);
        $objectService->method('searchObjectsPaginated')
            ->willReturn(
                    [
                        'results' => [$resultObject],
                        'total'   => 1,
                    ]
                    );

        $this->injectObjectService($objectService);

        $response = $this->service->index('catalog-uuid');

        $this->assertInstanceOf(JSONResponse::class, $response);
    }//end testIndexWithCatalogId()

    public function testIndexPagination(): void
    {
        $this->request->method('getParams')
            ->willReturn(
                    [
                        'limit' => '5',
                        'page'  => '2',
                    ]
                    );

        $this->config->method('getValueString')
            ->willReturnMap(
                    [
                        ['opencatalogi', 'catalog_schema', '', 'schema-1'],
                        ['opencatalogi', 'catalog_register', '', 'register-1'],
                    ]
                    );

        $catalogObject = $this->createMockCatalogObject(
                [
                    'registers' => ['reg-1'],
                    'schemas'   => [],
                ]
                );

        $objectService = $this->createMock(ObjectService::class);
        $objectService->method('searchObjects')
            ->willReturn([$catalogObject]);
        $objectService->method('searchObjectsPaginated')
            ->willReturn(
                    [
                        'results' => [],
                        'total'   => 0,
                        'page'    => 2,
                        'pages'   => 2,
                    ]
                    );

        $this->injectObjectService($objectService);

        $response = $this->service->index();

        $this->assertInstanceOf(JSONResponse::class, $response);
    }//end testIndexPagination()

    // ──────────────────────────────────────────────────────────
    // authenticated-read-parity (CAT-AUTH-001)
    // ──────────────────────────────────────────────────────────

    /**
     * Golden fixture: the full set of properties `index()` may see on an object's
     * `@self` envelope, covering every stripped property plus a few kept ones.
     * Frozen here so the anonymous-envelope assertion below is a byte-parity guard,
     * not just a "some keys are missing" check (design.md D3).
     *
     * @return array<string, mixed>
     */
    private function goldenSelfFixture(): array
    {
        return [
            'id'            => 'golden-1',
            'register'      => 'reg-1',
            'schema'        => 'sch-1',
            'schemaVersion' => '1.2.3',
            'relations'     => ['related-1'],
            'locked'        => true,
            'owner'         => 'admin',
            'folder'        => '/files/golden',
            'application'   => 'opencatalogi',
            'validation'    => ['valid' => true],
            'retention'     => ['period' => 'P1Y'],
            'size'          => 1024,
            'deleted'       => false,
        ];
    }//end goldenSelfFixture()

    /**
     * Scenario: anonymous envelope is unchanged (CAT-AUTH-001).
     *
     * @spec openspec/changes/authenticated-read-parity/specs/catalogs/spec.md
     */
    public function testIndexAnonymousEnvelopeIsByteIdenticalToGoldenFixture(): void
    {
        $this->request->method('getParams')->willReturn([]);
        $this->config->method('getValueString')
            ->willReturnMap(
                    [
                        ['opencatalogi', 'catalog_schema', '', 'schema-1'],
                        ['opencatalogi', 'catalog_register', '', 'register-1'],
                    ]
                    );

        $catalogObject = $this->createMockCatalogObject(
                [
                    'registers' => ['reg-1'],
                    'schemas'   => ['sch-1'],
                ]
                );

        $resultObject = $this->createMockResultObject(['@self' => $this->goldenSelfFixture()]);

        $objectService = $this->createMock(ObjectService::class);
        $objectService->method('searchObjects')->willReturn([$catalogObject]);
        $objectService->method('searchObjectsPaginated')
            ->willReturn(
                    [
                        'results' => [$resultObject],
                        'total'   => 1,
                    ]
                    );

        $this->injectObjectService($objectService);

        // Explicit anonymous session (no user on it).
        $this->userSession = $this->createMock(IUserSession::class);
        $this->userSession->method('getUser')->willReturn(null);
        $this->service = new CatalogiService(
            $this->config,
            $this->request,
            $this->container,
            $this->appManager,
            $this->cacheFactory,
            $this->logger,
            $this->userSession,
        );

        $response = $this->service->index();
        $data     = $response->getData();

        // Byte-identical golden envelope: exactly the pre-change stripped shape,
        // same keys, same order, same values.
        $this->assertSame(
                [
                    'id'       => 'golden-1',
                    'register' => 'reg-1',
                    'schema'   => 'sch-1',
                ],
                $data['results'][0]['@self']
                );
    }//end testIndexAnonymousEnvelopeIsByteIdenticalToGoldenFixture()

    /**
     * Scenario: authenticated caller sees full metadata (CAT-AUTH-001).
     *
     * @spec openspec/changes/authenticated-read-parity/specs/catalogs/spec.md
     */
    public function testIndexAuthenticatedEnvelopeCarriesFullMetadata(): void
    {
        $this->request->method('getParams')->willReturn([]);
        $this->config->method('getValueString')
            ->willReturnMap(
                    [
                        ['opencatalogi', 'catalog_schema', '', 'schema-1'],
                        ['opencatalogi', 'catalog_register', '', 'register-1'],
                    ]
                    );

        $catalogObject = $this->createMockCatalogObject(
                [
                    'registers' => ['reg-1'],
                    'schemas'   => ['sch-1'],
                ]
                );

        $goldenSelf   = $this->goldenSelfFixture();
        $resultObject = $this->createMockResultObject(['@self' => $goldenSelf]);

        $objectService = $this->createMock(ObjectService::class);
        $objectService->method('searchObjects')->willReturn([$catalogObject]);
        $objectService->method('searchObjectsPaginated')
            ->willReturn(
                    [
                        'results' => [$resultObject],
                        'total'   => 1,
                    ]
                    );

        $this->injectObjectService($objectService);

        // Authenticated session: OR RBAC already decided this caller may read the object.
        $user = $this->createMock(IUser::class);
        $this->userSession = $this->createMock(IUserSession::class);
        $this->userSession->method('getUser')->willReturn($user);
        $this->service = new CatalogiService(
            $this->config,
            $this->request,
            $this->container,
            $this->appManager,
            $this->cacheFactory,
            $this->logger,
            $this->userSession,
        );

        $response = $this->service->index();
        $data     = $response->getData();

        // Every previously stripped property is present, unmodified.
        $this->assertSame($goldenSelf, $data['results'][0]['@self']);
        $this->assertArrayHasKey('owner', $data['results'][0]['@self']);
        $this->assertArrayHasKey('locked', $data['results'][0]['@self']);
        $this->assertArrayHasKey('retention', $data['results'][0]['@self']);
        $this->assertSame('admin', $data['results'][0]['@self']['owner']);
    }//end testIndexAuthenticatedEnvelopeCarriesFullMetadata()

    /**
     * Scenario: session changes metadata richness, never the object set (CAT-AUTH-001).
     *
     * @spec openspec/changes/authenticated-read-parity/specs/catalogs/spec.md
     */
    public function testIndexObjectSetParityBetweenAnonymousAndAuthenticated(): void
    {
        $this->request->method('getParams')->willReturn([]);
        $this->config->method('getValueString')
            ->willReturnMap(
                    [
                        ['opencatalogi', 'catalog_schema', '', 'schema-1'],
                        ['opencatalogi', 'catalog_register', '', 'register-1'],
                    ]
                    );

        $catalogObject = $this->createMockCatalogObject(
                [
                    'registers' => ['reg-1'],
                    'schemas'   => ['sch-1'],
                ]
                );

        // Identical RBAC-governed result set for both audiences — the object set
        // (ids + order) MUST be identical regardless of session.
        $rbacResults = [
            $this->createMockResultObject(['@self' => ['id' => 'obj-a'] + $this->goldenSelfFixture()]),
            $this->createMockResultObject(['@self' => ['id' => 'obj-b'] + $this->goldenSelfFixture()]),
        ];

        $objectService = $this->createMock(ObjectService::class);
        $objectService->method('searchObjects')->willReturn([$catalogObject]);
        $objectService->method('searchObjectsPaginated')
            ->willReturn(
                    [
                        'results' => $rbacResults,
                        'total'   => 2,
                    ]
                    );

        $this->injectObjectService($objectService);

        // Anonymous run.
        $this->userSession = $this->createMock(IUserSession::class);
        $this->userSession->method('getUser')->willReturn(null);
        $this->service = new CatalogiService(
            $this->config,
            $this->request,
            $this->container,
            $this->appManager,
            $this->cacheFactory,
            $this->logger,
            $this->userSession,
        );
        $anonymousData = $this->service->index()->getData();

        // Authenticated run — same mocked RBAC context (same $objectService, same results).
        $user = $this->createMock(IUser::class);
        $this->userSession = $this->createMock(IUserSession::class);
        $this->userSession->method('getUser')->willReturn($user);
        $this->service = new CatalogiService(
            $this->config,
            $this->request,
            $this->container,
            $this->appManager,
            $this->cacheFactory,
            $this->logger,
            $this->userSession,
        );
        $authenticatedData = $this->service->index()->getData();

        $anonymousIds      = array_column(array_column($anonymousData['results'], '@self'), 'id');
        $authenticatedIds  = array_column(array_column($authenticatedData['results'], '@self'), 'id');

        // Same ids, same order, both audiences.
        $this->assertSame(['obj-a', 'obj-b'], $anonymousIds);
        $this->assertSame(['obj-a', 'obj-b'], $authenticatedIds);
        $this->assertSame($anonymousIds, $authenticatedIds);

        // Only the metadata richness differs.
        $this->assertArrayNotHasKey('owner', $anonymousData['results'][0]['@self']);
        $this->assertArrayHasKey('owner', $authenticatedData['results'][0]['@self']);
    }//end testIndexObjectSetParityBetweenAnonymousAndAuthenticated()

    // ──────────────────────────────────────────────────────────
    // computeRewrittenRegistersAndSchemas
    //
    // Regression guards for the catalog-update infinite-loop fix: this method
    // MUST be a pure function returning only the changed keys, so the
    // pre-save listener can hand the result to `setModifiedData(...)` without
    // triggering a second save.
    // ──────────────────────────────────────────────────────────
    public function testComputeRewrittenRegistersAndSchemasResolvesSlugsToIds(): void
    {
        $register       = $this->createEntityMock(Register::class, 42);
        $registerMapper = $this->createMock(RegisterMapper::class);
        $registerMapper->expects($this->once())
            ->method('find')
            ->with('my-register')
            ->willReturn($register);

        $schema       = $this->createEntityMock(Schema::class, 7);
        $schemaMapper = $this->createMock(SchemaMapper::class);
        $schemaMapper->expects($this->once())
            ->method('find')
            ->with('my-schema')
            ->willReturn($schema);

        $this->injectMappers($registerMapper, $schemaMapper);

        $result = $this->service->computeRewrittenRegistersAndSchemas(
                [
                    'registers' => ['my-register'],
                    'schemas'   => ['my-schema'],
                ]
                );

        $this->assertSame(['registers' => [42], 'schemas' => [7]], $result);
    }//end testComputeRewrittenRegistersAndSchemasResolvesSlugsToIds()

    public function testComputeRewrittenRegistersAndSchemasIsIdempotentOnIntegerIds(): void
    {
        // No mapper lookups should happen when everything is already an integer id.
        $registerMapper = $this->createMock(RegisterMapper::class);
        $registerMapper->expects($this->never())->method('find');
        $schemaMapper = $this->createMock(SchemaMapper::class);
        $schemaMapper->expects($this->never())->method('find');

        $this->injectMappers($registerMapper, $schemaMapper);

        $result = $this->service->computeRewrittenRegistersAndSchemas(
                [
                    'registers' => ['1', '2'],
                    'schemas'   => ['3'],
                ]
                );

        // No changes -> empty diff. This is what stops the listener loop.
        $this->assertSame([], $result);
    }//end testComputeRewrittenRegistersAndSchemasIsIdempotentOnIntegerIds()

    public function testComputeRewrittenRegistersAndSchemasReturnsOnlyChangedKey(): void
    {
        $register       = $this->createEntityMock(Register::class, 99);
        $registerMapper = $this->createMock(RegisterMapper::class);
        $registerMapper->expects($this->once())
            ->method('find')
            ->with('publication')
            ->willReturn($register);

        $schemaMapper = $this->createMock(SchemaMapper::class);
        $schemaMapper->expects($this->never())->method('find');

        $this->injectMappers($registerMapper, $schemaMapper);

        $result = $this->service->computeRewrittenRegistersAndSchemas(
                [
                    'registers' => ['publication'],
                    'schemas'   => ['12'],
                ]
                );

        // Only the registers key was rewritten; schemas was already numeric.
        $this->assertSame(['registers' => [99]], $result);
    }//end testComputeRewrittenRegistersAndSchemasReturnsOnlyChangedKey()

    public function testComputeRewrittenRegistersAndSchemasThrowsOnUnresolvableRegister(): void
    {
        $registerMapper = $this->createMock(RegisterMapper::class);
        $registerMapper->method('find')
            ->willThrowException(new NotFoundException('nope'));
        $schemaMapper = $this->createMock(SchemaMapper::class);

        $this->injectMappers($registerMapper, $schemaMapper);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Register ghost not found.');

        $this->service->computeRewrittenRegistersAndSchemas(
                [
                    'registers' => ['ghost'],
                    'schemas'   => [],
                ]
                );
    }//end testComputeRewrittenRegistersAndSchemasThrowsOnUnresolvableRegister()

    public function testComputeRewrittenRegistersAndSchemasTolerateMissingKeys(): void
    {
        $registerMapper = $this->createMock(RegisterMapper::class);
        $registerMapper->expects($this->never())->method('find');
        $schemaMapper = $this->createMock(SchemaMapper::class);
        $schemaMapper->expects($this->never())->method('find');

        $this->injectMappers($registerMapper, $schemaMapper);

        $this->assertSame([], $this->service->computeRewrittenRegistersAndSchemas([]));
    }//end testComputeRewrittenRegistersAndSchemasTolerateMissingKeys()

    // ──────────────────────────────────────────────────────────
    // rewriteSchemasAndRegisters (deprecated wrapper)
    // ──────────────────────────────────────────────────────────
    public function testRewriteSchemasAndRegistersSkipsSaveWhenNothingChanged(): void
    {
        $registerMapper = $this->createMock(RegisterMapper::class);
        $registerMapper->expects($this->never())->method('find');
        $schemaMapper = $this->createMock(SchemaMapper::class);
        $schemaMapper->expects($this->never())->method('find');

        $objectService = $this->createMock(ObjectService::class);
        $objectService->expects($this->never())->method('saveObject');

        $this->injectAll($registerMapper, $schemaMapper, $objectService);

        $entity = $this->createMockObjectEntity(['registers' => ['1'], 'schemas' => ['2']]);

        $this->assertFalse($this->service->rewriteSchemasAndRegisters($entity));
    }//end testRewriteSchemasAndRegistersSkipsSaveWhenNothingChanged()

    // ──────────────────────────────────────────────────────────
    // Helper methods
    // ──────────────────────────────────────────────────────────

    /**
     * Inject RegisterMapper + SchemaMapper into the container so
     * computeRewrittenRegistersAndSchemas can resolve slugs.
     */
    private function injectMappers(RegisterMapper $registerMapper, SchemaMapper $schemaMapper): void
    {
        $this->appManager->method('getInstalledApps')
            ->willReturn(['openregister']);

        $this->container->method('get')
            ->willReturnCallback(
                    function (string $class) use ($registerMapper, $schemaMapper) {
                        return match ($class) {
                            'OCA\OpenRegister\Db\RegisterMapper' => $registerMapper,
                            'OCA\OpenRegister\Db\SchemaMapper'   => $schemaMapper,
                            default                              => null,
                        };
                    }
                    );
    }//end injectMappers()

    /**
     * Inject mappers + ObjectService into the container.
     */
    private function injectAll(
        RegisterMapper $registerMapper,
        SchemaMapper $schemaMapper,
        ObjectService $objectService
    ): void {
        $this->appManager->method('getInstalledApps')
            ->willReturn(['openregister']);

        $this->container->method('get')
            ->willReturnCallback(
                    function (string $class) use ($registerMapper, $schemaMapper, $objectService) {
                        return match ($class) {
                            'OCA\OpenRegister\Db\RegisterMapper'        => $registerMapper,
                            'OCA\OpenRegister\Db\SchemaMapper'          => $schemaMapper,
                            'OCA\OpenRegister\Service\ObjectService'    => $objectService,
                            default                                     => null,
                        };
                    }
                    );
    }//end injectAll()

    /**
     * Build a NextCloud Entity mock that responds to the magic `getId()` accessor.
     *
     * @template T of object
     * @param    class-string<T> $class The Entity subclass to mock.
     * @param    int             $id    The id to return from the magic `getId()`.
     *
     * @return T&MockObject
     */
    private function createEntityMock(string $class, int $id): object
    {
        $mock = $this->getMockBuilder($class)
            ->disableOriginalConstructor()
            ->addMethods(['getId'])
            ->getMock();
        $mock->method('getId')->willReturn($id);

        return $mock;
    }//end createEntityMock()

    /**
     * Build an ObjectEntity mock with `getObject` and the magic `setObject` accessor.
     */
    private function createMockObjectEntity(array $object): ObjectEntity
    {
        $entity = $this->getMockBuilder(ObjectEntity::class)
            ->disableOriginalConstructor()
            ->addMethods(['setObject'])
            ->onlyMethods(['getObject'])
            ->getMock();
        $entity->method('getObject')->willReturn($object);

        return $entity;
    }//end createMockObjectEntity()

    /**
     * Create a mock object that implements jsonSerialize() returning the given data.
     */
    private function createMockCatalogObject(array $data): object
    {
        $mock = new class ($data) {

            private array $data;

            public function __construct(array $data)
            {
                $this->data = $data;
            }//end __construct()

            public function jsonSerialize(): array
            {
                return $this->data;
            }//end jsonSerialize()
        };

        return $mock;
    }//end createMockCatalogObject()

    /**
     * Create a mock result object with jsonSerialize support.
     */
    private function createMockResultObject(array $data): object
    {
        return $this->createMockCatalogObject($data);
    }//end createMockResultObject()

    /**
     * Inject a mock object service into the service using reflection.
     */
    private function injectObjectService(object $objectService): void
    {
        $this->appManager->method('getInstalledApps')
            ->willReturn(['openregister']);

        // index() resolves PublicationQueryService from the container as a collaborator.
        // Object visibility is enforced by OpenRegister RBAC, not by this service, so no
        // published-predicate stub is needed — the mock is returned for its class id below.
        $queryService = $this->createMock(\OCA\OpenCatalogi\Service\PublicationQueryService::class);

        $this->container->method('get')
            ->willReturnCallback(
                function (string $id) use ($objectService, $queryService) {
                    if ($id === \OCA\OpenCatalogi\Service\PublicationQueryService::class) {
                        return $queryService;
                    }

                    return $objectService;
                }
            );
    }//end injectObjectService()

    /**
     * Get a private method via reflection.
     */
    private function getPrivateMethod(string $methodName): \ReflectionMethod
    {
        $reflection = new \ReflectionClass($this->service);
        $method     = $reflection->getMethod($methodName);
        $method->setAccessible(true);

        return $method;
    }//end getPrivateMethod()

    /**
     * Robustness (#736): under the SOLR backend, searchObjectsPaginated returns
     * array shapes (not ObjectEntity instances). CatalogiService::index MUST
     * accept both array AND entity shapes without fataling with
     * "Call to a member function jsonSerialize() on array".
     */
    public function testIndexAcceptsArrayShapedResultsFromSolrBackend(): void
    {
        $this->request->method('getParams')->willReturn([]);
        $this->config->method('getValueString')
            ->willReturnMap([
                ['opencatalogi', 'catalog_schema', '', 'schema-1'],
                ['opencatalogi', 'catalog_register', '', 'register-1'],
            ]);

        $catalogObject = $this->createMockCatalogObject([
            'registers' => ['reg-1'],
            'schemas'   => ['sch-1'],
        ]);

        // SOLR-shape result: plain associative array, NOT an ObjectEntity.
        $solrShapedResult = [
            '@self' => [
                'id'            => 'pub-solr-1',
                'register'      => 'reg-1',
                'schema'        => 'sch-1',
                'owner'         => 'admin',
                'schemaVersion' => '1.0',
            ],
        ];

        $objectService = $this->createMock(ObjectService::class);
        $objectService->method('searchObjects')->willReturn([$catalogObject]);
        $objectService->method('searchObjectsPaginated')
            ->willReturn([
                'results' => [$solrShapedResult],
                'total'   => 1,
                'page'    => 1,
                'pages'   => 1,
            ]);

        $this->injectObjectService($objectService);

        $response = $this->service->index();

        $this->assertInstanceOf(JSONResponse::class, $response);
        $data = $response->getData();
        $this->assertCount(1, $data['results']);
        $first = $data['results'][0];
        $this->assertArrayNotHasKey('owner', $first['@self']);
        $this->assertArrayNotHasKey('schemaVersion', $first['@self']);
        $this->assertSame('reg-1', $first['@self']['register']);
    }
}//end class
