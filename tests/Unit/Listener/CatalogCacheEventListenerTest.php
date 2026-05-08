<?php

declare(strict_types=1);

namespace Unit\Listener;

use OCA\OpenCatalogi\Listener\CatalogCacheEventListener;
use OCA\OpenCatalogi\Service\CatalogiService;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Event\ObjectCreatedEvent;
use OCA\OpenRegister\Event\ObjectUpdatedEvent;
use OCA\OpenRegister\Event\ObjectDeletedEvent;
use OCP\EventDispatcher\Event;
use OCP\IAppConfig;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Tests for CatalogCacheEventListener.
 */
class CatalogCacheEventListenerTest extends TestCase
{
    private CatalogCacheEventListener $listener;

    protected function setUp(): void
    {
        parent::setUp();
        $this->listener = new CatalogCacheEventListener();
    }

    /**
     * Create a mock ObjectEntity with magic method support.
     */
    private function createObjectEntityMock(
        string $schema = 'cat-schema',
        string $register = 'cat-reg',
        string $uuid = 'test-uuid',
        ?string $slug = 'test-slug'
    ): ObjectEntity&MockObject {
        $entity = $this->getMockBuilder(ObjectEntity::class)
            ->disableOriginalConstructor()
            ->addMethods(['getUuid', 'getRegister', 'getSchema'])
            ->onlyMethods(['jsonSerialize'])
            ->getMock();

        $entity->method('getSchema')->willReturn($schema);
        $entity->method('getRegister')->willReturn($register);
        $entity->method('getUuid')->willReturn($uuid);
        $serializeData = [];
        if ($slug !== null) {
            $serializeData['slug'] = $slug;
        }
        $entity->method('jsonSerialize')->willReturn($serializeData);
        return $entity;
    }

    /**
     * Register mock services in the DI container.
     */
    private function registerMockServices(
        CatalogiService $catalogiService,
        IAppConfig $appConfig,
        LoggerInterface $logger
    ): void {
        \OC::$server->registerService(CatalogiService::class, fn() => $catalogiService);
        \OC::$server->registerService(IAppConfig::class, fn() => $appConfig);
        \OC::$server->registerService(LoggerInterface::class, fn() => $logger);
    }

    /**
     * Create a mock IAppConfig.
     */
    private function createMockAppConfig(string $catalogSchema = 'cat-schema', string $catalogRegister = 'cat-reg'): IAppConfig&MockObject
    {
        $appConfig = $this->createMock(IAppConfig::class);
        $appConfig->method('getValueString')
            ->willReturnCallback(function (string $app, string $key, string $default) use ($catalogSchema, $catalogRegister) {
                if ($key === 'catalog_schema') {
                    return $catalogSchema;
                }
                if ($key === 'catalog_register') {
                    return $catalogRegister;
                }
                return $default;
            });
        return $appConfig;
    }

    public function testHandleIgnoresUnsupportedEvent(): void
    {
        $event = $this->createMock(Event::class);
        $this->listener->handle($event);
        $this->assertTrue(true);
    }

    public function testHandleWarmsUpCacheOnObjectCreated(): void
    {
        $catalogiService = $this->createMock(CatalogiService::class);
        $catalogiService->expects($this->once())
            ->method('warmupCatalogCache')
            ->with('my-catalog');

        $appConfig = $this->createMockAppConfig('cat-schema', 'cat-reg');
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())
            ->method('info')
            ->with($this->stringContains('Catalog cache warmed up after creation'));

        $this->registerMockServices($catalogiService, $appConfig, $logger);

