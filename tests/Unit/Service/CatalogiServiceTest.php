<?php

declare(strict_types=1);

namespace Unit\Service;

use OCA\OpenCatalogi\Service\CatalogiService;
use OCA\OpenRegister\Service\ObjectService;
use OCA\OpenRegister\Service\FileService;
use OCP\IAppConfig;
use OCP\IRequest;
use OCP\App\IAppManager;
use OCP\AppFramework\Http\JSONResponse;
use OCP\ICache;
use OCP\ICacheFactory;
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

        $this->cacheFactory->method('createDistributed')
            ->with('opencatalogi_catalogs')
            ->willReturn($this->cache);

        $this->service = new CatalogiService(
            $this->config,
            $this->request,
            $this->container,
            $this->appManager,
            $this->cacheFactory,
            $this->logger,
        );
    }

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
    }

    public function testGetObjectServiceNotAvailable(): void
    {
        $this->appManager->method('getInstalledApps')
            ->willReturn(['opencatalogi']);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('OpenRegister service is not available.');

        $this->service->getObjectService();
    }

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
    }

    public function testGetFileServiceNotAvailable(): void
    {
        $this->appManager->method('getInstalledApps')
            ->willReturn([]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('OpenRegister service is not available.');

        $this->service->getFileService();
    }

    // ──────────────────────────────────────────────────────────
    // getCatalogFilters
    // ──────────────────────────────────────────────────────────

    public function testGetCatalogFiltersWithoutCatalogId(): void
    {
        $this->config->method('getValueString')
            ->willReturnMap([
                ['opencatalogi', 'catalog_schema', '', 'schema-1'],
                ['opencatalogi', 'catalog_register', '', 'register-1'],
            ]);

        $catalogObject = $this->createMockCatalogObject([
            'registers' => ['reg-a', 'reg-b'],
            'schemas'   => ['sch-x', 'sch-y'],
        ]);

        $objectService = $this->createMock(ObjectService::class);
        $objectService->method('searchObjects')
            ->willReturn([$catalogObject]);

        $this->injectObjectService($objectService);

        $result = $this->service->getCatalogFilters();

        $this->assertArrayHasKey('registers', $result);
        $this->assertArrayHasKey('schemas', $result);
        $this->assertEquals(['reg-a', 'reg-b'], $result['registers']);
        $this->assertEquals(['sch-x', 'sch-y'], $result['schemas']);
    }

    public function testGetCatalogFiltersWithCatalogId(): void
    {
        $this->config->method('getValueString')
            ->willReturnMap([
                ['opencatalogi', 'catalog_schema', '', 'schema-1'],
                ['opencatalogi', 'catalog_register', '', 'register-1'],
            ]);

        $catalogObject = $this->createMockCatalogObject([
            'registers' => ['reg-c'],
            'schemas'   => ['sch-z'],
        ]);

        $objectService = $this->createMock(ObjectService::class);
        $objectService->method('searchObjects')
            ->willReturn([$catalogObject]);

        $this->injectObjectService($objectService);

        $result = $this->service->getCatalogFilters('catalog-uuid-123');

        $this->assertEquals(['reg-c'], $result['registers']);
        $this->assertEquals(['sch-z'], $result['schemas']);
    }

    public function testGetCatalogFiltersWithMultipleCatalogsDeduplicated(): void
    {
        $this->config->method('getValueString')
            ->willReturnMap([
                ['opencatalogi', 'catalog_schema', '', 'schema-1'],
                ['opencatalogi', 'catalog_register', '', 'register-1'],
            ]);

        $cat1 = $this->createMockCatalogObject([
            'registers' => ['reg-a', 'reg-b'],
            'schemas'   => ['sch-x'],
        ]);
        $cat2 = $this->createMockCatalogObject([
            'registers' => ['reg-b', 'reg-c'],
            'schemas'   => ['sch-x', 'sch-y'],
        ]);

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
    }

    public function testGetCatalogFiltersEmptyResults(): void
    {
        $this->config->method('getValueString')
            ->willReturnMap([
                ['opencatalogi', 'catalog_schema', '', 'schema-1'],
                ['opencatalogi', 'catalog_register', '', 'register-1'],
            ]);

        $objectService = $this->createMock(ObjectService::class);
        $objectService->method('searchObjects')
            ->willReturn([]);

        $this->injectObjectService($objectService);

        $result = $this->service->getCatalogFilters();

        $this->assertEquals([], $result['registers']);
        $this->assertEquals([], $result['schemas']);
    }

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
    }

    public function testGetAvailableRegistersDefaultEmpty(): void
    {
        $this->assertEquals([], $this->service->getAvailableRegisters());
    }

    public function testGetAvailableSchemasReturnsStoredValues(): void
    {
        $reflection = new \ReflectionClass($this->service);
        $prop       = $reflection->getProperty('availableSchemas');
        $prop->setAccessible(true);
        $prop->setValue($this->service, ['sch-a', 'sch-b']);

        $this->assertEquals(['sch-a', 'sch-b'], $this->service->getAvailableSchemas());
    }

    public function testGetAvailableSchemasDefaultEmpty(): void
    {
        $this->assertEquals([], $this->service->getAvailableSchemas());
    }

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
    }

    public function testGetCatalogBySlugCacheMissWithDbResult(): void
    {
        $catalogData = ['id' => '2', 'slug' => 'my-catalog', 'title' => 'My Catalog'];

        $this->cache->method('get')
            ->with('catalog_slug_my-catalog')
            ->willReturn(null);

        $this->config->method('getValueString')
            ->willReturnMap([
                ['opencatalogi', 'catalog_schema', '', 'schema-1'],
                ['opencatalogi', 'catalog_register', '', 'register-1'],
            ]);

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
    }

    public function testGetCatalogBySlugNotFound(): void
    {
        $this->cache->method('get')
            ->willReturn(null);

        $this->config->method('getValueString')
            ->willReturnMap([
                ['opencatalogi', 'catalog_schema', '', 'schema-1'],
                ['opencatalogi', 'catalog_register', '', 'register-1'],
            ]);

        $objectService = $this->createMock(ObjectService::class);
        $objectService->method('searchObjects')
            ->willReturn([]);

        $this->injectObjectService($objectService);

        $this->logger->expects($this->atLeastOnce())
            ->method('error');

        $result = $this->service->getCatalogBySlug('nonexistent');

        $this->assertNull($result);
    }

    public function testGetCatalogBySlugEmptyConfig(): void
    {
        $this->cache->method('get')
            ->willReturn(null);

        $this->config->method('getValueString')
            ->willReturnMap([
                ['opencatalogi', 'catalog_schema', '', ''],
                ['opencatalogi', 'catalog_register', '', ''],
            ]);

        $this->logger->expects($this->atLeastOnce())
            ->method('error');

        $result = $this->service->getCatalogBySlug('some-slug');

        $this->assertNull($result);
    }

    public function testGetCatalogBySlugExceptionReturnsNull(): void
    {
        $this->cache->method('get')
            ->willReturn(null);

        $this->config->method('getValueString')
            ->willReturnMap([
                ['opencatalogi', 'catalog_schema', '', 'schema-1'],
                ['opencatalogi', 'catalog_register', '', 'register-1'],
            ]);

        $objectService = $this->createMock(ObjectService::class);
        $objectService->method('searchObjects')
            ->willThrowException(new \Exception('DB error'));

        $this->injectObjectService($objectService);

        $result = $this->service->getCatalogBySlug('broken');

        $this->assertNull($result);
    }

    // ──────────────────────────────────────────────────────────
    // invalidateCatalogCache
    // ──────────────────────────────────────────────────────────

    public function testInvalidateCatalogCache(): void
    {
        $this->cache->expects($this->once())
            ->method('remove')
            ->with('catalog_slug_test-slug');

        $this->service->invalidateCatalogCache('test-slug');
    }

    // ──────────────────────────────────────────────────────────
    // invalidateCatalogCacheById
    // ──────────────────────────────────────────────────────────

    public function testInvalidateCatalogCacheByIdSuccess(): void
    {
        $this->config->method('getValueString')
            ->willReturnMap([
                ['opencatalogi', 'catalog_schema', '', 'schema-1'],
                ['opencatalogi', 'catalog_register', '', 'register-1'],
            ]);

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
    }

    public function testInvalidateCatalogCacheByIdExceptionLogsError(): void
    {
        $this->config->method('getValueString')
            ->willReturnMap([
                ['opencatalogi', 'catalog_schema', '', 'schema-1'],
                ['opencatalogi', 'catalog_register', '', 'register-1'],
            ]);

        $objectService = $this->createMock(ObjectService::class);
        $objectService->method('find')
            ->willThrowException(new \Exception('Not found'));

        $this->injectObjectService($objectService);

        $this->logger->expects($this->once())
            ->method('error');

        $this->service->invalidateCatalogCacheById(999);
    }

    public function testInvalidateCatalogCacheByIdNoSlug(): void
    {
        $this->config->method('getValueString')
            ->willReturnMap([
                ['opencatalogi', 'catalog_schema', '', 'schema-1'],
                ['opencatalogi', 'catalog_register', '', 'register-1'],
            ]);

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
    }

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
            ->willReturnMap([
                ['opencatalogi', 'catalog_schema', '', 'schema-1'],
                ['opencatalogi', 'catalog_register', '', 'register-1'],
            ]);

        $catalogData   = ['slug' => 'warm-slug', 'title' => 'Warmed'];
        $catalogObject = $this->createMockCatalogObject($catalogData);

        $objectService = $this->createMock(ObjectService::class);
        $objectService->method('searchObjects')
            ->willReturn([$catalogObject]);

        $this->injectObjectService($objectService);

        $this->service->warmupCatalogCache('warm-slug');
    }

    // ──────────────────────────────────────────────────────────
    // warmupCatalogCacheById
    // ──────────────────────────────────────────────────────────

    public function testWarmupCatalogCacheByIdSuccess(): void
    {
        $this->config->method('getValueString')
            ->willReturnMap([
                ['opencatalogi', 'catalog_schema', '', 'schema-1'],
                ['opencatalogi', 'catalog_register', '', 'register-1'],
            ]);

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
    }

    public function testWarmupCatalogCacheByIdExceptionLogsError(): void
    {
        $this->config->method('getValueString')
            ->willReturnMap([
                ['opencatalogi', 'catalog_schema', '', 'schema-1'],
                ['opencatalogi', 'catalog_register', '', 'register-1'],
            ]);

        $objectService = $this->createMock(ObjectService::class);
        $objectService->method('find')
            ->willThrowException(new \Exception('Connection lost'));

        $this->injectObjectService($objectService);

        $this->logger->expects($this->once())
            ->method('error');

        $this->service->warmupCatalogCacheById(999);
    }

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
    }

    public function testGetConfigWithLimitAndOffset(): void
    {
        $this->request->method('getParams')
            ->willReturn([
                'limit'  => '50',
                'offset' => '100',
            ]);

        $method = $this->getPrivateMethod('getConfig');
        $result = $method->invoke($this->service);

        $this->assertEquals(50, $result['limit']);
        $this->assertEquals(100, $result['offset']);
    }

    public function testGetConfigWithUnderscorePrefixedParams(): void
    {
        $this->request->method('getParams')
            ->willReturn([
                '_limit'   => '30',
                '_offset'  => '60',
                '_page'    => '3',
                '_search'  => 'test query',
                '_extend'  => ['relation1'],
                '_fields'  => ['title', 'description'],
                '_unset'   => ['internal'],
                '_queries' => 'some-query',
            ]);

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
    }

    public function testGetConfigPageCalculatesOffset(): void
    {
        $this->request->method('getParams')
            ->willReturn([
                'page'  => '3',
                'limit' => '10',
            ]);

        $method = $this->getPrivateMethod('getConfig');
        $result = $method->invoke($this->service);

        $this->assertEquals(3, $result['page']);
        // offset = (3 - 1) * 10 = 20
        $this->assertEquals(20, $result['offset']);
    }

    public function testGetConfigPageDoesNotOverrideExplicitOffset(): void
    {
        $this->request->method('getParams')
            ->willReturn([
                'page'   => '5',
                'offset' => '10',
                'limit'  => '20',
            ]);

        $method = $this->getPrivateMethod('getConfig');
        $result = $method->invoke($this->service);

        // Explicit offset takes precedence.
        $this->assertEquals(10, $result['offset']);
    }

    public function testGetConfigStripsIdAndRoute(): void
    {
        $this->request->method('getParams')
            ->willReturn([
                'id'       => '123',
                '_route'   => 'some.route',
                'title'    => 'Test',
            ]);

        $method = $this->getPrivateMethod('getConfig');
        $result = $method->invoke($this->service);

        $this->assertArrayNotHasKey('id', $result['filters']);
        $this->assertArrayNotHasKey('_route', $result['filters']);
        $this->assertEquals('Test', $result['filters']['title']);
    }

    public function testGetConfigQueriesArrayPassthrough(): void
    {
        $this->request->method('getParams')
            ->willReturn([
                'queries' => ['q1', 'q2'],
            ]);

        $method = $this->getPrivateMethod('getConfig');
        $result = $method->invoke($this->service);

        $this->assertEquals(['q1', 'q2'], $result['queries']);
    }

    // ──────────────────────────────────────────────────────────
    // index
    // ──────────────────────────────────────────────────────────

    public function testIndexWithFilters(): void
    {
        $this->request->method('getParams')
            ->willReturn(['limit' => '10']);

        $this->config->method('getValueString')
            ->willReturnMap([
                ['opencatalogi', 'catalog_schema', '', 'schema-1'],
                ['opencatalogi', 'catalog_register', '', 'register-1'],
            ]);

        $catalogObject = $this->createMockCatalogObject([
            'registers' => ['reg-1'],
            'schemas'   => ['sch-1'],
        ]);

        $resultObject = $this->createMockResultObject([
            'id'    => 'obj-1',
            'title' => 'Test',
            '@self' => [
                'register'      => 'reg-1',
                'schema'        => 'sch-1',
                'owner'         => 'admin',
                'deleted'       => false,
                'schemaVersion' => '1.0',
            ],
        ]);

        $objectService = $this->createMock(ObjectService::class);
        $objectService->method('searchObjects')
            ->willReturn([$catalogObject]);
        $objectService->method('searchObjectsPaginated')
            ->willReturn([
                'results' => [$resultObject],
                'total'   => 1,
                'page'    => 1,
                'pages'   => 1,
            ]);

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
    }

    public function testIndexWithMultipleRegistersAndSchemas(): void
    {
        $this->request->method('getParams')
            ->willReturn([]);

        $this->config->method('getValueString')
            ->willReturnMap([
                ['opencatalogi', 'catalog_schema', '', 'schema-1'],
                ['opencatalogi', 'catalog_register', '', 'register-1'],
            ]);

        $catalogObject = $this->createMockCatalogObject([
            'registers' => ['reg-1', 'reg-2'],
            'schemas'   => ['sch-1', 'sch-2'],
        ]);

        $objectService = $this->createMock(ObjectService::class);
        $objectService->method('searchObjects')
            ->willReturn([$catalogObject]);
        $objectService->method('searchObjectsPaginated')
            ->willReturn([
                'results' => [],
                'total'   => 0,
                'page'    => 1,
                'pages'   => 0,
            ]);

        $this->injectObjectService($objectService);

        $response = $this->service->index();

        $this->assertInstanceOf(JSONResponse::class, $response);
        $data = $response->getData();
        $this->assertCount(0, $data['results']);
    }

    public function testIndexWithCatalogId(): void
    {
        $this->request->method('getParams')
            ->willReturn([]);

        $this->config->method('getValueString')
            ->willReturnMap([
                ['opencatalogi', 'catalog_schema', '', 'schema-1'],
                ['opencatalogi', 'catalog_register', '', 'register-1'],
            ]);

        $catalogObject = $this->createMockCatalogObject([
            'registers' => ['reg-1'],
            'schemas'   => ['sch-1'],
        ]);

        $resultObject = $this->createMockResultObject([
            'id'    => 'obj-1',
            'title' => 'Filtered',
            '@self' => ['register' => 'reg-1', 'schema' => 'sch-1'],
        ]);

        $objectService = $this->createMock(ObjectService::class);
        $objectService->method('searchObjects')
            ->willReturn([$catalogObject]);
        $objectService->method('searchObjectsPaginated')
            ->willReturn([
                'results' => [$resultObject],
                'total'   => 1,
            ]);

        $this->injectObjectService($objectService);

        $response = $this->service->index('catalog-uuid');

        $this->assertInstanceOf(JSONResponse::class, $response);
    }

    public function testIndexPagination(): void
    {
        $this->request->method('getParams')
            ->willReturn([
                'limit' => '5',
                'page'  => '2',
            ]);

        $this->config->method('getValueString')
            ->willReturnMap([
                ['opencatalogi', 'catalog_schema', '', 'schema-1'],
                ['opencatalogi', 'catalog_register', '', 'register-1'],
            ]);

        $catalogObject = $this->createMockCatalogObject([
            'registers' => ['reg-1'],
            'schemas'   => [],
        ]);

        $objectService = $this->createMock(ObjectService::class);
        $objectService->method('searchObjects')
            ->willReturn([$catalogObject]);
        $objectService->method('searchObjectsPaginated')
            ->willReturn([
                'results' => [],
                'total'   => 0,
                'page'    => 2,
                'pages'   => 2,
            ]);

        $this->injectObjectService($objectService);

        $response = $this->service->index();

        $this->assertInstanceOf(JSONResponse::class, $response);
    }

    // ──────────────────────────────────────────────────────────
    // Helper methods
    // ──────────────────────────────────────────────────────────

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
            }

            public function jsonSerialize(): array
            {
                return $this->data;
            }
        };

        return $mock;
    }

    /**
     * Create a mock result object with jsonSerialize support.
     */
    private function createMockResultObject(array $data): object
    {
        return $this->createMockCatalogObject($data);
    }

    /**
     * Inject a mock object service into the service using reflection.
     */
    private function injectObjectService(object $objectService): void
    {
        $this->appManager->method('getInstalledApps')
            ->willReturn(['openregister']);

        $this->container->method('get')
            ->willReturn($objectService);
    }

    /**
     * Get a private method via reflection.
     */
    private function getPrivateMethod(string $methodName): \ReflectionMethod
    {
        $reflection = new \ReflectionClass($this->service);
        $method     = $reflection->getMethod($methodName);
        $method->setAccessible(true);

        return $method;
    }
}
