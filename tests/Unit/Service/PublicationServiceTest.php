<?php

declare(strict_types=1);

namespace Unit\Service;

use OCA\OpenCatalogi\Service\DirectoryService;
use OCA\OpenCatalogi\Service\PublicationService;
use OCP\App\IAppManager;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Http\DataDownloadResponse;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IAppConfig;
use OCP\IRequest;
use OCP\IURLGenerator;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use RuntimeException;

class PublicationServiceTest extends TestCase
{
    private IAppConfig|MockObject $config;
    private IRequest|MockObject $request;
    private ContainerInterface|MockObject $container;
    private IAppManager|MockObject $appManager;
    private DirectoryService|MockObject $directoryService;
    private IURLGenerator|MockObject $urlGenerator;
    private PublicationService $service;

    protected function setUp(): void
    {
        $this->config           = $this->createMock(IAppConfig::class);
        $this->request          = $this->createMock(IRequest::class);
        $this->container        = $this->createMock(ContainerInterface::class);
        $this->appManager       = $this->createMock(IAppManager::class);
        $this->directoryService = $this->createMock(DirectoryService::class);
        $this->urlGenerator     = $this->createMock(IURLGenerator::class);

        $this->service = new PublicationService(
            $this->config,
            $this->request,
            $this->container,
            $this->appManager,
            $this->directoryService,
            $this->urlGenerator,
        );
    }

    // -----------------------------------------------------------------------
    // Helper: create a mock ObjectService
    // -----------------------------------------------------------------------

    private function createObjectServiceMock(): MockObject
    {
        return $this->createMock(\OCA\OpenRegister\Service\ObjectService::class);
    }

    /**
     * Set up the container to return the given mock when ObjectService is requested.
     */
    private function mockObjectServiceAvailable(MockObject $objectService): void
    {
        $this->appManager->method('getInstalledApps')
            ->willReturn(['openregister']);
        $this->container->method('get')
            ->with('OCA\OpenRegister\Service\ObjectService')
            ->willReturn($objectService);
    }

    /**
     * Set up the container to return the given mock when FileService is requested.
     */
    private function mockFileServiceAvailable(MockObject $fileService): void
    {
        $this->appManager->method('getInstalledApps')
            ->willReturn(['openregister']);
        $this->container->method('get')
            ->with('OCA\OpenRegister\Service\FileService')
            ->willReturn($fileService);
    }

    /**
     * Helper to make OpenRegister not installed.
     */
    private function mockOpenRegisterNotInstalled(): void
    {
        $this->appManager->method('getInstalledApps')
            ->willReturn(['files', 'activity']);
    }

    /**
     * Helper to create a mock file service.
     */
    private function createFileServiceMock(): MockObject
    {
        return $this->createMock(\OCA\OpenRegister\Service\FileService::class);
    }

    /**
     * Helper to create a serializable mock object for catalog/publication results.
     *
     * Uses a real ObjectEntity partial mock so that:
     * - jsonSerialize() returns the given data
     * - __call magic (getRelations, getUuid, etc.) works via setters
     * - instanceof Entity checks pass
     */
    private function createSerializableObject(array $data): \OCA\OpenRegister\Db\ObjectEntity
    {
        $obj = $this->getMockBuilder(\OCA\OpenRegister\Db\ObjectEntity::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['jsonSerialize'])
            ->getMock();
        $obj->method('jsonSerialize')->willReturn($data);
        if (isset($data['uuid'])) {
            $obj->setUuid($data['uuid']);
        }
        if (isset($data['relations'])) {
            $obj->setRelations($data['relations']);
        }
        return $obj;
    }

    // =======================================================================
    // getObjectService()
    // =======================================================================

    public function testGetObjectServiceWhenAvailable(): void
    {
        $objectService = $this->createMock(\OCA\OpenRegister\Service\ObjectService::class);
        $this->appManager->method('getInstalledApps')
            ->willReturn(['openregister']);
        $this->container->method('get')
            ->with('OCA\OpenRegister\Service\ObjectService')
            ->willReturn($objectService);

        $result = $this->service->getObjectService();
        $this->assertSame($objectService, $result);
    }

    public function testGetObjectServiceThrowsWhenNotInstalled(): void
    {
        $this->mockOpenRegisterNotInstalled();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('OpenRegister service is not available.');
        $this->service->getObjectService();
    }

    // =======================================================================
    // getFileService()
    // =======================================================================

    public function testGetFileServiceWhenAvailable(): void
    {
        $fileService = $this->createMock(\OCA\OpenRegister\Service\FileService::class);
        $this->appManager->method('getInstalledApps')
            ->willReturn(['openregister']);
        $this->container->method('get')
            ->with('OCA\OpenRegister\Service\FileService')
            ->willReturn($fileService);

        $result = $this->service->getFileService();
        $this->assertSame($fileService, $result);
    }

    public function testGetFileServiceThrowsWhenNotInstalled(): void
    {
        $this->mockOpenRegisterNotInstalled();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('OpenRegister service is not available.');
        $this->service->getFileService();
    }

    // =======================================================================
    // getAvailableRegisters() / getAvailableSchemas()
    // =======================================================================

    public function testGetAvailableRegistersDefaultEmpty(): void
    {
        $this->assertSame([], $this->service->getAvailableRegisters());
    }

    public function testGetAvailableSchemasDefaultEmpty(): void
    {
        $this->assertSame([], $this->service->getAvailableSchemas());
    }

    // =======================================================================
    // getCatalogFilters()
    // =======================================================================

    public function testGetCatalogFiltersExtractsRegistersAndSchemas(): void
    {
        $objectService = $this->createObjectServiceMock();
        $this->mockObjectServiceAvailable($objectService);

        $this->config->method('getValueString')
            ->willReturnMap([
                ['opencatalogi', 'catalog_schema', '', 'schema-1'],
                ['opencatalogi', 'catalog_register', '', 'register-1'],
            ]);

        $catalog1 = $this->createSerializableObject([
            'registers' => ['reg-a', 'reg-b'],
            'schemas'   => ['sch-x', 'sch-y'],
        ]);
        $catalog2 = $this->createSerializableObject([
            'registers' => ['reg-b', 'reg-c'],
            'schemas'   => ['sch-y', 'sch-z'],
        ]);

        $objectService->method('searchObjects')->willReturn([$catalog1, $catalog2]);

        $result = $this->service->getCatalogFilters();

        $this->assertArrayHasKey('registers', $result);
        $this->assertArrayHasKey('schemas', $result);
        // Should deduplicate
        $this->assertContains('reg-a', $result['registers']);
        $this->assertContains('reg-b', $result['registers']);
        $this->assertContains('reg-c', $result['registers']);
        $this->assertContains('sch-x', $result['schemas']);
        $this->assertContains('sch-y', $result['schemas']);
        $this->assertContains('sch-z', $result['schemas']);
    }

    public function testGetCatalogFiltersBySpecificCatalogId(): void
    {
        $objectService = $this->createObjectServiceMock();
        $this->mockObjectServiceAvailable($objectService);

        $this->config->method('getValueString')
            ->willReturnMap([
                ['opencatalogi', 'catalog_schema', '', 'schema-1'],
                ['opencatalogi', 'catalog_register', '', 'register-1'],
            ]);

        $catalog = $this->createSerializableObject([
            'registers' => ['reg-specific'],
            'schemas'   => ['sch-specific'],
        ]);

        // searchObjects returns all, but find returns the specific one
        $objectService->method('searchObjects')->willReturn([]);
        $objectService->method('find')->with('catalog-42')->willReturn($catalog);

        $result = $this->service->getCatalogFilters('catalog-42');

        $this->assertSame(['reg-specific'], $result['registers']);
        $this->assertSame(['sch-specific'], $result['schemas']);
    }

    public function testGetCatalogFiltersCachesResults(): void
    {
        $objectService = $this->createObjectServiceMock();
        $this->mockObjectServiceAvailable($objectService);

        $this->config->method('getValueString')
            ->willReturnMap([
                ['opencatalogi', 'catalog_schema', '', 'schema-1'],
                ['opencatalogi', 'catalog_register', '', 'register-1'],
            ]);

        $catalog = $this->createSerializableObject([
            'registers' => ['reg-cached'],
            'schemas'   => ['sch-cached'],
        ]);
        $objectService->expects($this->once())
            ->method('searchObjects')
            ->willReturn([$catalog]);

        // Call twice — searchObjects should only be called once
        $result1 = $this->service->getCatalogFilters();
        $result2 = $this->service->getCatalogFilters();

        $this->assertSame($result1, $result2);
    }

    public function testGetCatalogFiltersUpdatesAvailableRegistersAndSchemas(): void
    {
        $objectService = $this->createObjectServiceMock();
        $this->mockObjectServiceAvailable($objectService);

        $this->config->method('getValueString')
            ->willReturnMap([
                ['opencatalogi', 'catalog_schema', '', 'schema-1'],
                ['opencatalogi', 'catalog_register', '', 'register-1'],
            ]);

        $catalog = $this->createSerializableObject([
            'registers' => ['reg-1', 'reg-2'],
            'schemas'   => ['sch-1'],
        ]);
        $objectService->method('searchObjects')->willReturn([$catalog]);

        $this->service->getCatalogFilters();

        $this->assertSame(['reg-1', 'reg-2'], array_values($this->service->getAvailableRegisters()));
        $this->assertSame(['sch-1'], array_values($this->service->getAvailableSchemas()));
    }

    public function testGetCatalogFiltersHandlesEmptyCatalogs(): void
    {
        $objectService = $this->createObjectServiceMock();
        $this->mockObjectServiceAvailable($objectService);

        $this->config->method('getValueString')->willReturn('');
        $objectService->method('searchObjects')->willReturn([]);

        $result = $this->service->getCatalogFilters();

        $this->assertSame([], $result['registers']);
        $this->assertSame([], $result['schemas']);
    }

    public function testGetCatalogFiltersHandlesCatalogWithoutRegistersOrSchemas(): void
    {
        $objectService = $this->createObjectServiceMock();
        $this->mockObjectServiceAvailable($objectService);

        $this->config->method('getValueString')->willReturn('');

        $catalog = $this->createSerializableObject([
            'title' => 'Empty catalog',
        ]);
        $objectService->method('searchObjects')->willReturn([$catalog]);

        $result = $this->service->getCatalogFilters();

        $this->assertSame([], $result['registers']);
        $this->assertSame([], $result['schemas']);
    }

    // =======================================================================
    // index()
    // =======================================================================

    public function testIndexReturnsJsonResponse(): void
    {
        $objectService = $this->createObjectServiceMock();
        $this->mockObjectServiceAvailable($objectService);

        $this->config->method('getValueString')
            ->willReturnMap([
                ['opencatalogi', 'catalog_schema', '', 'schema-1'],
                ['opencatalogi', 'catalog_register', '', 'register-1'],
            ]);

        $catalog = $this->createSerializableObject([
            'registers' => ['reg-1'],
            'schemas'   => ['sch-1'],
        ]);
        $objectService->method('searchObjects')->willReturn([$catalog]);

        $resultObj = $this->createSerializableObject([
            '@self' => ['id' => 'pub-1', 'title' => 'Test'],
        ]);
        $objectService->method('searchObjectsPaginated')->willReturn([
            'results' => [$resultObj],
            'total'   => 1,
        ]);

        $this->request->method('getParams')->willReturn([]);

        $response = $this->service->index();
        $this->assertInstanceOf(JSONResponse::class, $response);

        $data = json_decode($response->render(), true);
        $this->assertArrayHasKey('results', $data);
        $this->assertCount(1, $data['results']);
    }

    public function testIndexWithCatalogId(): void
    {
        $objectService = $this->createObjectServiceMock();
        $this->mockObjectServiceAvailable($objectService);

        $this->config->method('getValueString')
            ->willReturnMap([
                ['opencatalogi', 'catalog_schema', '', 'schema-1'],
                ['opencatalogi', 'catalog_register', '', 'register-1'],
            ]);

        $catalog = $this->createSerializableObject([
            'registers' => ['reg-1'],
            'schemas'   => ['sch-1'],
        ]);
        $objectService->method('searchObjects')->willReturn([]);
        $objectService->method('find')
            ->willReturn($catalog);

        $objectService->method('searchObjectsPaginated')->willReturn([
            'results' => [],
            'total'   => 0,
        ]);

        $this->request->method('getParams')->willReturn([]);

        $response = $this->service->index('catalog-99');
        $this->assertInstanceOf(JSONResponse::class, $response);
    }

    public function testIndexWithCustomParams(): void
    {
        $objectService = $this->createObjectServiceMock();
        $this->mockObjectServiceAvailable($objectService);

        $this->config->method('getValueString')->willReturn('');

        $catalog = $this->createSerializableObject([
            'registers' => ['reg-1'],
            'schemas'   => ['sch-1'],
        ]);
        $objectService->method('searchObjects')->willReturn([$catalog]);
        $objectService->method('searchObjectsPaginated')->willReturn([
            'results' => [],
            'total'   => 0,
        ]);

        $customParams = ['_limit' => 5, '_page' => 2];
        $response     = $this->service->index(null, $customParams);

        $this->assertInstanceOf(JSONResponse::class, $response);
    }

    public function testIndexReturns400OnInvalidArgument(): void
    {
        $objectService = $this->createObjectServiceMock();
        $this->mockObjectServiceAvailable($objectService);

        $this->config->method('getValueString')->willReturn('');

        $catalog = $this->createSerializableObject([
            'registers' => ['reg-1'],
            'schemas'   => ['sch-1'],
        ]);
        $objectService->method('searchObjects')->willReturn([$catalog]);

        // Request asks for a register not in the catalog context
        $this->request->method('getParams')->willReturn([
            '@self' => ['register' => 'invalid-register'],
        ]);

        $response = $this->service->index();
        $this->assertInstanceOf(JSONResponse::class, $response);

        $data = json_decode($response->render(), true);
        $this->assertArrayHasKey('error', $data);
        $this->assertSame(400, $response->getStatus());
    }

    public function testIndexReturns400OnInvalidSchema(): void
    {
        $objectService = $this->createObjectServiceMock();
        $this->mockObjectServiceAvailable($objectService);

        $this->config->method('getValueString')->willReturn('');

        $catalog = $this->createSerializableObject([
            'registers' => ['reg-1'],
            'schemas'   => ['sch-1'],
        ]);
        $objectService->method('searchObjects')->willReturn([$catalog]);

        $this->request->method('getParams')->willReturn([
            '@self' => ['schema' => 'invalid-schema'],
        ]);

        $response = $this->service->index();
        $this->assertSame(400, $response->getStatus());
    }

