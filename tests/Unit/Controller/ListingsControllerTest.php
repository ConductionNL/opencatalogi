<?php

declare(strict_types=1);

namespace Unit\Controller;

use OCA\OpenCatalogi\Controller\ListingsController;
use OCA\OpenCatalogi\Service\DirectoryService;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IAppConfig;
use OCP\IL10N;
use OCP\IRequest;
use OCP\App\IAppManager;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use RuntimeException;

/**
 * Unit tests for ListingsController.
 */
class ListingsControllerTest extends TestCase
{

    private IRequest|MockObject $request;
    private IAppConfig|MockObject $config;
    private ContainerInterface|MockObject $container;
    private IAppManager|MockObject $appManager;
    private DirectoryService|MockObject $directoryService;
    private IL10N|MockObject $l10n;
    private ListingsController $controller;

    protected function setUp(): void
    {
        $this->request          = $this->createMock(IRequest::class);
        $this->config           = $this->createMock(IAppConfig::class);
        $this->container        = $this->createMock(ContainerInterface::class);
        $this->appManager       = $this->createMock(IAppManager::class);
        $this->directoryService = $this->createMock(DirectoryService::class);
        $this->l10n             = $this->createMock(IL10N::class);

        $this->l10n->method('t')
            ->willReturnCallback(fn(string $text) => $text);

        $this->controller = new ListingsController(
            'opencatalogi',
            $this->request,
            $this->config,
            $this->container,
            $this->appManager,
            $this->directoryService,
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

    public function testIndexReturnsJsonResponse(): void
    {
        $mockObjService = $this->mockObjectService();

        $mockObjService->method('searchObjectsPaginated')
            ->willReturn(['results' => [], 'total' => 0]);

        $this->config->method('getValueString')
            ->willReturn('');

        $this->request->method('getParams')
            ->willReturn([]);

        $response = $this->controller->index();

        $this->assertInstanceOf(JSONResponse::class, $response);
        $this->assertEquals(200, $response->getStatus());
    }

    public function testIndexWithFilters(): void
    {
        $mockObjService = $this->mockObjectService();

        $mockObjService->method('searchObjectsPaginated')
            ->willReturn(['results' => [['id' => 1]], 'total' => 1]);

        $this->config->method('getValueString')
            ->willReturnMap([
                ['opencatalogi', 'listing_schema', '', '5'],
                ['opencatalogi', 'listing_register', '', '3'],
            ]);

        $this->request->method('getParams')
            ->willReturn(['filters' => ['status' => 'active'], 'limit' => 10, 'offset' => 0]);

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

        $this->expectException(RuntimeException::class);

        $this->controller->index();
    }

    public function testShowReturnsListingData(): void
    {
        $mockObjService = $this->mockObjectService();

        $mockEntity = $this->createMock(\OCA\OpenRegister\Db\ObjectEntity::class);
        $mockEntity->method('jsonSerialize')
            ->willReturn(['id' => '123', 'title' => 'Test Listing']);

        $mockObjService->method('find')
            ->willReturn($mockEntity);

        $this->config->method('getValueString')
            ->willReturn('');

        $response = $this->controller->show('123');

        $this->assertInstanceOf(JSONResponse::class, $response);
        $this->assertEquals(200, $response->getStatus());
    }

    public function testShowWithIntegerId(): void
    {
        $mockObjService = $this->mockObjectService();

        $mockEntity = $this->createMock(\OCA\OpenRegister\Db\ObjectEntity::class);
        $mockEntity->method('jsonSerialize')
            ->willReturn(['id' => 42]);

        $mockObjService->method('find')
            ->willReturn($mockEntity);

        $this->config->method('getValueString')
            ->willReturn('');

        $response = $this->controller->show(42);

        $this->assertInstanceOf(JSONResponse::class, $response);
    }

    public function testCreateReturnsNewListing(): void
    {
        $mockObjService = $this->mockObjectService();

        $mockEntity = $this->createMock(\OCA\OpenRegister\Db\ObjectEntity::class);
        $mockObjService->method('saveObject')
            ->willReturn($mockEntity);

        $this->config->method('getValueString')
            ->willReturn('');

        $this->request->method('getParams')
            ->willReturn(['title' => 'New Listing']);

        $response = $this->controller->create();

        $this->assertInstanceOf(JSONResponse::class, $response);
        $this->assertEquals(200, $response->getStatus());
    }

    public function testUpdateReturnsUpdatedListing(): void
    {
        $mockObjService = $this->mockObjectService();

        $mockEntity = $this->createMock(\OCA\OpenRegister\Db\ObjectEntity::class);
        $mockObjService->method('saveObject')
            ->willReturn($mockEntity);

        $this->config->method('getValueString')
            ->willReturn('');

        $this->request->method('getParams')
            ->willReturn(['title' => 'Updated Listing']);

        $response = $this->controller->update('123');

        $this->assertInstanceOf(JSONResponse::class, $response);
        $this->assertEquals(200, $response->getStatus());
    }

    public function testDestroyReturnsSuccessOnDeletion(): void
    {
        $mockObjService = $this->mockObjectService();

        $mockObjService->method('deleteObject')
            ->with('123')
            ->willReturn(true);

        $response = $this->controller->destroy('123');

        $this->assertInstanceOf(JSONResponse::class, $response);
        $this->assertEquals(200, $response->getStatus());
    }

    public function testDestroyReturns404WhenNotFound(): void
    {
        $mockObjService = $this->mockObjectService();

        $mockObjService->method('deleteObject')
            ->with('nonexistent')
            ->willReturn(false);

        $response = $this->controller->destroy('nonexistent');

        $this->assertInstanceOf(JSONResponse::class, $response);
        $this->assertEquals(404, $response->getStatus());
    }

    public function testSynchroniseAllDirectories(): void
    {
        $this->directoryService->method('doCronSync')
            ->willReturn(['synced' => 5, 'errors' => 0]);

        $response = $this->controller->synchronise(null);

        $this->assertInstanceOf(JSONResponse::class, $response);
        $this->assertEquals(200, $response->getStatus());
    }

    public function testSynchroniseReturns500OnException(): void
    {
        $this->directoryService->method('doCronSync')
            ->willThrowException(new \Exception('Sync failed'));

        $response = $this->controller->synchronise(null);

        $this->assertInstanceOf(JSONResponse::class, $response);
        $this->assertEquals(500, $response->getStatus());
    }

    public function testAddReturns400WhenNoUrl(): void
    {
        $this->request->method('getParam')
            ->with('url')
            ->willReturn(null);

        $response = $this->controller->add();

        $this->assertInstanceOf(JSONResponse::class, $response);
        $this->assertEquals(400, $response->getStatus());
    }

    public function testAddReturns400WhenEmptyUrl(): void
    {
        $this->request->method('getParam')
            ->with('url')
            ->willReturn('');

        $response = $this->controller->add();

        $this->assertInstanceOf(JSONResponse::class, $response);
        $this->assertEquals(400, $response->getStatus());
    }

    public function testAddReturnsSuccessWithValidUrl(): void
    {
        $this->request->method('getParam')
            ->with('url')
            ->willReturn('https://example.com/directory');

        $this->directoryService->method('syncDirectory')
            ->with('https://example.com/directory')
            ->willReturn(['synced' => 3]);

        $response = $this->controller->add();

        $this->assertInstanceOf(JSONResponse::class, $response);
        $this->assertEquals(200, $response->getStatus());
    }

    public function testAddReturns400OnInvalidArgument(): void
    {
        $this->request->method('getParam')
            ->with('url')
            ->willReturn('invalid-url');

        $this->directoryService->method('syncDirectory')
            ->willThrowException(new \InvalidArgumentException('Invalid URL'));

        $response = $this->controller->add();

        $this->assertInstanceOf(JSONResponse::class, $response);
        $this->assertEquals(400, $response->getStatus());
    }

    public function testAddReturns500OnGenericException(): void
    {
        $this->request->method('getParam')
            ->with('url')
            ->willReturn('https://example.com/dir');

        $this->directoryService->method('syncDirectory')
            ->willThrowException(new \Exception('Server error'));

        $response = $this->controller->add();

        $this->assertInstanceOf(JSONResponse::class, $response);
        $this->assertEquals(500, $response->getStatus());
    }
}