        $entity = $this->createObjectEntityMock('cat-schema', 'cat-reg', 'uuid-1', 'my-catalog');
        $event = new ObjectCreatedEvent($entity);
        $this->listener->handle($event);
    }

    public function testHandleWarmsUpCacheOnObjectUpdated(): void
    {
        $catalogiService = $this->createMock(CatalogiService::class);
        $catalogiService->expects($this->once())
            ->method('warmupCatalogCache')
            ->with('updated-catalog');

        $appConfig = $this->createMockAppConfig('cat-schema', 'cat-reg');
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())
            ->method('info')
            ->with($this->stringContains('Catalog cache warmed up after update'));

        $this->registerMockServices($catalogiService, $appConfig, $logger);

        $newEntity = $this->createObjectEntityMock('cat-schema', 'cat-reg', 'uuid-2', 'updated-catalog');
        $event = new ObjectUpdatedEvent($newEntity, null);
        $this->listener->handle($event);
    }

    public function testHandleInvalidatesCacheOnObjectDeleted(): void
    {
        $catalogiService = $this->createMock(CatalogiService::class);
        $catalogiService->expects($this->once())
            ->method('invalidateCatalogCache')
            ->with('deleted-catalog');

        $appConfig = $this->createMockAppConfig('cat-schema', 'cat-reg');
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())
            ->method('info')
            ->with($this->stringContains('Catalog cache invalidated after deletion'));

        $this->registerMockServices($catalogiService, $appConfig, $logger);

        $entity = $this->createObjectEntityMock('cat-schema', 'cat-reg', 'uuid-3', 'deleted-catalog');
        $event = new ObjectDeletedEvent($entity);
        $this->listener->handle($event);
    }

    public function testHandleIgnoresNonCatalogObjectDifferentSchema(): void
    {
        $catalogiService = $this->createMock(CatalogiService::class);
        $catalogiService->expects($this->never())->method('warmupCatalogCache');
        $catalogiService->expects($this->never())->method('invalidateCatalogCache');

        $appConfig = $this->createMockAppConfig('cat-schema', 'cat-reg');
        $logger = $this->createMock(LoggerInterface::class);

        $this->registerMockServices($catalogiService, $appConfig, $logger);

        $entity = $this->createObjectEntityMock('other-schema', 'cat-reg', 'uuid-4', 'some-slug');
        $event = new ObjectCreatedEvent($entity);
        $this->listener->handle($event);
        $this->assertTrue(true);
    }

    public function testHandleIgnoresNonCatalogObjectDifferentRegister(): void
    {
        $catalogiService = $this->createMock(CatalogiService::class);
        $catalogiService->expects($this->never())->method('warmupCatalogCache');
        $catalogiService->expects($this->never())->method('invalidateCatalogCache');

        $appConfig = $this->createMockAppConfig('cat-schema', 'cat-reg');
        $logger = $this->createMock(LoggerInterface::class);

        $this->registerMockServices($catalogiService, $appConfig, $logger);

        $entity = $this->createObjectEntityMock('cat-schema', 'other-reg', 'uuid-5', 'some-slug');
        $event = new ObjectCreatedEvent($entity);
        $this->listener->handle($event);
        $this->assertTrue(true);
    }

    public function testHandleSkipsCacheWhenNoSlug(): void
    {
        $catalogiService = $this->createMock(CatalogiService::class);
        $catalogiService->expects($this->never())->method('warmupCatalogCache');
        $catalogiService->expects($this->never())->method('invalidateCatalogCache');

        $appConfig = $this->createMockAppConfig('cat-schema', 'cat-reg');
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->never())->method('info');

        $this->registerMockServices($catalogiService, $appConfig, $logger);

        $entity = $this->createObjectEntityMock('cat-schema', 'cat-reg', 'uuid-6', null);
        $event = new ObjectCreatedEvent($entity);
        $this->listener->handle($event);
        $this->assertTrue(true);
    }

    public function testHandleDeletionWithNoSlug(): void
    {
        $catalogiService = $this->createMock(CatalogiService::class);
        $catalogiService->expects($this->never())->method('invalidateCatalogCache');

        $appConfig = $this->createMockAppConfig('cat-schema', 'cat-reg');
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->never())->method('info');

        $this->registerMockServices($catalogiService, $appConfig, $logger);

        $entity = $this->createObjectEntityMock('cat-schema', 'cat-reg', 'uuid-7', null);
        $event = new ObjectDeletedEvent($entity);
        $this->listener->handle($event);
        $this->assertTrue(true);
    }

    public function testHandleCatchesExceptionAndLogs(): void
    {
        $catalogiService = $this->createMock(CatalogiService::class);
        $appConfig = $this->createMock(IAppConfig::class);
        $appConfig->method('getValueString')
            ->willThrowException(new \RuntimeException('Config broken'));

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())
            ->method('error')
            ->with($this->stringContains('Exception in catalog cache event listener'));

        $this->registerMockServices($catalogiService, $appConfig, $logger);

        $entity = $this->createObjectEntityMock();
        $event = new ObjectCreatedEvent($entity);
        $this->listener->handle($event);
    }

    public function testHandleCatchesExceptionWhenLoggerNotInitialized(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())
            ->method('error')
            ->with($this->stringContains('Exception in catalog cache event listener'));

        \OC::$server->registerService(CatalogiService::class, function () {
            throw new \RuntimeException('Service unavailable');
        });
        \OC::$server->registerService(LoggerInterface::class, fn() => $logger);

        $entity = $this->createObjectEntityMock();
        $event = new ObjectCreatedEvent($entity);
        $this->listener->handle($event);
    }
}