    // =======================================================================
    // show()
    // =======================================================================

    public function testShowReturnsObjectWhenFound(): void
    {
        $objectService = $this->createObjectServiceMock();
        $this->mockObjectServiceAvailable($objectService);

        $objectEntity = $this->createSerializableObject(['id' => 'pub-1', 'title' => 'Found Publication']);
        $objectService->method('find')
            ->with('pub-1', [])
            ->willReturn($objectEntity);

        $this->request->method('getParams')->willReturn([]);

        $response = $this->service->show('pub-1');
        $this->assertInstanceOf(JSONResponse::class, $response);

        $data = json_decode($response->render(), true);
        $this->assertSame('pub-1', $data['id']);
        $this->assertSame('Found Publication', $data['title']);
    }

    public function testShowReturns404WhenNotFound(): void
    {
        $objectService = $this->createObjectServiceMock();
        $this->mockObjectServiceAvailable($objectService);

        $objectService->method('find')
            ->willThrowException(new DoesNotExistException('Not found'));

        $this->request->method('getParams')->willReturn([]);

        $response = $this->service->show('nonexistent');
        $this->assertSame(404, $response->getStatus());

        $data = json_decode($response->render(), true);
        $this->assertSame('Not Found', $data['error']);
    }

    public function testShowWithExtendParameter(): void
    {
        $objectService = $this->createObjectServiceMock();
        $this->mockObjectServiceAvailable($objectService);

        $objectEntity = $this->createSerializableObject(['id' => 'pub-1']);
        $objectService->method('find')
            ->with('pub-1', ['@self.files'])
            ->willReturn($objectEntity);

        $this->request->method('getParams')->willReturn([
            'extend' => ['@self.files', 'invalid_no_prefix'],
        ]);

        $response = $this->service->show('pub-1');
        $this->assertInstanceOf(JSONResponse::class, $response);
    }

    public function testShowWithExtendAsString(): void
    {
        $objectService = $this->createObjectServiceMock();
        $this->mockObjectServiceAvailable($objectService);

        $objectEntity = $this->createSerializableObject(['id' => 'pub-1']);
        $objectService->method('find')
            ->with('pub-1', ['@self.metadata'])
            ->willReturn($objectEntity);

        $this->request->method('getParams')->willReturn([
            'extend' => '@self.metadata',
        ]);

        $response = $this->service->show('pub-1');
        $this->assertInstanceOf(JSONResponse::class, $response);
    }

    public function testShowFiltersNonSelfExtend(): void
    {
        $objectService = $this->createObjectServiceMock();
        $this->mockObjectServiceAvailable($objectService);

        // After filtering, only @self.files should remain; 'other.field' is stripped
        $objectEntity = $this->createSerializableObject(['id' => 'pub-1']);
        $objectService->method('find')
            ->with('pub-1', ['@self.files'])
            ->willReturn($objectEntity);

        $this->request->method('getParams')->willReturn([
            'extend' => ['@self.files', 'other.field'],
        ]);

        $response = $this->service->show('pub-1');
        $this->assertInstanceOf(JSONResponse::class, $response);
    }

    // =======================================================================
    // attachments()
    // =======================================================================

    public function testAttachmentsReturnsFormattedFiles(): void
    {
        // Need to set up both ObjectService and FileService via container
        $objectService = $this->createObjectServiceMock();
        $fileService   = $this->createFileServiceMock();

        $this->appManager->method('getInstalledApps')
            ->willReturn(['openregister']);

        $this->container->method('get')
            ->willReturnCallback(function (string $class) use ($objectService, $fileService) {
                if ($class === 'OCA\OpenRegister\Service\ObjectService') {
                    return $objectService;
                }
                if ($class === 'OCA\OpenRegister\Service\FileService') {
                    return $fileService;
                }
                return null;
            });

        $objectService->method('find')->willReturn($this->createSerializableObject(['id' => 'pub-1']));

        $formattedData = [
            'results' => [['name' => 'file1.pdf']],
            'total'   => 1,
        ];
        $fileService->method('getFiles')->willReturn([['raw-file']]);
        $fileService->method('formatFiles')->willReturn($formattedData);

        $this->request->method('getParams')->willReturn([]);

        $response = $this->service->attachments('pub-1');
        $this->assertInstanceOf(JSONResponse::class, $response);

        $data = json_decode($response->render(), true);
        $this->assertArrayHasKey('results', $data);
    }

    public function testAttachmentsReturns404OnDoesNotExist(): void
    {
        $objectService = $this->createObjectServiceMock();
        $fileService   = $this->createFileServiceMock();

        $this->appManager->method('getInstalledApps')
            ->willReturn(['openregister']);

        $this->container->method('get')
            ->willReturnCallback(function (string $class) use ($objectService, $fileService) {
                if ($class === 'OCA\OpenRegister\Service\ObjectService') {
                    return $objectService;
                }
                if ($class === 'OCA\OpenRegister\Service\FileService') {
                    return $fileService;
                }
                return null;
            });

        $objectService->method('find')->willReturn($this->createSerializableObject(['id' => 'pub-1']));
        $fileService->method('getFiles')
            ->willThrowException(new DoesNotExistException('Not found'));

        $this->request->method('getParams')->willReturn([]);

        $response = $this->service->attachments('pub-1');
        $this->assertSame(404, $response->getStatus());
    }

    public function testAttachmentsReturns500OnGenericException(): void
    {
        $objectService = $this->createObjectServiceMock();
        $fileService   = $this->createFileServiceMock();

        $this->appManager->method('getInstalledApps')
            ->willReturn(['openregister']);

        $this->container->method('get')
            ->willReturnCallback(function (string $class) use ($objectService, $fileService) {
                if ($class === 'OCA\OpenRegister\Service\ObjectService') {
                    return $objectService;
                }
                if ($class === 'OCA\OpenRegister\Service\FileService') {
                    return $fileService;
                }
                return null;
            });

        $objectService->method('find')->willReturn($this->createSerializableObject(['id' => 'pub-1']));
        $fileService->method('getFiles')
            ->willThrowException(new \Exception('Something broke'));

        $this->request->method('getParams')->willReturn([]);

        $response = $this->service->attachments('pub-1');
        $this->assertSame(500, $response->getStatus());
    }

    // =======================================================================
    // download()
    // =======================================================================

    public function testDownloadReturns404OnDoesNotExist(): void
    {
        $fileService = $this->createFileServiceMock();
        $this->mockFileServiceAvailable($fileService);

        $fileService->method('createObjectFilesZip')
            ->willThrowException(new DoesNotExistException('Not found'));

        $response = $this->service->download('pub-1');
        $this->assertInstanceOf(JSONResponse::class, $response);
        $this->assertSame(404, $response->getStatus());
    }

    public function testDownloadReturns500OnGenericException(): void
    {
        $fileService = $this->createFileServiceMock();
        $this->mockFileServiceAvailable($fileService);

        $fileService->method('createObjectFilesZip')
            ->willThrowException(new \Exception('ZIP creation failed'));

        $response = $this->service->download('pub-1');
        $this->assertInstanceOf(JSONResponse::class, $response);
        $this->assertSame(500, $response->getStatus());

        $data = json_decode($response->render(), true);
        $this->assertStringContainsString('ZIP creation failed', $data['error']);
    }

    // =======================================================================
    // uses()
    // =======================================================================

    public function testUsesReturnsEmptyWhenNoRelations(): void
    {
        $objectService = $this->createObjectServiceMock();
        $this->mockObjectServiceAvailable($objectService);

        $pubObj = $this->createSerializableObject([
            'id'        => 'pub-1',
            'relations' => [],
        ]);
        $objectService->method('find')->willReturn($pubObj);

        $response = $this->service->uses('pub-1');
        $this->assertInstanceOf(JSONResponse::class, $response);

        $data = json_decode($response->render(), true);
        $this->assertSame([], $data['results']);
        $this->assertSame(0, $data['total']);
        $this->assertSame(1, $data['page']);
        $this->assertSame(1, $data['pages']);
    }

    public function testUsesReturnsRelatedObjects(): void
    {
        $objectService = $this->createObjectServiceMock();
        $this->mockObjectServiceAvailable($objectService);

        $this->config->method('getValueString')
            ->willReturnMap([
                ['opencatalogi', 'catalog_schema', '', 'schema-1'],
                ['opencatalogi', 'catalog_register', '', 'register-1'],
            ]);

        $pubObj = $this->createSerializableObject([
            'id'        => 'pub-1',
            'relations' => ['rel-1', 'rel-2'],
        ]);

        $catalog = $this->createSerializableObject([
            'registers' => ['reg-1'],
            'schemas'   => ['sch-1'],
        ]);

        $relatedObj = $this->createSerializableObject([
            '@self' => ['id' => 'rel-1', 'title' => 'Related'],
        ]);

        $objectService->method('find')->willReturn($pubObj);
        $objectService->method('searchObjects')->willReturn([$catalog]);
        $objectService->method('searchObjectsPaginated')->willReturn([
            'results' => [$relatedObj],
            'total'   => 1,
        ]);

        $this->request->method('getParams')->willReturn([]);

        $response = $this->service->uses('pub-1');
        $this->assertInstanceOf(JSONResponse::class, $response);
    }

    // =======================================================================
    // used()
    // =======================================================================

    public function testUsedReturnsEmptyWhenNoRelations(): void
    {
        $objectService = $this->createObjectServiceMock();
        $this->mockObjectServiceAvailable($objectService);

        $objectService->method('findByRelations')->willReturn([]);

        $response = $this->service->used('pub-1');
        $this->assertInstanceOf(JSONResponse::class, $response);

        $data = json_decode($response->render(), true);
        $this->assertSame([], $data['results']);
        $this->assertSame(0, $data['total']);
    }

    public function testUsedReturnsReferencingObjects(): void
    {
        $objectService = $this->createObjectServiceMock();
        $this->mockObjectServiceAvailable($objectService);

        $this->config->method('getValueString')
            ->willReturnMap([
                ['opencatalogi', 'catalog_schema', '', 'schema-1'],
                ['opencatalogi', 'catalog_register', '', 'register-1'],
            ]);

        $relObj = $this->createSerializableObject([
            'uuid' => 'ref-obj-1',
        ]);
        $objectService->method('findByRelations')->willReturn([$relObj]);

        $catalog = $this->createSerializableObject([
            'registers' => ['reg-1'],
            'schemas'   => ['sch-1'],
        ]);
        $objectService->method('searchObjects')->willReturn([$catalog]);

        $resultObj = $this->createSerializableObject([
            '@self' => ['id' => 'ref-obj-1'],
        ]);
        $objectService->method('searchObjectsPaginated')->willReturn([
            'results' => [$resultObj],
            'total'   => 1,
        ]);

        $this->request->method('getParams')->willReturn([]);

        $response = $this->service->used('pub-1');
        $this->assertInstanceOf(JSONResponse::class, $response);
    }

    // =======================================================================
    // getAggregatedPublications() — non-aggregate / ultra-fast path
    // =======================================================================

    public function testGetAggregatedPublicationsNoAggregation(): void
    {
        $objectService = $this->createObjectServiceMock();
        $this->mockObjectServiceAvailable($objectService);

        $this->config->method('getValueString')
            ->willReturnMap([
                ['opencatalogi', 'catalog_schema', '', 'schema-1'],
                ['opencatalogi', 'catalog_register', '', 'register-1'],
            ]);

        $catalog = $this->createSerializableObject([
            'registers' => ['reg-1'],
            'schemas'   => ['sch-1'],
        ]);
        $objectService->method('searchObjects')->willReturn([$catalog]);

        $resultObj = $this->createSerializableObject([
            '@self' => ['id' => 'pub-1', 'title' => 'Test'],
        ]);
        $objectService->method('searchObjectsPaginated')->willReturn([
            'results' => [$resultObj],
            'total'   => 1,
        ]);

        $this->request->method('getParams')->willReturn([]);

        $result = $this->service->getAggregatedPublications(
            ['_aggregate' => 'false'],
            [],
            'http://example.com/api'
        );

        $this->assertArrayHasKey('results', $result);
        $this->assertArrayHasKey('total', $result);
        $this->assertArrayHasKey('page', $result);
        $this->assertArrayHasKey('pages', $result);
        $this->assertArrayHasKey('_performance', $result);
    }

    public function testGetAggregatedPublicationsDefaultPagination(): void
    {
        $objectService = $this->createObjectServiceMock();
        $this->mockObjectServiceAvailable($objectService);

        $this->config->method('getValueString')->willReturn('');

        $catalog = $this->createSerializableObject([
            'registers' => ['reg-1'],
            'schemas'   => ['sch-1'],
        ]);
        $objectService->method('searchObjects')->willReturn([$catalog]);
        $objectService->method('searchObjectsPaginated')->willReturn([
            'results' => [],
            'total'   => 0,
        ]);

        $this->request->method('getParams')->willReturn([]);

        $result = $this->service->getAggregatedPublications(
            ['_aggregate' => 'false'],
            [],
            ''
        );

        $this->assertSame(1, $result['page']);
        $this->assertSame(20, $result['limit']);
        $this->assertSame(0, $result['offset']);
    }

    public function testGetAggregatedPublicationsCustomPagination(): void
    {
        $objectService = $this->createObjectServiceMock();
        $this->mockObjectServiceAvailable($objectService);

        $this->config->method('getValueString')->willReturn('');

        $catalog = $this->createSerializableObject([
            'registers' => ['reg-1'],
            'schemas'   => ['sch-1'],
        ]);
        $objectService->method('searchObjects')->willReturn([$catalog]);
        $objectService->method('searchObjectsPaginated')->willReturn([
            'results' => [],
            'total'   => 50,
        ]);

        $this->request->method('getParams')->willReturn([]);

        $result = $this->service->getAggregatedPublications(
            ['_aggregate' => 'false', '_limit' => 10, '_page' => 3],
            [],
            'http://example.com/api'
        );

        $this->assertSame(3, $result['page']);
        $this->assertSame(10, $result['limit']);
    }

    public function testGetAggregatedPublicationsNextPrevLinks(): void
    {
        $objectService = $this->createObjectServiceMock();
        $this->mockObjectServiceAvailable($objectService);

        $this->config->method('getValueString')->willReturn('');

        $catalog = $this->createSerializableObject([
            'registers' => ['reg-1'],
            'schemas'   => ['sch-1'],
        ]);
        $objectService->method('searchObjects')->willReturn([$catalog]);
        $objectService->method('searchObjectsPaginated')->willReturn([
            'results' => [],
            'total'   => 30,
        ]);

        $this->request->method('getParams')->willReturn([]);

        // Page 2 of 3 should have both next and prev
        $result = $this->service->getAggregatedPublications(
            ['_aggregate' => 'false', '_limit' => 10, '_page' => 2],
            ['_page' => 2],
            'http://example.com/api'
        );

        $this->assertArrayHasKey('next', $result);
        $this->assertArrayHasKey('prev', $result);
        $this->assertStringContainsString('_page=3', $result['next']);
        $this->assertStringContainsString('_page=1', $result['prev']);
    }

    public function testGetAggregatedPublicationsNoFederatedDirectories(): void
    {
        $objectService = $this->createObjectServiceMock();
        $this->mockObjectServiceAvailable($objectService);

        $this->config->method('getValueString')->willReturn('');

        $catalog = $this->createSerializableObject([
            'registers' => ['reg-1'],
            'schemas'   => ['sch-1'],
        ]);
        $objectService->method('searchObjects')->willReturn([$catalog]);
        $objectService->method('searchObjectsPaginated')->willReturn([
            'results' => [],
            'total'   => 0,
        ]);

        $this->request->method('getParams')->willReturn([]);

        // Aggregation enabled but no federated directories
        $this->directoryService->method('getUniqueDirectories')->willReturn([]);

        $result = $this->service->getAggregatedPublications([], [], '');

        $this->assertArrayHasKey('results', $result);
        $this->assertArrayHasKey('_performance', $result);
        $this->assertTrue($result['_performance']['ultra_fast_path']);
    }

    public function testGetAggregatedPublicationsDirectoryExceptionFallsBackToLocal(): void
    {
        $objectService = $this->createObjectServiceMock();
        $this->mockObjectServiceAvailable($objectService);

        $this->config->method('getValueString')->willReturn('');

        $catalog = $this->createSerializableObject([
            'registers' => ['reg-1'],
            'schemas'   => ['sch-1'],
        ]);
        $objectService->method('searchObjects')->willReturn([$catalog]);
        $objectService->method('searchObjectsPaginated')->willReturn([
            'results' => [],
            'total'   => 0,
        ]);

        $this->request->method('getParams')->willReturn([]);

        $this->directoryService->method('getUniqueDirectories')
            ->willThrowException(new \Exception('Directory unavailable'));

        $result = $this->service->getAggregatedPublications([], [], '');

        $this->assertArrayHasKey('results', $result);
    }

    public function testGetAggregatedPublicationsWithFederatedDirectories(): void
    {
        $objectService = $this->createObjectServiceMock();
        $this->mockObjectServiceAvailable($objectService);

        $this->config->method('getValueString')
            ->willReturnMap([
                ['opencatalogi', 'catalog_schema', '', 'schema-1'],
                ['opencatalogi', 'catalog_register', '', 'register-1'],
            ]);

        $catalog = $this->createSerializableObject([
            'registers' => ['reg-1'],
            'schemas'   => ['sch-1'],
        ]);
        $objectService->method('searchObjects')->willReturn([$catalog]);

        $localObj = $this->createSerializableObject([
            '@self' => ['id' => 'pub-local'],
        ]);
        $objectService->method('searchObjectsPaginated')->willReturn([
            'results' => [$localObj],
            'total'   => 1,
        ]);

        $this->request->method('getParams')->willReturn([]);

        $this->directoryService->method('getUniqueDirectories')
            ->willReturn(['https://external.example.com']);

        $this->directoryService->method('getPublications')->willReturn([
            'results' => [
                ['id' => 'pub-federated', 'title' => 'Federated Pub'],
            ],
            'total' => 1,
        ]);

        $result = $this->service->getAggregatedPublications([], [], 'http://example.com/api');

        $this->assertArrayHasKey('results', $result);
        // Should include both local and federated
        $this->assertSame(2, $result['total']);
    }

    public function testGetAggregatedPublicationsDeduplicatesResults(): void
    {
        $objectService = $this->createObjectServiceMock();
        $this->mockObjectServiceAvailable($objectService);

        $this->config->method('getValueString')
            ->willReturnMap([
                ['opencatalogi', 'catalog_schema', '', 'schema-1'],
                ['opencatalogi', 'catalog_register', '', 'register-1'],
            ]);

        $catalog = $this->createSerializableObject([
            'registers' => ['reg-1'],
            'schemas'   => ['sch-1'],
        ]);
        $objectService->method('searchObjects')->willReturn([$catalog]);

        $localObj = $this->createSerializableObject([
            '@self' => ['id' => 'pub-1'],
            'id'    => 'shared-id',
        ]);
        $objectService->method('searchObjectsPaginated')->willReturn([
            'results' => [$localObj],
            'total'   => 1,
        ]);

        $this->request->method('getParams')->willReturn([]);

        $this->directoryService->method('getUniqueDirectories')
            ->willReturn(['https://external.example.com']);

        // Federated returns same ID — should be deduplicated
        $this->directoryService->method('getPublications')->willReturn([
            'results' => [
                ['id' => 'shared-id', 'title' => 'Duplicate'],
            ],
            'total' => 1,
        ]);

        $result = $this->service->getAggregatedPublications([], [], '');

        // Results should be deduplicated by ID
        $ids = array_column($result['results'], 'id');
        $this->assertCount(count(array_unique($ids)), $ids);
    }

    public function testGetAggregatedPublicationsFederationFailureFallsBack(): void
    {
        $objectService = $this->createObjectServiceMock();
        $this->mockObjectServiceAvailable($objectService);

        $this->config->method('getValueString')
            ->willReturnMap([
                ['opencatalogi', 'catalog_schema', '', 'schema-1'],
                ['opencatalogi', 'catalog_register', '', 'register-1'],
            ]);

        $catalog = $this->createSerializableObject([
            'registers' => ['reg-1'],
            'schemas'   => ['sch-1'],
        ]);
        $objectService->method('searchObjects')->willReturn([$catalog]);

        $localObj = $this->createSerializableObject([
            '@self' => ['id' => 'pub-local'],
        ]);
        $objectService->method('searchObjectsPaginated')->willReturn([
            'results' => [$localObj],
            'total'   => 1,
        ]);

        $this->request->method('getParams')->willReturn([]);

        $this->directoryService->method('getUniqueDirectories')
            ->willReturn(['https://external.example.com']);

        $this->directoryService->method('getPublications')
            ->willThrowException(new \Exception('Network error'));

        $result = $this->service->getAggregatedPublications([], [], '');

        // Should still return local results
        $this->assertArrayHasKey('results', $result);
        $this->assertArrayHasKey('_performance', $result);
        $this->assertSame('Federation failed, returned local only', $result['_performance']['error']);
    }

    // =======================================================================
    // getFederatedPublication()
    // =======================================================================

    public function testGetFederatedPublicationFoundLocally(): void
    {
        $objectService = $this->createObjectServiceMock();
        $this->mockObjectServiceAvailable($objectService);

        $objectService->method('find')
            ->willReturn($this->createSerializableObject(['id' => 'pub-1', '@self' => ['title' => 'Local Pub']]));

        $this->request->method('getParams')->willReturn([]);

        $result = $this->service->getFederatedPublication('pub-1', ['_aggregate' => 'false']);

        $this->assertTrue($result['success']);
        $this->assertSame(200, $result['status']);
        $this->assertSame('pub-1', $result['data']['id']);
    }

    public function testGetFederatedPublicationFoundLocallyWithAggregation(): void
    {
        $objectService = $this->createObjectServiceMock();
        $this->mockObjectServiceAvailable($objectService);

        $this->config->method('getValueString')->willReturn('');

        $objectService->method('find')
            ->willReturn($this->createSerializableObject(['id' => 'pub-1', '@self' => ['title' => 'Local Pub']]));

        $objectService->method('searchObjects')->willReturn([]);

        $this->request->method('getParams')->willReturn([]);

        $result = $this->service->getFederatedPublication('pub-1');

        $this->assertTrue($result['success']);
        $this->assertSame(200, $result['status']);
    }

    public function testGetFederatedPublicationNotFoundLocallyNoAggregation(): void
    {
        $objectService = $this->createObjectServiceMock();
        $this->mockObjectServiceAvailable($objectService);

        $objectService->method('find')
            ->willThrowException(new DoesNotExistException('Not found'));

        $this->request->method('getParams')->willReturn([]);

        $result = $this->service->getFederatedPublication('missing', ['_aggregate' => 'false']);

        $this->assertFalse($result['success']);
        $this->assertSame(404, $result['status']);
    }

    public function testGetFederatedPublicationNotFoundLocallySearchesFederated(): void
    {
        $objectService = $this->createObjectServiceMock();
        $this->mockObjectServiceAvailable($objectService);

        $objectService->method('find')
            ->willThrowException(new DoesNotExistException('Not found'));

        $this->request->method('getParams')->willReturn([]);

        $this->directoryService->method('getPublication')
            ->willReturn([
                'result' => ['id' => 'fed-1', 'title' => 'Federated Pub'],
                'source' => ['url' => 'https://external.example.com'],
            ]);

        $result = $this->service->getFederatedPublication('fed-1');

        $this->assertTrue($result['success']);
        $this->assertSame(200, $result['status']);
        $this->assertSame('fed-1', $result['data']['id']);
        $this->assertArrayHasKey('sources', $result['data']);
    }

    public function testGetFederatedPublicationNotFoundAnywhere(): void
    {
        $objectService = $this->createObjectServiceMock();
        $this->mockObjectServiceAvailable($objectService);

        $objectService->method('find')
            ->willThrowException(new DoesNotExistException('Not found'));

        $this->request->method('getParams')->willReturn([]);

        $this->directoryService->method('getPublication')
            ->willReturn([]);

        $result = $this->service->getFederatedPublication('nonexistent');

        $this->assertFalse($result['success']);
        $this->assertSame(404, $result['status']);
    }

    public function testGetFederatedPublicationFederationException(): void
    {
        $objectService = $this->createObjectServiceMock();
        $this->mockObjectServiceAvailable($objectService);

        $objectService->method('find')
            ->willThrowException(new DoesNotExistException('Not found'));

        $this->request->method('getParams')->willReturn([]);

        $this->directoryService->method('getPublication')
            ->willThrowException(new \Exception('Network error'));

        $result = $this->service->getFederatedPublication('pub-1');

        $this->assertFalse($result['success']);
        $this->assertSame(404, $result['status']);
    }

    public function testGetFederatedPublicationWithTimeoutParams(): void
    {
        $objectService = $this->createObjectServiceMock();
        $this->mockObjectServiceAvailable($objectService);

        $objectService->method('find')
            ->willThrowException(new DoesNotExistException('Not found'));

        $this->request->method('getParams')->willReturn([]);

        $this->directoryService->expects($this->once())
            ->method('getPublication')
            ->with(
                'pub-1',
                $this->callback(function ($config) {
                    return $config['timeout'] === 10
                        && $config['connect_timeout'] === 5;
                })
            )
            ->willReturn([]);

        $this->service->getFederatedPublication('pub-1', [
            'timeout'         => '10',
            'connect_timeout' => '5',
        ]);
    }

    // =======================================================================
    // getFederatedUsed()
    // =======================================================================

    public function testGetFederatedUsedNoAggregation(): void
    {
        $objectService = $this->createObjectServiceMock();
        $this->mockObjectServiceAvailable($objectService);

        $objectService->method('findByRelations')->willReturn([]);

        $this->request->method('getParams')->willReturn([]);

        $result = $this->service->getFederatedUsed('pub-1', ['_aggregate' => 'false']);

        $this->assertTrue($result['success']);
        $this->assertSame(200, $result['status']);
    }

    public function testGetFederatedUsedWithAggregation(): void
    {
        $objectService = $this->createObjectServiceMock();
        $this->mockObjectServiceAvailable($objectService);

        $objectService->method('findByRelations')->willReturn([]);

        $this->request->method('getParams')->willReturn([]);

        $this->directoryService->method('getUsed')->willReturn([
            'results' => [['id' => 'fed-used-1']],
            'sources' => ['external' => 'https://external.example.com'],
        ]);

        $result = $this->service->getFederatedUsed('pub-1');

        $this->assertTrue($result['success']);
        $this->assertSame(200, $result['status']);
        $this->assertNotEmpty($result['data']['results']);
    }

    public function testGetFederatedUsedFederationFailure(): void
    {
        $objectService = $this->createObjectServiceMock();
        $this->mockObjectServiceAvailable($objectService);

        $objectService->method('findByRelations')->willReturn([]);

        $this->request->method('getParams')->willReturn([]);

        $this->directoryService->method('getUsed')
            ->willThrowException(new \Exception('Network error'));

        $result = $this->service->getFederatedUsed('pub-1');

        // Should return local results gracefully
        $this->assertTrue($result['success']);
        $this->assertSame(200, $result['status']);
    }

    // =======================================================================
    // getFederatedUses()
    // =======================================================================

    public function testGetFederatedUsesNoAggregation(): void
    {
        $objectService = $this->createObjectServiceMock();
        $this->mockObjectServiceAvailable($objectService);

        $pubObj = $this->createSerializableObject([
            'id'        => 'pub-1',
            'relations' => [],
        ]);
        $objectService->method('find')->willReturn($pubObj);

        $this->request->method('getParams')->willReturn([]);

        $result = $this->service->getFederatedUses('pub-1', ['_aggregate' => 'false']);

        $this->assertTrue($result['success']);
        $this->assertSame(200, $result['status']);
    }

    public function testGetFederatedUsesWithAggregation(): void
    {
        $objectService = $this->createObjectServiceMock();
        $this->mockObjectServiceAvailable($objectService);

        $pubObj = $this->createSerializableObject([
            'id'        => 'pub-1',
            'relations' => [],
        ]);
        $objectService->method('find')->willReturn($pubObj);

        $this->request->method('getParams')->willReturn([]);

        // getFederatedUses currently returns local results only
        $result = $this->service->getFederatedUses('pub-1');

        $this->assertTrue($result['success']);
        $this->assertSame(200, $result['status']);
    }

    // =======================================================================
    // Private method: filterUnwantedProperties (via reflection)
    // =======================================================================

    public function testFilterUnwantedPropertiesRemovesBlacklisted(): void
    {
        $method = new \ReflectionMethod(PublicationService::class, 'filterUnwantedProperties');
        $method->setAccessible(true);

        $obj = $this->createSerializableObject([
            '@self' => [
                'id'            => 'pub-1',
                'title'         => 'Keep me',
                'schemaVersion' => 'remove',
                'relations'     => 'remove',
                'locked'        => 'remove',
                'owner'         => 'remove',
                'folder'        => 'remove',
                'application'   => 'remove',
                'validation'    => 'remove',
                'retention'     => 'remove',
                'size'          => 'remove',
                'deleted'       => 'remove',
            ],
        ]);

        $result = $method->invoke($this->service, [$obj]);

        $this->assertCount(1, $result);
        $self = $result[0]['@self'];
        $this->assertSame('pub-1', $self['id']);
        $this->assertSame('Keep me', $self['title']);
        $this->assertArrayNotHasKey('schemaVersion', $self);
        $this->assertArrayNotHasKey('relations', $self);
        $this->assertArrayNotHasKey('locked', $self);
        $this->assertArrayNotHasKey('owner', $self);
        $this->assertArrayNotHasKey('folder', $self);
        $this->assertArrayNotHasKey('application', $self);
        $this->assertArrayNotHasKey('validation', $self);
        $this->assertArrayNotHasKey('retention', $self);
        $this->assertArrayNotHasKey('size', $self);
        $this->assertArrayNotHasKey('deleted', $self);
    }

    public function testFilterUnwantedPropertiesFiltersFilesWithoutPublished(): void
    {
        $method = new \ReflectionMethod(PublicationService::class, 'filterUnwantedProperties');
        $method->setAccessible(true);

        $obj = $this->createSerializableObject([
            '@self' => [
                'id'    => 'pub-1',
                'files' => [
                    ['name' => 'published.pdf', 'published' => true],
                    ['name' => 'unpublished.pdf'],
                    ['name' => 'also-published.pdf', 'published' => false],
                ],
            ],
        ]);

        $result = $method->invoke($this->service, [$obj]);

        $files = array_values($result[0]['@self']['files']);
        $this->assertCount(2, $files);
        $this->assertSame('published.pdf', $files[0]['name']);
        $this->assertSame('also-published.pdf', $files[1]['name']);
    }

    public function testFilterUnwantedPropertiesHandlesNoSelfKey(): void
    {
        $method = new \ReflectionMethod(PublicationService::class, 'filterUnwantedProperties');
        $method->setAccessible(true);

        $obj = $this->createSerializableObject([
            'title' => 'No @self key',
        ]);

        $result = $method->invoke($this->service, [$obj]);
        $this->assertCount(1, $result);
        $this->assertSame('No @self key', $result[0]['title']);
    }

    public function testFilterUnwantedPropertiesHandlesEmptyArray(): void
    {
        $method = new \ReflectionMethod(PublicationService::class, 'filterUnwantedProperties');
        $method->setAccessible(true);

        $result = $method->invoke($this->service, []);
        $this->assertSame([], $result);
    }

    // =======================================================================
    // Private method: extractFieldValue (via reflection)
    // =======================================================================

    public function testExtractFieldValueSimplePath(): void
    {
        $method = new \ReflectionMethod(PublicationService::class, 'extractFieldValue');
        $method->setAccessible(true);

        $result = ['title' => 'Hello'];
        $this->assertSame('Hello', $method->invoke($this->service, $result, 'title'));
    }

    public function testExtractFieldValueNestedPath(): void
    {
        $method = new \ReflectionMethod(PublicationService::class, 'extractFieldValue');
        $method->setAccessible(true);

        $result = ['@self' => ['published' => '2024-01-01']];
        $this->assertSame('2024-01-01', $method->invoke($this->service, $result, '@self.published'));
    }

    public function testExtractFieldValueMissingPath(): void
    {
        $method = new \ReflectionMethod(PublicationService::class, 'extractFieldValue');
        $method->setAccessible(true);

        $result = ['title' => 'Hello'];
        $this->assertNull($method->invoke($this->service, $result, 'nonexistent.path'));
    }

    public function testExtractFieldValueDeeplyNested(): void
    {
        $method = new \ReflectionMethod(PublicationService::class, 'extractFieldValue');
        $method->setAccessible(true);

        $result = ['a' => ['b' => ['c' => 'deep']]];
        $this->assertSame('deep', $method->invoke($this->service, $result, 'a.b.c'));
    }

    // =======================================================================
    // Private method: compareValues (via reflection)
    // =======================================================================

    public function testCompareValuesBothNull(): void
    {
        $method = new \ReflectionMethod(PublicationService::class, 'compareValues');
        $method->setAccessible(true);

        $this->assertSame(0, $method->invoke($this->service, null, null));
    }

    public function testCompareValuesFirstNull(): void
    {
        $method = new \ReflectionMethod(PublicationService::class, 'compareValues');
        $method->setAccessible(true);

        $this->assertSame(-1, $method->invoke($this->service, null, 'value'));
    }

    public function testCompareValuesSecondNull(): void
    {
        $method = new \ReflectionMethod(PublicationService::class, 'compareValues');
        $method->setAccessible(true);

        $this->assertSame(1, $method->invoke($this->service, 'value', null));
    }

    public function testCompareValuesDateStrings(): void
    {
        $method = new \ReflectionMethod(PublicationService::class, 'compareValues');
        $method->setAccessible(true);

        $result = $method->invoke($this->service, '2024-01-01', '2024-06-01');
        $this->assertLessThan(0, $result);

        $result = $method->invoke($this->service, '2024-06-01', '2024-01-01');
        $this->assertGreaterThan(0, $result);

        $result = $method->invoke($this->service, '2024-01-01', '2024-01-01');
        $this->assertSame(0, $result);
    }

    public function testCompareValuesNumeric(): void
    {
        $method = new \ReflectionMethod(PublicationService::class, 'compareValues');
        $method->setAccessible(true);

        $this->assertLessThan(0, $method->invoke($this->service, 1, 2));
        $this->assertGreaterThan(0, $method->invoke($this->service, 10, 5));
        $this->assertSame(0, $method->invoke($this->service, 42, 42));
    }

    public function testCompareValuesStrings(): void
    {
        $method = new \ReflectionMethod(PublicationService::class, 'compareValues');
        $method->setAccessible(true);

        $this->assertLessThan(0, $method->invoke($this->service, 'apple', 'banana'));
        $this->assertGreaterThan(0, $method->invoke($this->service, 'zebra', 'aardvark'));
        $this->assertSame(0, $method->invoke($this->service, 'same', 'same'));
    }

    // =======================================================================
    // Private method: applyCumulativeOrdering (via reflection)
    // =======================================================================

    public function testApplyCumulativeOrderingNoOrderParams(): void
    {
        $method = new \ReflectionMethod(PublicationService::class, 'applyCumulativeOrdering');
        $method->setAccessible(true);

        $results = [['title' => 'B'], ['title' => 'A']];
        $ordered = $method->invoke($this->service, $results, []);

        // Should remain unchanged
        $this->assertSame('B', $ordered[0]['title']);
        $this->assertSame('A', $ordered[1]['title']);
    }

    public function testApplyCumulativeOrderingAscending(): void
    {
        $method = new \ReflectionMethod(PublicationService::class, 'applyCumulativeOrdering');
        $method->setAccessible(true);

        $results = [
            ['title' => 'Banana'],
            ['title' => 'Apple'],
            ['title' => 'Cherry'],
        ];

        $ordered = $method->invoke($this->service, $results, [
            '_order' => ['title' => 'ASC'],
        ]);

        $this->assertSame('Apple', $ordered[0]['title']);
        $this->assertSame('Banana', $ordered[1]['title']);
        $this->assertSame('Cherry', $ordered[2]['title']);
    }

    public function testApplyCumulativeOrderingDescending(): void
    {
        $method = new \ReflectionMethod(PublicationService::class, 'applyCumulativeOrdering');
        $method->setAccessible(true);

        $results = [
            ['@self' => ['published' => '2024-01-01']],
            ['@self' => ['published' => '2024-06-01']],
            ['@self' => ['published' => '2024-03-01']],
        ];

        $ordered = $method->invoke($this->service, $results, [
            '_order' => ['@self.published' => 'DESC'],
        ]);

        $this->assertSame('2024-06-01', $ordered[0]['@self']['published']);
        $this->assertSame('2024-03-01', $ordered[1]['@self']['published']);
        $this->assertSame('2024-01-01', $ordered[2]['@self']['published']);
    }

    // =======================================================================
    // Private method: mergeFacetsData (via reflection)
    // =======================================================================

    public function testMergeFacetsDataReturnsLocalWhenAvailable(): void
    {
        $method = new \ReflectionMethod(PublicationService::class, 'mergeFacetsData');
        $method->setAccessible(true);

        $local     = ['@self' => ['status' => ['type' => 'terms']]];
        $federated = ['@self' => ['other' => ['type' => 'terms']]];

        $result = $method->invoke($this->service, $local, $federated);
        $this->assertSame($local, $result);
    }

    public function testMergeFacetsDataReturnsFederatedWhenLocalEmpty(): void
    {
        $method = new \ReflectionMethod(PublicationService::class, 'mergeFacetsData');
        $method->setAccessible(true);

        $federated = ['@self' => ['status' => ['type' => 'terms']]];
        $result    = $method->invoke($this->service, [], $federated);
        $this->assertSame($federated, $result);
    }

    public function testMergeFacetsDataBothEmpty(): void
    {
        $method = new \ReflectionMethod(PublicationService::class, 'mergeFacetsData');
        $method->setAccessible(true);

        $result = $method->invoke($this->service, [], []);
        $this->assertSame([], $result);
    }

    // =======================================================================
    // Private method: mergeFacetableData (via reflection)
    // =======================================================================

    public function testMergeFacetableDataAddsVirtualFields(): void
    {
        $method = new \ReflectionMethod(PublicationService::class, 'mergeFacetableData');
        $method->setAccessible(true);

        $result = $method->invoke($this->service, [], []);

        $this->assertArrayHasKey('@self', $result);
        $this->assertArrayHasKey('directory', $result['@self']);
        $this->assertArrayHasKey('catalogs', $result['@self']);
        $this->assertSame('categorical', $result['@self']['directory']['type']);
        $this->assertSame('categorical', $result['@self']['catalogs']['type']);
    }

    public function testMergeFacetableDataPreservesLocalData(): void
    {
        $method = new \ReflectionMethod(PublicationService::class, 'mergeFacetableData');
        $method->setAccessible(true);

        $local = [
            '@self'  => ['status' => ['type' => 'categorical']],
            'custom' => ['field' => 'data'],
        ];

        $result = $method->invoke($this->service, $local, []);

        $this->assertArrayHasKey('status', $result['@self']);
        $this->assertArrayHasKey('directory', $result['@self']);
        $this->assertArrayHasKey('catalogs', $result['@self']);
        $this->assertArrayHasKey('custom', $result);
    }

    // =======================================================================
    // Private method: searchPublications (via reflection) — parameter mapping
    // =======================================================================

    public function testSearchPublicationsValidatesRegisters(): void
    {
        $objectService = $this->createObjectServiceMock();
        $this->mockObjectServiceAvailable($objectService);

        $this->config->method('getValueString')->willReturn('');

        $catalog = $this->createSerializableObject([
            'registers' => ['reg-1'],
            'schemas'   => ['sch-1'],
        ]);
        $objectService->method('searchObjects')->willReturn([$catalog]);

        $method = new \ReflectionMethod(PublicationService::class, 'searchPublications');
        $method->setAccessible(true);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid register(s) requested');

        $method->invoke($this->service, null, null, [
            '@self' => ['register' => 'invalid-reg'],
        ]);
    }

    public function testSearchPublicationsValidatesSchemas(): void
    {
        $objectService = $this->createObjectServiceMock();
        $this->mockObjectServiceAvailable($objectService);

        $this->config->method('getValueString')->willReturn('');

        $catalog = $this->createSerializableObject([
            'registers' => ['reg-1'],
            'schemas'   => ['sch-1'],
        ]);
        $objectService->method('searchObjects')->willReturn([$catalog]);

        $method = new \ReflectionMethod(PublicationService::class, 'searchPublications');
        $method->setAccessible(true);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid schema(s) requested');

        $method->invoke($this->service, null, null, [
            '@self' => ['schema' => 'invalid-sch'],
        ]);
    }

    public function testSearchPublicationsPassesIdsFilter(): void
    {
        $objectService = $this->createObjectServiceMock();
        $this->mockObjectServiceAvailable($objectService);

        $this->config->method('getValueString')->willReturn('');

        $catalog = $this->createSerializableObject([
            'registers' => ['reg-1'],
            'schemas'   => ['sch-1'],
        ]);
        $objectService->method('searchObjects')->willReturn([$catalog]);

        $resultObj = $this->createSerializableObject([
            '@self' => ['id' => 'obj-1'],
        ]);

        $objectService->expects($this->once())
            ->method('searchObjectsPaginated')
            ->with($this->callback(function ($query) {
                return isset($query['_ids']) && $query['_ids'] === ['id-1', 'id-2'];
            }))
            ->willReturn(['results' => [$resultObj], 'total' => 1]);

        $this->request->method('getParams')->willReturn([]);

        $method = new \ReflectionMethod(PublicationService::class, 'searchPublications');
        $method->setAccessible(true);

        $method->invoke($this->service, null, ['id-1', 'id-2']);
    }

    public function testSearchPublicationsRemovesVirtualFacetParams(): void
    {
        $objectService = $this->createObjectServiceMock();
        $this->mockObjectServiceAvailable($objectService);

        $this->config->method('getValueString')->willReturn('');

        $catalog = $this->createSerializableObject([
            'registers' => ['reg-1'],
            'schemas'   => ['sch-1'],
        ]);
        $objectService->method('searchObjects')->willReturn([$catalog]);

        $resultObj = $this->createSerializableObject([
            '@self' => ['id' => 'obj-1'],
        ]);

        $objectService->expects($this->once())
            ->method('searchObjectsPaginated')
            ->with($this->callback(function ($query) {
                // Virtual facets should be stripped
                return !isset($query['_facets']['@self']['directory'])
                    && !isset($query['_facets']['@self']['catalogs']);
            }))
            ->willReturn(['results' => [$resultObj], 'total' => 1]);

        $this->request->method('getParams')->willReturn([
            '_facets' => [
                '@self' => [
                    'directory' => true,
                    'catalogs'  => true,
                    'status'    => true,
                ],
            ],
        ]);

        $method = new \ReflectionMethod(PublicationService::class, 'searchPublications');
        $method->setAccessible(true);

        $method->invoke($this->service, null, null);
    }

    public function testSearchPublicationsAcceptsValidRegisters(): void
    {
        $objectService = $this->createObjectServiceMock();
        $this->mockObjectServiceAvailable($objectService);

        $this->config->method('getValueString')->willReturn('');

        $catalog = $this->createSerializableObject([
            'registers' => ['reg-1', 'reg-2'],
            'schemas'   => ['sch-1'],
        ]);
        $objectService->method('searchObjects')->willReturn([$catalog]);

        $resultObj = $this->createSerializableObject([
            '@self' => ['id' => 'obj-1'],
        ]);
        $objectService->method('searchObjectsPaginated')->willReturn([
            'results' => [$resultObj],
            'total'   => 1,
        ]);

        $this->request->method('getParams')->willReturn([
            '@self' => ['register' => 'reg-1'],
        ]);

        $method = new \ReflectionMethod(PublicationService::class, 'searchPublications');
        $method->setAccessible(true);

        // Should not throw
        $result = $method->invoke($this->service, null, null);
        $this->assertArrayHasKey('results', $result);
    }

    // =======================================================================
    // Edge case: parameter mapping in searchPublications
    // =======================================================================

    public function testSearchPublicationsMapsExtendParameter(): void
    {
        $objectService = $this->createObjectServiceMock();
        $this->mockObjectServiceAvailable($objectService);

        $this->config->method('getValueString')->willReturn('');

        $catalog = $this->createSerializableObject([
            'registers' => ['reg-1'],
            'schemas'   => ['sch-1'],
        ]);
        $objectService->method('searchObjects')->willReturn([$catalog]);

        $resultObj = $this->createSerializableObject([
            '@self' => ['id' => 'obj-1'],
        ]);
        $objectService->method('searchObjectsPaginated')->willReturn([
            'results' => [$resultObj],
            'total'   => 1,
        ]);

        // 'extend' should be mapped to '_extend'
        $this->request->method('getParams')->willReturn([
            'extend' => '@self.files',
        ]);

        $method = new \ReflectionMethod(PublicationService::class, 'searchPublications');
        $method->setAccessible(true);

        $result = $method->invoke($this->service, null, null);
        $this->assertArrayHasKey('results', $result);
    }

    // =======================================================================
    // Private method: getLocalCatalogs (via reflection)
    // =======================================================================

    public function testGetLocalCatalogsReturnsEmptyWhenConfigMissing(): void
    {
        $method = new \ReflectionMethod(PublicationService::class, 'getLocalCatalogs');
        $method->setAccessible(true);

        $this->config->method('getValueString')->willReturn('');

        $result = $method->invoke($this->service);
        $this->assertSame([], $result);
    }

    public function testGetLocalCatalogsCachesResults(): void
    {
        $method = new \ReflectionMethod(PublicationService::class, 'getLocalCatalogs');
        $method->setAccessible(true);

        $objectService = $this->createObjectServiceMock();
        $this->mockObjectServiceAvailable($objectService);

        $this->config->method('getValueString')
            ->willReturnMap([
                ['opencatalogi', 'catalog_schema', '', 'schema-1'],
                ['opencatalogi', 'catalog_register', '', 'register-1'],
            ]);

        $catalog = $this->createSerializableObject([
            'id'    => 'cat-1',
            'title' => 'Test Catalog',
        ]);
        $objectService->expects($this->once())
            ->method('searchObjects')
            ->willReturn([$catalog]);

        // Call twice — searchObjects should only be called once
        $result1 = $method->invoke($this->service);
        $result2 = $method->invoke($this->service);

        $this->assertSame($result1, $result2);
    }

    public function testGetLocalCatalogsHandlesException(): void
    {
        $method = new \ReflectionMethod(PublicationService::class, 'getLocalCatalogs');
        $method->setAccessible(true);

        $objectService = $this->createObjectServiceMock();
        $this->mockObjectServiceAvailable($objectService);

        $this->config->method('getValueString')
            ->willReturnMap([
                ['opencatalogi', 'catalog_schema', '', 'schema-1'],
                ['opencatalogi', 'catalog_register', '', 'register-1'],
            ]);

        $objectService->method('searchObjects')
            ->willThrowException(new \Exception('DB error'));

        $result = $method->invoke($this->service);
        $this->assertSame([], $result);
    }

    // =======================================================================
    // Private method: getExternalCatalogsFromListings (via reflection)
    // =======================================================================

    public function testGetExternalCatalogsFromListings(): void
    {
        $method = new \ReflectionMethod(PublicationService::class, 'getExternalCatalogsFromListings');
        $method->setAccessible(true);

        $this->directoryService->method('getDirectory')->willReturn([
            'results' => [
                ['id' => 'listing-1', 'catalog' => 'cat-ext-1', 'title' => 'External Catalog 1'],
                ['id' => 'listing-2', 'catalog' => 'cat-ext-2', 'title' => 'External Catalog 2'],
                ['id' => 'listing-3', 'catalog' => 'cat-ext-1', 'title' => 'Duplicate'],
            ],
        ]);

        $result = $method->invoke($this->service);

        $this->assertCount(2, $result);
        $this->assertSame('cat-ext-1', $result[0]['key']);
        $this->assertSame('External Catalog 1', $result[0]['label']);
        $this->assertSame('cat-ext-2', $result[1]['key']);
    }

    public function testGetExternalCatalogsFromListingsHandlesException(): void
    {
        $method = new \ReflectionMethod(PublicationService::class, 'getExternalCatalogsFromListings');
        $method->setAccessible(true);

        $this->directoryService->method('getDirectory')
            ->willThrowException(new \Exception('Directory error'));

        $result = $method->invoke($this->service);
        $this->assertSame([], $result);
    }

    public function testGetExternalCatalogsFromListingsHandlesEmptyListings(): void
    {
        $method = new \ReflectionMethod(PublicationService::class, 'getExternalCatalogsFromListings');
        $method->setAccessible(true);

        $this->directoryService->method('getDirectory')->willReturn([
            'results' => [],
        ]);

        $result = $method->invoke($this->service);
        $this->assertSame([], $result);
    }

    // =======================================================================
    // Constructor / appName
    // =======================================================================

    public function testAppNameIsSetCorrectly(): void
    {
        $property = new \ReflectionProperty(PublicationService::class, 'appName');
        $property->setAccessible(true);

        $this->assertSame('opencatalogi', $property->getValue($this->service));
    }

    public function testObjectServicePropertyDefaultNull(): void
    {
        $property = new \ReflectionProperty(PublicationService::class, 'objectService');
        $property->setAccessible(true);

        $this->assertNull($property->getValue($this->service));
    }

    // =======================================================================
    // getAggregatedPublications — limit=0 edge case
    // =======================================================================

    public function testGetAggregatedPublicationsLimitZero(): void
    {
        $objectService = $this->createObjectServiceMock();
        $this->mockObjectServiceAvailable($objectService);

        $this->config->method('getValueString')->willReturn('');

        $catalog = $this->createSerializableObject([
            'registers' => ['reg-1'],
            'schemas'   => ['sch-1'],
        ]);
        $objectService->method('searchObjects')->willReturn([$catalog]);
        $objectService->method('searchObjectsPaginated')->willReturn([
            'results' => [],
            'total'   => 5,
        ]);

        $this->request->method('getParams')->willReturn([]);

        $result = $this->service->getAggregatedPublications(
            ['_aggregate' => 'false', '_limit' => 0],
            [],
            ''
        );

        $this->assertSame(0, $result['limit']);
        $this->assertSame(1, $result['pages']);
    }

    // =======================================================================
    // getAggregatedPublications — negative values clamped
    // =======================================================================

    public function testGetAggregatedPublicationsNegativeValuesClampedToZero(): void
    {
        $objectService = $this->createObjectServiceMock();
        $this->mockObjectServiceAvailable($objectService);

        $this->config->method('getValueString')->willReturn('');

        $catalog = $this->createSerializableObject([
            'registers' => ['reg-1'],
            'schemas'   => ['sch-1'],
        ]);
        $objectService->method('searchObjects')->willReturn([$catalog]);
        $objectService->method('searchObjectsPaginated')->willReturn([
            'results' => [],
            'total'   => 0,
        ]);

        $this->request->method('getParams')->willReturn([]);

        $result = $this->service->getAggregatedPublications(
            ['_aggregate' => 'false', '_limit' => -5, '_page' => -1],
            [],
            ''
        );

        $this->assertSame(0, $result['limit']);
        $this->assertSame(1, $result['page']);
    }

    // =======================================================================
    // show() with _extend fallback parameter name
    // =======================================================================

    public function testShowWithUnderscoreExtendParameter(): void
    {
        $objectService = $this->createObjectServiceMock();
        $this->mockObjectServiceAvailable($objectService);

        $objectEntity = $this->createSerializableObject(['id' => 'pub-1']);
        $objectService->method('find')
            ->with('pub-1', ['@self.attachments'])
            ->willReturn($objectEntity);

        $this->request->method('getParams')->willReturn([
            '_extend' => '@self.attachments',
        ]);

        $response = $this->service->show('pub-1');
        $this->assertInstanceOf(JSONResponse::class, $response);
    }

    // =======================================================================
    // addVirtualFieldFacets (via reflection)
    // =======================================================================

    public function testAddVirtualFieldFacetsDirectoryOnly(): void
    {
        $method = new \ReflectionMethod(PublicationService::class, 'addVirtualFieldFacets');
        $method->setAccessible(true);

        $this->directoryService->method('getUniqueDirectories')
            ->willReturn(['https://external.example.com']);

        $result = $method->invoke($this->service, [], true, false);

        $this->assertArrayHasKey('@self', $result);
        $this->assertArrayHasKey('directory', $result['@self']);
        $this->assertArrayNotHasKey('catalogs', $result['@self']);

        $buckets = $result['@self']['directory']['buckets'];
        $this->assertCount(2, $buckets);
        $this->assertSame('local', $buckets[0]['key']);
    }

    public function testAddVirtualFieldFacetsCatalogsOnly(): void
    {
        $method = new \ReflectionMethod(PublicationService::class, 'addVirtualFieldFacets');
        $method->setAccessible(true);

        // Need ObjectService for getLocalCatalogs
        $objectService = $this->createObjectServiceMock();
        $this->mockObjectServiceAvailable($objectService);

        $this->config->method('getValueString')
            ->willReturnMap([
                ['opencatalogi', 'catalog_schema', '', 'schema-1'],
                ['opencatalogi', 'catalog_register', '', 'register-1'],
            ]);

        $catalog = $this->createSerializableObject([
            'id'    => 'cat-1',
            'title' => 'My Catalog',
        ]);
        $objectService->method('searchObjects')->willReturn([$catalog]);

        $this->directoryService->method('getDirectory')->willReturn(['results' => []]);

        $result = $method->invoke($this->service, [], false, true);

        $this->assertArrayHasKey('@self', $result);
        $this->assertArrayNotHasKey('directory', $result['@self']);
        $this->assertArrayHasKey('catalogs', $result['@self']);
    }

    public function testAddVirtualFieldFacetsDefaultCatalogWhenNoneFound(): void
    {
        $method = new \ReflectionMethod(PublicationService::class, 'addVirtualFieldFacets');
        $method->setAccessible(true);

        // Empty config = no catalogs
        $this->config->method('getValueString')->willReturn('');
        $this->directoryService->method('getDirectory')->willReturn(['results' => []]);

        $result = $method->invoke($this->service, [], false, true);

        $catalogs = $result['@self']['catalogs']['buckets'];
        $this->assertCount(1, $catalogs);
        $this->assertSame('default', $catalogs[0]['key']);
        $this->assertSame('Default Catalog', $catalogs[0]['label']);
    }

    public function testAddVirtualFieldFacetsHandlesException(): void
    {
        $method = new \ReflectionMethod(PublicationService::class, 'addVirtualFieldFacets');
        $method->setAccessible(true);

        $this->directoryService->method('getUniqueDirectories')
            ->willThrowException(new \Exception('Failed'));

        $existing = ['@self' => ['status' => ['type' => 'terms']]];
        $result   = $method->invoke($this->service, $existing, true, true);

        // Should return existing facets unchanged on exception
        $this->assertSame($existing, $result);
    }

    // =======================================================================
    // uses() — InvalidArgumentException handling
    // =======================================================================

    public function testUsesReturns400OnInvalidArgument(): void
    {
        $objectService = $this->createObjectServiceMock();
        $this->mockObjectServiceAvailable($objectService);

        $this->config->method('getValueString')->willReturn('');

        $pubObj = $this->createSerializableObject([
            'id'        => 'pub-1',
            'relations' => ['rel-1'],
        ]);
        $objectService->method('find')->willReturn($pubObj);

        // getCatalogFilters will return empty, making any register/schema "invalid"
        $catalog = $this->createSerializableObject([
            'registers' => ['reg-1'],
            'schemas'   => ['sch-1'],
        ]);
        $objectService->method('searchObjects')->willReturn([$catalog]);

        // Pass invalid register via request params
        $this->request->method('getParams')->willReturn([
            '@self' => ['register' => 'nonexistent-register'],
        ]);

        $response = $this->service->uses('pub-1');
        $this->assertSame(400, $response->getStatus());
    }

    // =======================================================================
    // used() — InvalidArgumentException handling
    // =======================================================================

    public function testUsedReturns400OnInvalidArgument(): void
    {
        $objectService = $this->createObjectServiceMock();
        $this->mockObjectServiceAvailable($objectService);

        $this->config->method('getValueString')->willReturn('');

        $relObj = $this->createSerializableObject(['uuid' => 'ref-1']);
        $objectService->method('findByRelations')->willReturn([$relObj]);

        $catalog = $this->createSerializableObject([
            'registers' => ['reg-1'],
            'schemas'   => ['sch-1'],
        ]);
        $objectService->method('searchObjects')->willReturn([$catalog]);

        $this->request->method('getParams')->willReturn([
            '@self' => ['register' => 'nonexistent-register'],
        ]);

        $response = $this->service->used('pub-1');
        $this->assertSame(400, $response->getStatus());
    }

    // =======================================================================
    // searchPublications — single vs multi register/schema
    // =======================================================================

    public function testSearchPublicationsUsesSingleRegisterScalar(): void
    {
        $objectService = $this->createObjectServiceMock();
        $this->mockObjectServiceAvailable($objectService);

        $this->config->method('getValueString')->willReturn('');

        $catalog = $this->createSerializableObject([
            'registers' => ['single-reg'],
            'schemas'   => ['single-sch'],
        ]);
        $objectService->method('searchObjects')->willReturn([$catalog]);

        $resultObj = $this->createSerializableObject(['@self' => ['id' => 'x']]);

        $objectService->expects($this->once())
            ->method('searchObjectsPaginated')
            ->with($this->callback(function ($query) {
                // Single register/schema should be scalar, not array
                return $query['@self']['register'] === 'single-reg'
                    && $query['@self']['schema'] === 'single-sch';
            }))
            ->willReturn(['results' => [$resultObj], 'total' => 1]);

        $this->request->method('getParams')->willReturn([]);

        $method = new \ReflectionMethod(PublicationService::class, 'searchPublications');
        $method->setAccessible(true);
        $method->invoke($this->service, null, null);
    }

    public function testSearchPublicationsUsesMultiRegisterArray(): void
    {
        $objectService = $this->createObjectServiceMock();
        $this->mockObjectServiceAvailable($objectService);

        $this->config->method('getValueString')->willReturn('');

        $catalog = $this->createSerializableObject([
            'registers' => ['reg-1', 'reg-2'],
            'schemas'   => ['sch-1', 'sch-2'],
        ]);
        $objectService->method('searchObjects')->willReturn([$catalog]);

        $resultObj = $this->createSerializableObject(['@self' => ['id' => 'x']]);

        $objectService->expects($this->once())
            ->method('searchObjectsPaginated')
            ->with($this->callback(function ($query) {
                return is_array($query['@self']['register'])
                    && is_array($query['@self']['schema']);
            }))
            ->willReturn(['results' => [$resultObj], 'total' => 1]);

        $this->request->method('getParams')->willReturn([]);

        $method = new \ReflectionMethod(PublicationService::class, 'searchPublications');
        $method->setAccessible(true);
        $method->invoke($this->service, null, null);
    }

    // =======================================================================
    // searchPublications — includeDeleted always false
    // =======================================================================

    public function testSearchPublicationsSetsIncludeDeletedFalse(): void
    {
        $objectService = $this->createObjectServiceMock();
        $this->mockObjectServiceAvailable($objectService);

        $this->config->method('getValueString')->willReturn('');

        $catalog = $this->createSerializableObject([
            'registers' => ['reg-1'],
            'schemas'   => ['sch-1'],
        ]);
        $objectService->method('searchObjects')->willReturn([$catalog]);

        $resultObj = $this->createSerializableObject(['@self' => ['id' => 'x']]);

        $objectService->expects($this->once())
            ->method('searchObjectsPaginated')
            ->with($this->callback(function ($query) {
                return $query['_includeDeleted'] === false;
            }))
            ->willReturn(['results' => [$resultObj], 'total' => 1]);

        $this->request->method('getParams')->willReturn([]);

        $method = new \ReflectionMethod(PublicationService::class, 'searchPublications');
        $method->setAccessible(true);
        $method->invoke($this->service, null, null);
    }

    // =======================================================================
    // getLocalPublicationsFast (via reflection)
    // =======================================================================

    public function testGetLocalPublicationsFastWithCatalogAndDirectoryFacets(): void
    {
        $method = new \ReflectionMethod(PublicationService::class, 'getLocalPublicationsFast');
        $method->setAccessible(true);

        $objectService = $this->createObjectServiceMock();
        $this->mockObjectServiceAvailable($objectService);

        $this->config->method('getValueString')
            ->willReturnMap([
                ['opencatalogi', 'catalog_schema', '', 'schema-1'],
                ['opencatalogi', 'catalog_register', '', 'register-1'],
            ]);

        $catalog = $this->createSerializableObject([
            'registers' => ['reg-1'],
            'schemas'   => ['sch-1'],
        ]);
        $objectService->method('searchObjects')->willReturn([$catalog]);

        $resultObj = $this->createSerializableObject([
            '@self' => ['id' => 'pub-1', 'title' => 'Test'],
        ]);
        $objectService->method('searchObjectsPaginated')->willReturn([
            'results' => [$resultObj],
            'total'   => 1,
            'facets'  => ['@self' => ['status' => ['type' => 'terms']]],
        ]);

        $this->request->method('getParams')->willReturn([]);

        $this->directoryService->method('getUniqueDirectories')
            ->willReturn(['https://external.example.com']);

        $this->directoryService->method('getDirectory')->willReturn(['results' => []]);

        $result = $method->invoke(
            $this->service,
            ['_limit' => 10, '_page' => 1],
            ['_page' => 1],
            'http://example.com/api',
            true,  // includeCatalogs
            true,  // reqDirFacets
            true   // reqCatFacets
        );

        $this->assertArrayHasKey('results', $result);
        $this->assertArrayHasKey('_performance', $result);
        $this->assertTrue($result['_performance']['fast_path']);
        $this->assertFalse($result['_performance']['ultra_fast_path']);
        $this->assertTrue($result['_performance']['include_catalogs']);
        $this->assertArrayHasKey('facets', $result);
    }

    public function testGetLocalPublicationsFastDelegatesToUltraFastWhenNoCatalogOrFacets(): void
    {
        $method = new \ReflectionMethod(PublicationService::class, 'getLocalPublicationsFast');
        $method->setAccessible(true);

        $objectService = $this->createObjectServiceMock();
        $this->mockObjectServiceAvailable($objectService);

        $this->config->method('getValueString')
            ->willReturnMap([
                ['opencatalogi', 'catalog_schema', '', 'schema-1'],
                ['opencatalogi', 'catalog_register', '', 'register-1'],
            ]);

        $catalog = $this->createSerializableObject([
            'registers' => ['reg-1'],
            'schemas'   => ['sch-1'],
        ]);
        $objectService->method('searchObjects')->willReturn([$catalog]);
        $objectService->method('searchObjectsPaginated')->willReturn([
            'results' => [],
            'total'   => 0,
        ]);

        $this->request->method('getParams')->willReturn([]);

        $result = $method->invoke(
            $this->service,
            ['_limit' => 10, '_page' => 1],
            [],
            '',
            false, // includeCatalogs
            false, // reqDirFacets
            false  // reqCatFacets
        );

        $this->assertArrayHasKey('results', $result);
        $this->assertArrayHasKey('_performance', $result);
        // Should delegate to ultra-fast path
        $this->assertTrue($result['_performance']['ultra_fast_path']);
    }

    public function testGetLocalPublicationsFastWithPaginationLinks(): void
    {
        $method = new \ReflectionMethod(PublicationService::class, 'getLocalPublicationsFast');
        $method->setAccessible(true);

        $objectService = $this->createObjectServiceMock();
        $this->mockObjectServiceAvailable($objectService);

        $this->config->method('getValueString')
            ->willReturnMap([
                ['opencatalogi', 'catalog_schema', '', 'schema-1'],
                ['opencatalogi', 'catalog_register', '', 'register-1'],
            ]);

        $catalog = $this->createSerializableObject([
            'id'        => 'cat-1',
            'title'     => 'Test Catalog',
            'registers' => ['reg-1'],
            'schemas'   => ['sch-1'],
        ]);
        $objectService->method('searchObjects')->willReturn([$catalog]);

        $resultObj = $this->createSerializableObject([
            '@self' => ['id' => 'pub-1'],
        ]);
        $objectService->method('searchObjectsPaginated')->willReturn([
            'results'   => [$resultObj],
            'total'     => 30,
            'facets'    => ['@self' => ['status' => ['type' => 'terms']]],
            'facetable' => ['@self' => ['status' => ['type' => 'categorical']]],
        ]);

        $this->request->method('getParams')->willReturn([]);

        $this->directoryService->method('getDirectory')->willReturn(['results' => []]);

        $result = $method->invoke(
            $this->service,
            ['_limit' => 10, '_page' => 2],
            ['_page' => 2],
            'http://example.com/api',
            true,  // includeCatalogs
            false, // reqDirFacets
            false  // reqCatFacets
        );

        $this->assertArrayHasKey('next', $result);
        $this->assertArrayHasKey('prev', $result);
        $this->assertSame(30, $result['total']);
        $this->assertSame(2, $result['page']);
        $this->assertEquals(3, $result['pages']);
        $this->assertArrayHasKey('facetable', $result);
    }

    public function testGetLocalPublicationsFastLimitZero(): void
    {
        $method = new \ReflectionMethod(PublicationService::class, 'getLocalPublicationsFast');
        $method->setAccessible(true);

        $objectService = $this->createObjectServiceMock();
        $this->mockObjectServiceAvailable($objectService);

        $this->config->method('getValueString')
            ->willReturnMap([
                ['opencatalogi', 'catalog_schema', '', 'schema-1'],
                ['opencatalogi', 'catalog_register', '', 'register-1'],
            ]);

        $catalog = $this->createSerializableObject([
            'registers' => ['reg-1'],
            'schemas'   => ['sch-1'],
        ]);
        $objectService->method('searchObjects')->willReturn([$catalog]);
        $objectService->method('searchObjectsPaginated')->willReturn([
            'results' => [],
            'total'   => 5,
        ]);

        $this->request->method('getParams')->willReturn([]);

        $this->directoryService->method('getDirectory')->willReturn(['results' => []]);

        $result = $method->invoke(
            $this->service,
            ['_limit' => 0, '_page' => 1],
            [],
            '',
            true,  // includeCatalogs
            false, // reqDirFacets
            true   // reqCatFacets
        );

        $this->assertSame(0, $result['limit']);
        $this->assertSame(1, $result['pages']);
    }

    public function testGetLocalPublicationsFastNoFacetsButDirFacetsRequested(): void
    {
        $method = new \ReflectionMethod(PublicationService::class, 'getLocalPublicationsFast');
        $method->setAccessible(true);

        $objectService = $this->createObjectServiceMock();
        $this->mockObjectServiceAvailable($objectService);

        $this->config->method('getValueString')
            ->willReturnMap([
                ['opencatalogi', 'catalog_schema', '', 'schema-1'],
                ['opencatalogi', 'catalog_register', '', 'register-1'],
            ]);

        $catalog = $this->createSerializableObject([
            'registers' => ['reg-1'],
            'schemas'   => ['sch-1'],
        ]);
        $objectService->method('searchObjects')->willReturn([$catalog]);
        $objectService->method('searchObjectsPaginated')->willReturn([
            'results' => [],
            'total'   => 0,
        ]);

        $this->request->method('getParams')->willReturn([]);

        $this->directoryService->method('getUniqueDirectories')
            ->willReturn([]);

        $result = $method->invoke(
            $this->service,
            ['_limit' => 10, '_page' => 1],
            [],
            '',
            false, // includeCatalogs
            true,  // reqDirFacets
            false  // reqCatFacets
        );

        $this->assertArrayHasKey('facets', $result);
    }

    public function testGetLocalPublicationsFastNestedFacetsUnwrapped(): void
    {
        $method = new \ReflectionMethod(PublicationService::class, 'getLocalPublicationsFast');
        $method->setAccessible(true);

        $objectService = $this->createObjectServiceMock();
        $this->mockObjectServiceAvailable($objectService);

        $this->config->method('getValueString')
            ->willReturnMap([
                ['opencatalogi', 'catalog_schema', '', 'schema-1'],
                ['opencatalogi', 'catalog_register', '', 'register-1'],
            ]);

        $catalog = $this->createSerializableObject([
            'registers' => ['reg-1'],
            'schemas'   => ['sch-1'],
        ]);
        $objectService->method('searchObjects')->willReturn([$catalog]);

        // Return nested facets (facets inside facets)
        $objectService->method('searchObjectsPaginated')->willReturn([
            'results' => [],
            'total'   => 0,
            'facets'  => ['facets' => ['@self' => ['status' => ['type' => 'terms']]]],
        ]);

        $this->request->method('getParams')->willReturn([]);

        $this->directoryService->method('getUniqueDirectories')
            ->willReturn(['https://external.example.com']);

        $result = $method->invoke(
            $this->service,
            ['_limit' => 10, '_page' => 1],
            [],
            '',
            true,  // includeCatalogs
            true,  // reqDirFacets
            false  // reqCatFacets
        );

        $this->assertArrayHasKey('facets', $result);
    }

    // =======================================================================
    // getAggregatedPublications — with _include_catalogs & facets
    // =======================================================================

    public function testGetAggregatedPublicationsWithIncludeCatalogs(): void
    {
        $objectService = $this->createObjectServiceMock();
        $this->mockObjectServiceAvailable($objectService);

        $this->config->method('getValueString')
            ->willReturnMap([
                ['opencatalogi', 'catalog_schema', '', 'schema-1'],
                ['opencatalogi', 'catalog_register', '', 'register-1'],
            ]);

        $catalog = $this->createSerializableObject([
            'id'        => 'cat-1',
            'title'     => 'Test Catalog',
            'registers' => ['reg-1'],
            'schemas'   => ['sch-1'],
        ]);
        $objectService->method('searchObjects')->willReturn([$catalog]);
        $objectService->method('searchObjectsPaginated')->willReturn([
            'results' => [],
            'total'   => 0,
        ]);

        $this->request->method('getParams')->willReturn([]);

        $this->directoryService->method('getUniqueDirectories')->willReturn([]);
        $this->directoryService->method('getDirectory')->willReturn(['results' => []]);

        $result = $this->service->getAggregatedPublications(
            [
                '_aggregate'        => 'false',
                '_include_catalogs' => 'true',
                '_facets'           => ['@self' => ['directory' => true, 'catalogs' => true]],
            ],
            [],
            'http://example.com/api'
        );

        $this->assertArrayHasKey('results', $result);
        $this->assertArrayHasKey('_performance', $result);
    }

    public function testGetAggregatedPublicationsUltraFastExplicitlyDisabled(): void
    {
        $objectService = $this->createObjectServiceMock();
        $this->mockObjectServiceAvailable($objectService);

        $this->config->method('getValueString')
            ->willReturnMap([
                ['opencatalogi', 'catalog_schema', '', 'schema-1'],
                ['opencatalogi', 'catalog_register', '', 'register-1'],
            ]);

        $catalog = $this->createSerializableObject([
            'id'        => 'cat-1',
            'title'     => 'Test Catalog',
            'registers' => ['reg-1'],
            'schemas'   => ['sch-1'],
        ]);
        $objectService->method('searchObjects')->willReturn([$catalog]);
        $objectService->method('searchObjectsPaginated')->willReturn([
            'results' => [],
            'total'   => 0,
        ]);

        $this->request->method('getParams')->willReturn([]);

        $this->directoryService->method('getUniqueDirectories')->willReturn([]);
        $this->directoryService->method('getDirectory')->willReturn(['results' => []]);

        $result = $this->service->getAggregatedPublications(
            [
                '_aggregate'        => 'false',
                '_ultra_fast'       => 'false',
                '_include_catalogs' => 'true',
            ],
            [],
            ''
        );

        $this->assertArrayHasKey('results', $result);
        $this->assertArrayHasKey('_performance', $result);
        $this->assertFalse($result['_performance']['ultra_fast_path']);
    }

    // =======================================================================
    // getAggregatedPublications — with federation, facets and ordering
    // =======================================================================

    public function testGetAggregatedPublicationsWithFederationFacetsAndOrdering(): void
    {
        $objectService = $this->createObjectServiceMock();
        $this->mockObjectServiceAvailable($objectService);

        $this->config->method('getValueString')
            ->willReturnMap([
                ['opencatalogi', 'catalog_schema', '', 'schema-1'],
                ['opencatalogi', 'catalog_register', '', 'register-1'],
            ]);

        $catalog = $this->createSerializableObject([
            'id'        => 'cat-1',
            'title'     => 'Test',
            'registers' => ['reg-1'],
            'schemas'   => ['sch-1'],
        ]);
        $objectService->method('searchObjects')->willReturn([$catalog]);

        $localObj = $this->createSerializableObject([
            '@self' => ['id' => 'pub-local', 'published' => '2024-06-01'],
            'id'    => 'pub-local',
        ]);
        $objectService->method('searchObjectsPaginated')->willReturn([
            'results'   => [$localObj],
            'total'     => 1,
            'facets'    => ['@self' => ['status' => ['type' => 'terms']]],
            'facetable' => ['@self' => ['status' => ['type' => 'categorical']]],
        ]);

        $this->request->method('getParams')->willReturn([]);

        $this->directoryService->method('getUniqueDirectories')
            ->willReturn(['https://external.example.com']);
        $this->directoryService->method('getDirectory')->willReturn(['results' => []]);

        $this->directoryService->method('getPublications')->willReturn([
            'results'   => [
                ['id' => 'pub-fed', '@self' => ['published' => '2024-01-01']],
            ],
            'total'     => 1,
            'facets'    => ['@self' => ['type' => ['type' => 'terms']]],
            'facetable' => ['@self' => ['type' => ['type' => 'categorical']]],
        ]);

        $result = $this->service->getAggregatedPublications(
            [
                '_order'    => ['@self.published' => 'DESC'],
                '_facets'   => ['@self' => ['directory' => true, 'catalogs' => true]],
                '_facetable' => 'true',
            ],
            ['_page' => 1],
            'http://example.com/api'
        );

        $this->assertArrayHasKey('results', $result);
        $this->assertArrayHasKey('facets', $result);
        $this->assertArrayHasKey('facetable', $result);
        $this->assertSame(2, $result['total']);
    }

    public function testGetAggregatedPublicationsWithFederationNoFacets(): void
    {
        $objectService = $this->createObjectServiceMock();
        $this->mockObjectServiceAvailable($objectService);

        $this->config->method('getValueString')
            ->willReturnMap([
                ['opencatalogi', 'catalog_schema', '', 'schema-1'],
                ['opencatalogi', 'catalog_register', '', 'register-1'],
            ]);

        $catalog = $this->createSerializableObject([
            'registers' => ['reg-1'],
            'schemas'   => ['sch-1'],
        ]);
        $objectService->method('searchObjects')->willReturn([$catalog]);

        $localObj = $this->createSerializableObject([
            '@self' => ['id' => 'pub-local'],
            'id'    => 'pub-local',
        ]);
        $objectService->method('searchObjectsPaginated')->willReturn([
            'results' => [$localObj],
            'total'   => 1,
        ]);

        $this->request->method('getParams')->willReturn([]);

        $this->directoryService->method('getUniqueDirectories')
            ->willReturn(['https://external.example.com']);

        $this->directoryService->method('getPublications')->willReturn([
            'results' => [],
            'total'   => 0,
        ]);

        $result = $this->service->getAggregatedPublications(
            [
                '_facets'    => ['@self' => ['directory' => true]],
                '_facetable' => 'true',
            ],
            [],
            ''
        );

        $this->assertArrayHasKey('results', $result);
        $this->assertArrayHasKey('facets', $result);
        $this->assertArrayHasKey('facetable', $result);
    }

    // =======================================================================
    // getLocalPublicationsUltraFast — catalog context fallback
    // =======================================================================

    public function testGetLocalPublicationsUltraFastCatalogContextFallback(): void
    {
        $method = new \ReflectionMethod(PublicationService::class, 'getLocalPublicationsUltraFast');
        $method->setAccessible(true);

        $objectService = $this->createObjectServiceMock();
        $this->mockObjectServiceAvailable($objectService);

        $this->config->method('getValueString')
            ->willReturnMap([
                ['opencatalogi', 'catalog_schema', '', 'schema-1'],
                ['opencatalogi', 'catalog_register', '', 'register-1'],
            ]);

        // searchObjects throws, should use fallback
        $objectService->method('searchObjects')
            ->willThrowException(new \Exception('DB unavailable'));

        $resultObj = $this->createSerializableObject([
            '@self' => ['id' => 'pub-1'],
        ]);
        $objectService->method('searchObjectsPaginated')->willReturn([
            'results' => [$resultObj],
            'total'   => 1,
        ]);

        $result = $method->invoke(
            $this->service,
            ['_limit' => 10, '_page' => 1],
            [],
            '',
            microtime(true)
        );

        $this->assertArrayHasKey('results', $result);
        $this->assertTrue($result['_performance']['ultra_fast_path']);
    }

    public function testGetLocalPublicationsUltraFastSkipFiltering(): void
    {
        $method = new \ReflectionMethod(PublicationService::class, 'getLocalPublicationsUltraFast');
        $method->setAccessible(true);

        $objectService = $this->createObjectServiceMock();
        $this->mockObjectServiceAvailable($objectService);

        $this->config->method('getValueString')
            ->willReturnMap([
                ['opencatalogi', 'catalog_schema', '', 'schema-1'],
                ['opencatalogi', 'catalog_register', '', 'register-1'],
            ]);

        $catalog = $this->createSerializableObject([
            'registers' => ['reg-1'],
            'schemas'   => ['sch-1'],
        ]);
        $objectService->method('searchObjects')->willReturn([$catalog]);
        $objectService->method('searchObjectsPaginated')->willReturn([
            'results' => [],
            'total'   => 0,
        ]);

        $result = $method->invoke(
            $this->service,
            ['_limit' => 10, '_page' => 1, '_skip_filtering' => 'true'],
            [],
            '',
            microtime(true)
        );

        $this->assertTrue($result['_performance']['skipped_filtering']);
    }

    public function testGetLocalPublicationsUltraFastWithVirtualFacets(): void
    {
        $method = new \ReflectionMethod(PublicationService::class, 'getLocalPublicationsUltraFast');
        $method->setAccessible(true);

        $objectService = $this->createObjectServiceMock();
        $this->mockObjectServiceAvailable($objectService);

        $this->config->method('getValueString')
            ->willReturnMap([
                ['opencatalogi', 'catalog_schema', '', 'schema-1'],
                ['opencatalogi', 'catalog_register', '', 'register-1'],
            ]);

        $catalog = $this->createSerializableObject([
            'registers' => ['reg-1'],
            'schemas'   => ['sch-1'],
        ]);
        $objectService->method('searchObjects')->willReturn([$catalog]);
        $objectService->method('searchObjectsPaginated')->willReturn([
            'results'   => [],
            'total'     => 0,
            'facets'    => ['@self' => ['status' => ['type' => 'terms']]],
            'facetable' => ['@self' => ['status' => ['type' => 'categorical']]],
        ]);

        $this->directoryService->method('getUniqueDirectories')->willReturn([]);
        $this->directoryService->method('getDirectory')->willReturn(['results' => []]);

        $result = $method->invoke(
            $this->service,
            [
                '_limit'  => 10,
                '_page'   => 1,
                '_facets' => ['@self' => ['directory' => true, 'catalogs' => true]],
            ],
            [],
            '',
            microtime(true)
        );

        $this->assertArrayHasKey('facets', $result);
        $this->assertTrue($result['_performance']['processed_virtual_facets']);
    }

    public function testGetLocalPublicationsUltraFastWithMultiRegisterSchema(): void
    {
        $method = new \ReflectionMethod(PublicationService::class, 'getLocalPublicationsUltraFast');
        $method->setAccessible(true);

        $objectService = $this->createObjectServiceMock();
        $this->mockObjectServiceAvailable($objectService);

        $this->config->method('getValueString')
            ->willReturnMap([
                ['opencatalogi', 'catalog_schema', '', 'schema-1'],
                ['opencatalogi', 'catalog_register', '', 'register-1'],
            ]);

        $catalog = $this->createSerializableObject([
            'registers' => ['reg-1', 'reg-2'],
            'schemas'   => ['sch-1', 'sch-2'],
        ]);
        $objectService->method('searchObjects')->willReturn([$catalog]);

        $objectService->expects($this->once())
            ->method('searchObjectsPaginated')
            ->with($this->callback(function ($query) {
                return is_array($query['@self']['register'])
                    && is_array($query['@self']['schema']);
            }))
            ->willReturn([
                'results' => [],
                'total'   => 0,
            ]);

        $result = $method->invoke(
            $this->service,
            ['_limit' => 10, '_page' => 1],
            [],
            '',
            microtime(true)
        );

        $this->assertArrayHasKey('results', $result);
    }

    // =======================================================================
    // getLocalCatalogs — with non-Entity catalog
    // =======================================================================

    public function testGetLocalCatalogsWithNonEntityCatalog(): void
    {
        $method = new \ReflectionMethod(PublicationService::class, 'getLocalCatalogs');
        $method->setAccessible(true);

        $objectService = $this->createObjectServiceMock();
        $this->mockObjectServiceAvailable($objectService);

        $this->config->method('getValueString')
            ->willReturnMap([
                ['opencatalogi', 'catalog_schema', '', 'schema-1'],
                ['opencatalogi', 'catalog_register', '', 'register-1'],
            ]);

        // Return a plain array (not an Entity object)
        $objectService->method('searchObjects')->willReturn([
            ['id' => 'cat-array', 'title' => 'Array Catalog'],
        ]);

        $result = $method->invoke($this->service);
        $this->assertCount(1, $result);
        $this->assertSame('cat-array', $result[0]['id']);
    }

    public function testGetLocalCatalogsSkipsEmptyId(): void
    {
        $method = new \ReflectionMethod(PublicationService::class, 'getLocalCatalogs');
        $method->setAccessible(true);

        $objectService = $this->createObjectServiceMock();
        $this->mockObjectServiceAvailable($objectService);

        $this->config->method('getValueString')
            ->willReturnMap([
                ['opencatalogi', 'catalog_schema', '', 'schema-1'],
                ['opencatalogi', 'catalog_register', '', 'register-1'],
            ]);

        $catalog = $this->createSerializableObject([
            'title' => 'No ID Catalog',
        ]);
        $objectService->method('searchObjects')->willReturn([$catalog]);

        $result = $method->invoke($this->service);
        $this->assertSame([], $result);
    }

    // =======================================================================
    // getExternalCatalogsFromListings — fallback labels
    // =======================================================================

    public function testGetExternalCatalogsFromListingsFallbackLabel(): void
    {
        $method = new \ReflectionMethod(PublicationService::class, 'getExternalCatalogsFromListings');
        $method->setAccessible(true);

        $this->directoryService->method('getDirectory')->willReturn([
            'results' => [
                // Listing with id only (no catalog, no title)
                ['id' => 'listing-no-title'],
            ],
        ]);

        $result = $method->invoke($this->service);
        $this->assertCount(1, $result);
        $this->assertSame('listing-no-title', $result[0]['key']);
        // Label falls back to catalogId which falls back to id
        $this->assertSame('listing-no-title', $result[0]['label']);
    }

    // =======================================================================
    // addVirtualFieldFacets — catalogs with both id and title
    // =======================================================================

    public function testAddVirtualFieldFacetsCatalogWithIdNoTitle(): void
    {
        $method = new \ReflectionMethod(PublicationService::class, 'addVirtualFieldFacets');
        $method->setAccessible(true);

        $objectService = $this->createObjectServiceMock();
        $this->mockObjectServiceAvailable($objectService);

        $this->config->method('getValueString')
            ->willReturnMap([
                ['opencatalogi', 'catalog_schema', '', 'schema-1'],
                ['opencatalogi', 'catalog_register', '', 'register-1'],
            ]);

        $catalog = $this->createSerializableObject([
            'id' => 'cat-no-title',
        ]);
        $objectService->method('searchObjects')->willReturn([$catalog]);

        $this->directoryService->method('getDirectory')->willReturn(['results' => []]);

        $result = $method->invoke($this->service, [], false, true);

        $buckets = $result['@self']['catalogs']['buckets'];
        $this->assertCount(1, $buckets);
        $this->assertSame('cat-no-title', $buckets[0]['key']);
        // getLocalCatalogs defaults title to 'Local Catalog' when missing
        $this->assertSame('Local Catalog', $buckets[0]['label']);
    }

    public function testAddVirtualFieldFacetsCatalogWithNoIdNoTitle(): void
    {
        $method = new \ReflectionMethod(PublicationService::class, 'addVirtualFieldFacets');
        $method->setAccessible(true);

        $objectService = $this->createObjectServiceMock();
        $this->mockObjectServiceAvailable($objectService);

        $this->config->method('getValueString')
            ->willReturnMap([
                ['opencatalogi', 'catalog_schema', '', 'schema-1'],
                ['opencatalogi', 'catalog_register', '', 'register-1'],
            ]);

        $catalog = $this->createSerializableObject([
            'description' => 'no id, no title',
        ]);
        $objectService->method('searchObjects')->willReturn([$catalog]);

        $this->directoryService->method('getDirectory')->willReturn(['results' => []]);

        $result = $method->invoke($this->service, [], false, true);

        $buckets = $result['@self']['catalogs']['buckets'];
        // getLocalCatalogs skips catalogs with empty id, so no local catalogs found
        // Default bucket is added when catalogBuckets is empty
        $this->assertSame('default', $buckets[0]['key']);
        $this->assertSame('Default Catalog', $buckets[0]['label']);
    }

    public function testAddVirtualFieldFacetsExternalCatalogsDeduplication(): void
    {
        $method = new \ReflectionMethod(PublicationService::class, 'addVirtualFieldFacets');
        $method->setAccessible(true);

        $objectService = $this->createObjectServiceMock();
        $this->mockObjectServiceAvailable($objectService);

        $this->config->method('getValueString')
            ->willReturnMap([
                ['opencatalogi', 'catalog_schema', '', 'schema-1'],
                ['opencatalogi', 'catalog_register', '', 'register-1'],
            ]);

        $catalog = $this->createSerializableObject([
            'id'    => 'cat-1',
            'title' => 'Test Catalog',
        ]);
        $objectService->method('searchObjects')->willReturn([$catalog]);

        // External catalog has same key as local — should be deduplicated
        $this->directoryService->method('getDirectory')->willReturn([
            'results' => [
                ['catalog' => 'cat-1', 'title' => 'Duplicate Catalog'],
            ],
        ]);

        $result = $method->invoke($this->service, [], false, true);

        $buckets = $result['@self']['catalogs']['buckets'];
        $this->assertCount(1, $buckets);
    }

    // =======================================================================
    // applyCumulativeOrdering — indexed array format
    // =======================================================================

    public function testApplyCumulativeOrderingIndexedArrayFormat(): void
    {
        $method = new \ReflectionMethod(PublicationService::class, 'applyCumulativeOrdering');
        $method->setAccessible(true);

        $results = [
            ['title' => 'B', 'score' => 1],
            ['title' => 'A', 'score' => 2],
            ['title' => 'C', 'score' => 3],
        ];

        // Indexed format: [0 => ['field' => 'direction']]
        $ordered = $method->invoke($this->service, $results, [
            '_order' => [0 => ['score' => 'DESC']],
        ]);

        $this->assertSame(3, $ordered[0]['score']);
        $this->assertSame(2, $ordered[1]['score']);
        $this->assertSame(1, $ordered[2]['score']);
    }

    public function testApplyCumulativeOrderingNonArrayDirection(): void
    {
        $method = new \ReflectionMethod(PublicationService::class, 'applyCumulativeOrdering');
        $method->setAccessible(true);

        $results = [
            ['title' => 'B'],
            ['title' => 'A'],
        ];

        // Non-string direction defaults to ASC — verify no crash and ordering is applied
        $ordered = $method->invoke($this->service, $results, [
            '_order' => ['title' => 123],
        ]);

        // Should contain both results regardless of actual order
        $titles = array_column($ordered, 'title');
        $this->assertCount(2, $titles);
        $this->assertContains('A', $titles);
        $this->assertContains('B', $titles);
    }

    // =======================================================================
    // download — success path
    // =======================================================================

    public function testDownloadReturnsZipOnSuccess(): void
    {
        $fileService = $this->createFileServiceMock();
        $this->mockFileServiceAvailable($fileService);

        // Create a temp file for the test
        $tmpFile = tempnam(sys_get_temp_dir(), 'test_zip_');
        file_put_contents($tmpFile, 'fake zip content');

        $fileService->method('createObjectFilesZip')
            ->willReturn([
                'path'     => $tmpFile,
                'filename' => 'test-files.zip',
                'mimeType' => 'application/zip',
            ]);

        $response = $this->service->download('pub-1');
        $this->assertInstanceOf(DataDownloadResponse::class, $response);
    }

    // =======================================================================
    // attachments — NotFoundException handling
    // =======================================================================

    public function testAttachmentsReturns404OnNotFoundException(): void
    {
        $objectService = $this->createObjectServiceMock();
        $fileService   = $this->createFileServiceMock();

        $this->appManager->method('getInstalledApps')
            ->willReturn(['openregister']);

        $this->container->method('get')
            ->willReturnCallback(function (string $class) use ($objectService, $fileService) {
                if ($class === 'OCA\OpenRegister\Service\ObjectService') {
                    return $objectService;
                }
                if ($class === 'OCA\OpenRegister\Service\FileService') {
                    return $fileService;
                }
                return null;
            });

        $objectService->method('find')->willReturn($this->createSerializableObject(['id' => 'pub-1']));
        $fileService->method('getFiles')
            ->willThrowException(new \OCP\Common\Exception\NotFoundException('Folder not found'));

        $this->request->method('getParams')->willReturn([]);

        $response = $this->service->attachments('pub-1');
        $this->assertSame(404, $response->getStatus());
        $data = json_decode($response->render(), true);
        $this->assertSame('Files folder not found', $data['error']);
    }

    // =======================================================================
    // getFederatedUsed — with timeout params
    // =======================================================================

    public function testGetFederatedUsedWithTimeoutParams(): void
    {
        $objectService = $this->createObjectServiceMock();
        $this->mockObjectServiceAvailable($objectService);

        $objectService->method('findByRelations')->willReturn([]);

        $this->request->method('getParams')->willReturn([]);

        $this->directoryService->expects($this->once())
            ->method('getUsed')
            ->with(
                'pub-1',
                $this->callback(function ($config) {
                    return $config['timeout'] === 10
                        && $config['connect_timeout'] === 5;
                })
            )
            ->willReturn(['results' => [], 'sources' => []]);

        $this->service->getFederatedUsed('pub-1', [
            'timeout'         => '10',
            'connect_timeout' => '5',
        ]);
    }

    // =======================================================================
    // getFederatedUses — with timeout params (code path)
    // =======================================================================

    public function testGetFederatedUsesWithTimeoutParams(): void
    {
        $objectService = $this->createObjectServiceMock();
        $this->mockObjectServiceAvailable($objectService);

        $pubObj = $this->createSerializableObject([
            'id'        => 'pub-1',
            'relations' => [],
        ]);
        $objectService->method('find')->willReturn($pubObj);

        $this->request->method('getParams')->willReturn([]);

        $result = $this->service->getFederatedUses('pub-1', [
            'timeout'         => '10',
            'connect_timeout' => '5',
        ]);

        $this->assertTrue($result['success']);
        $this->assertSame(200, $result['status']);
    }

    // =======================================================================
    // getFederatedPublication — timeout boundary values
    // =======================================================================

    public function testGetFederatedPublicationInvalidTimeoutIgnored(): void
    {
        $objectService = $this->createObjectServiceMock();
        $this->mockObjectServiceAvailable($objectService);

        $objectService->method('find')
            ->willThrowException(new DoesNotExistException('Not found'));

        $this->request->method('getParams')->willReturn([]);

        $this->directoryService->expects($this->once())
            ->method('getPublication')
            ->with(
                'pub-1',
                $this->callback(function ($config) {
                    // Invalid timeout values (over max) should not be set
                    return !isset($config['timeout']) && !isset($config['connect_timeout']);
                })
            )
            ->willReturn([]);

        $this->service->getFederatedPublication('pub-1', [
            'timeout'         => '999',
            'connect_timeout' => '999',
        ]);
    }

    // =======================================================================
    // searchPublications — request param mapping
    // =======================================================================

    public function testSearchPublicationsMapsMultipleParameters(): void
    {
        $objectService = $this->createObjectServiceMock();
        $this->mockObjectServiceAvailable($objectService);

        $this->config->method('getValueString')->willReturn('');

        $catalog = $this->createSerializableObject([
            'registers' => ['reg-1'],
            'schemas'   => ['sch-1'],
        ]);
        $objectService->method('searchObjects')->willReturn([$catalog]);

        $resultObj = $this->createSerializableObject(['@self' => ['id' => 'obj-1']]);
        $objectService->method('searchObjectsPaginated')->willReturn([
            'results' => [$resultObj],
            'total'   => 1,
        ]);

        // Multiple parameters that need mapping
        $this->request->method('getParams')->willReturn([
            'fields' => ['title'],
            'facets' => ['status'],
            'order'  => ['title' => 'ASC'],
            'page'   => 1,
            'limit'  => 10,
        ]);

        $method = new \ReflectionMethod(PublicationService::class, 'searchPublications');
        $method->setAccessible(true);

        $result = $method->invoke($this->service, null, null);
        $this->assertArrayHasKey('results', $result);
    }

    // =======================================================================
    // searchPublications — virtual facets with results
    // =======================================================================

    public function testSearchPublicationsAddsVirtualFacetsWhenRequested(): void
    {
        $objectService = $this->createObjectServiceMock();
        $this->mockObjectServiceAvailable($objectService);

        $this->config->method('getValueString')->willReturn('');

        $catalog = $this->createSerializableObject([
            'registers' => ['reg-1'],
            'schemas'   => ['sch-1'],
        ]);
        $objectService->method('searchObjects')->willReturn([$catalog]);

        $resultObj = $this->createSerializableObject(['@self' => ['id' => 'obj-1']]);
        $objectService->method('searchObjectsPaginated')->willReturn([
            'results' => [$resultObj],
            'total'   => 1,
            'facets'  => ['@self' => ['status' => ['type' => 'terms']]],
        ]);

        $this->directoryService->method('getUniqueDirectories')->willReturn([]);
        $this->directoryService->method('getDirectory')->willReturn(['results' => []]);

        $this->request->method('getParams')->willReturn([
            '_facets' => [
                '@self' => [
                    'directory' => true,
                    'catalogs'  => true,
                    'status'    => true,
                ],
            ],
        ]);

        $method = new \ReflectionMethod(PublicationService::class, 'searchPublications');
        $method->setAccessible(true);

        $result = $method->invoke($this->service, null, null);
        $this->assertArrayHasKey('facets', $result);
        $this->assertArrayHasKey('directory', $result['facets']['@self']);
        $this->assertArrayHasKey('catalogs', $result['facets']['@self']);
    }

    // =======================================================================
    // addVirtualFieldFacets — directory without host
    // =======================================================================

    public function testAddVirtualFieldFacetsDirectoryWithoutHost(): void
    {
        $method = new \ReflectionMethod(PublicationService::class, 'addVirtualFieldFacets');
        $method->setAccessible(true);

        // Return a URL-like string that has no parseable host
        $this->directoryService->method('getUniqueDirectories')
            ->willReturn(['/just-a-path']);

        $result = $method->invoke($this->service, [], true, false);

        $buckets = $result['@self']['directory']['buckets'];
        $this->assertCount(2, $buckets);
        // Second bucket should use the raw URL as name since parse_url returns empty host
        $this->assertSame('/just-a-path', $buckets[1]['key']);
    }

    // =======================================================================
    // getAggregatedPublications — facetable requested but not in response
    // =======================================================================

    public function testGetAggregatedPublicationsWithFacetableRequested(): void
    {
        $objectService = $this->createObjectServiceMock();
        $this->mockObjectServiceAvailable($objectService);

        $this->config->method('getValueString')
            ->willReturnMap([
                ['opencatalogi', 'catalog_schema', '', 'schema-1'],
                ['opencatalogi', 'catalog_register', '', 'register-1'],
            ]);

        $catalog = $this->createSerializableObject([
            'registers' => ['reg-1'],
            'schemas'   => ['sch-1'],
        ]);
        $objectService->method('searchObjects')->willReturn([$catalog]);
        $objectService->method('searchObjectsPaginated')->willReturn([
            'results' => [],
            'total'   => 0,
        ]);

        $this->request->method('getParams')->willReturn([]);

        $this->directoryService->method('getUniqueDirectories')
            ->willReturn(['https://external.example.com']);

        $this->directoryService->method('getPublications')->willReturn([
            'results' => [],
            'total'   => 0,
        ]);

        $result = $this->service->getAggregatedPublications(
            ['_facetable' => 'true'],
            [],
            ''
        );

        $this->assertArrayHasKey('facetable', $result);
    }

    // =======================================================================
    // getAggregatedPublications — nested facets unwrapping in federation
    // =======================================================================

    public function testGetAggregatedPublicationsNestedFacetsUnwrapped(): void
    {
        $objectService = $this->createObjectServiceMock();
        $this->mockObjectServiceAvailable($objectService);

        $this->config->method('getValueString')
            ->willReturnMap([
                ['opencatalogi', 'catalog_schema', '', 'schema-1'],
                ['opencatalogi', 'catalog_register', '', 'register-1'],
            ]);

        $catalog = $this->createSerializableObject([
            'registers' => ['reg-1'],
            'schemas'   => ['sch-1'],
        ]);
        $objectService->method('searchObjects')->willReturn([$catalog]);
        $objectService->method('searchObjectsPaginated')->willReturn([
            'results' => [],
            'total'   => 0,
            'facets'  => ['facets' => ['@self' => ['status' => ['type' => 'terms']]]],
        ]);

        $this->request->method('getParams')->willReturn([]);

        $this->directoryService->method('getUniqueDirectories')
            ->willReturn(['https://external.example.com']);

        $this->directoryService->method('getPublications')->willReturn([
            'results' => [],
            'total'   => 0,
            'facets'  => ['facets' => ['@self' => ['type' => ['type' => 'terms']]]],
        ]);

        $result = $this->service->getAggregatedPublications([], [], '');

        $this->assertArrayHasKey('facets', $result);
    }

    // =======================================================================
    // compareValues — non-date string comparison
    // =======================================================================

    public function testCompareValuesNonDateStrings(): void
    {
        $method = new \ReflectionMethod(PublicationService::class, 'compareValues');
        $method->setAccessible(true);

        // Strings that cannot be parsed as dates
        $result = $method->invoke($this->service, 'abc', 'xyz');
        $this->assertLessThan(0, $result);
    }
}
