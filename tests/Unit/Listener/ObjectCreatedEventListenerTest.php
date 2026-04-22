<?php

declare(strict_types=1);

namespace Unit\Listener;

use OCA\OpenCatalogi\Listener\ObjectCreatedEventListener;
use OCA\OpenCatalogi\Service\EventService;
use OCA\OpenCatalogi\Service\SettingsService;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Event\ObjectCreatedEvent;
use OCP\EventDispatcher\Event;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Tests for ObjectCreatedEventListener.
 *
 * Uses \OC::$server to register mock services, then calls handle() to test
 * the full handle() logic including auto-publishing and error branches.
 */
class ObjectCreatedEventListenerTest extends TestCase
{
    private ObjectCreatedEventListener $listener;

    protected function setUp(): void
    {
        parent::setUp();
        $this->listener = new ObjectCreatedEventListener();
    }

    /**
     * Create a mock ObjectEntity with magic method support.
     * ObjectEntity uses __call for getters (getUuid, getRegister, etc.),
     * so we must use addMethods for all magic-method getters.
     */
    private function createObjectEntityMock(
        string $uuid = 'test-uuid',
        string $register = 'reg-1',
        string $schema = 'schema-1',
        ?\DateTime $published = null,
        ?\DateTime $depublished = null,
        array $jsonData = []
    ): ObjectEntity&MockObject {
        $entity = $this->getMockBuilder(ObjectEntity::class)
            ->disableOriginalConstructor()
            ->addMethods(['getUuid', 'getRegister', 'getSchema', 'getPublished', 'getDepublished'])
            ->onlyMethods(['jsonSerialize'])
            ->getMock();

        $entity->method('jsonSerialize')->willReturn($jsonData);
        $entity->method('getUuid')->willReturn($uuid);
        $entity->method('getRegister')->willReturn($register);
        $entity->method('getSchema')->willReturn($schema);
        $entity->method('getPublished')->willReturn($published);
        $entity->method('getDepublished')->willReturn($depublished);

        return $entity;
    }

    public function testHandleIgnoresNonObjectCreatedEvent(): void
    {
        $event = $this->createMock(Event::class);

        // Should return early without accessing \OC::$server.
        $this->listener->handle($event);
        $this->assertTrue(true);
    }

    /**
     * Test convertObjectEntityToArray via reflection.
     */
    public function testConvertObjectEntityToArrayViaReflection(): void
    {
        $entity = $this->createObjectEntityMock(
            uuid: 'test-uuid-123',
            register: 'reg-1',
            schema: 'schema-1',
            jsonData: [
                'title' => 'Test Object',
                '@self' => ['existingKey' => 'existingValue'],
            ]
        );

        $method = new \ReflectionMethod(ObjectCreatedEventListener::class, 'convertObjectEntityToArray');
        $method->setAccessible(true);

        $result = $method->invoke($this->listener, $entity);

        $this->assertIsArray($result);
        $this->assertSame('test-uuid-123', $result['@self']['id']);
        $this->assertSame('test-uuid-123', $result['@self']['uuid']);
        $this->assertSame('reg-1', $result['@self']['register']);
        $this->assertSame('schema-1', $result['@self']['schema']);
        $this->assertNull($result['@self']['published']);
        $this->assertNull($result['@self']['depublished']);
        $this->assertSame('Test Object', $result['title']);
    }

    /**
     * Test convertObjectEntityToArray when jsonSerialize does not include @self.
     */
    public function testConvertObjectEntityToArrayWithoutSelfMetadata(): void
    {
        $entity = $this->createObjectEntityMock(
            uuid: 'uuid-no-self',
            register: 'reg-2',
            schema: 'schema-2',
            jsonData: ['title' => 'No Self']
        );

        $method = new \ReflectionMethod(ObjectCreatedEventListener::class, 'convertObjectEntityToArray');
        $method->setAccessible(true);

        $result = $method->invoke($this->listener, $entity);

        $this->assertArrayHasKey('@self', $result);
        $this->assertSame('uuid-no-self', $result['@self']['id']);
    }

    /**
     * Test convertObjectEntityToArray with published and depublished dates.
     */
    public function testConvertObjectEntityToArrayWithDates(): void
    {
        $published = new \DateTime('2025-06-01T12:00:00+00:00');
        $depublished = new \DateTime('2025-12-01T12:00:00+00:00');

        $entity = $this->createObjectEntityMock(
            uuid: 'uuid-dated',
            register: 'reg-3',
            schema: 'schema-3',
            published: $published,
            depublished: $depublished
        );

        $method = new \ReflectionMethod(ObjectCreatedEventListener::class, 'convertObjectEntityToArray');
        $method->setAccessible(true);

        $result = $method->invoke($this->listener, $entity);

        $this->assertSame($published->format('c'), $result['@self']['published']);
        $this->assertSame($depublished->format('c'), $result['@self']['depublished']);
    }

    /**
     * Test handle() when auto-publishing is disabled (both options false).
     */
    public function testHandleReturnsEarlyWhenAutoPublishingDisabled(): void
    {
        $settingsService = $this->createMock(SettingsService::class);
        $settingsService->method('getPublishingOptions')->willReturn([
            'auto_publish_objects'     => false,
            'auto_publish_attachments' => false,
        ]);

        $eventService = $this->createMock(EventService::class);
        $eventService->expects($this->never())->method('handleObjectCreateEvents');

        $logger = $this->createMock(LoggerInterface::class);

        \OC::$server->registerService(SettingsService::class, fn() => $settingsService);
        \OC::$server->registerService(EventService::class, fn() => $eventService);
        \OC::$server->registerService(LoggerInterface::class, fn() => $logger);

        $entity = $this->createObjectEntityMock();
        $event = new ObjectCreatedEvent($entity);
        $this->listener->handle($event);

        $this->assertTrue(true);
    }

    /**
     * Test handle() when auto-publish objects is enabled and processing succeeds.
     */
    public function testHandleProcessesObjectWhenAutoPublishEnabled(): void
    {
        $settingsService = $this->createMock(SettingsService::class);
        $settingsService->method('getPublishingOptions')->willReturn([
            'auto_publish_objects'     => true,
            'auto_publish_attachments' => false,
        ]);

        $eventService = $this->createMock(EventService::class);
        $eventService->expects($this->once())
            ->method('handleObjectCreateEvents')
            ->willReturn([
                'processed'            => 1,
                'published'            => 1,
                'attachmentsPublished' => 0,
                'errors'               => [],
            ]);

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())
            ->method('info')
            ->with($this->stringContains('Processed object creation event'));

        \OC::$server->registerService(SettingsService::class, fn() => $settingsService);
        \OC::$server->registerService(EventService::class, fn() => $eventService);
        \OC::$server->registerService(LoggerInterface::class, fn() => $logger);

        $entity = $this->createObjectEntityMock(
            uuid: 'pub-uuid',
            published: new \DateTime()
        );

        $event = new ObjectCreatedEvent($entity);
        $this->listener->handle($event);
    }

    /**
     * Test handle() when processing returns errors.
     */
    public function testHandleLogsErrorsFromEventService(): void
    {
        $settingsService = $this->createMock(SettingsService::class);
        $settingsService->method('getPublishingOptions')->willReturn([
            'auto_publish_objects'     => true,
            'auto_publish_attachments' => false,
        ]);

        $eventService = $this->createMock(EventService::class);
        $eventService->method('handleObjectCreateEvents')
            ->willReturn([
                'processed'            => 0,
                'published'            => 0,
                'attachmentsPublished' => 0,
                'errors'               => ['Something went wrong'],
            ]);

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())
            ->method('error')
            ->with($this->stringContains('Error processing object creation event'));

        \OC::$server->registerService(SettingsService::class, fn() => $settingsService);
        \OC::$server->registerService(EventService::class, fn() => $eventService);
        \OC::$server->registerService(LoggerInterface::class, fn() => $logger);

        $entity = $this->createObjectEntityMock(uuid: 'err-uuid');
        $event = new ObjectCreatedEvent($entity);
        $this->listener->handle($event);
    }

    /**
     * Test handle() when an exception is thrown during processing.
     */
    public function testHandleCatchesExceptionAndLogs(): void
    {
        $settingsService = $this->createMock(SettingsService::class);
        $settingsService->method('getPublishingOptions')
            ->willThrowException(new \RuntimeException('Settings broken'));

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())
            ->method('error')
            ->with($this->stringContains('Exception in object creation event listener'));

        \OC::$server->registerService(SettingsService::class, fn() => $settingsService);
        \OC::$server->registerService(LoggerInterface::class, fn() => $logger);

        $entity = $this->createObjectEntityMock();
        $event = new ObjectCreatedEvent($entity);
        $this->listener->handle($event);
    }

    /**
     * Test handle() when processed is 0 but no errors (info should not be logged).
     */
    public function testHandleDoesNotLogInfoWhenNothingProcessed(): void
    {
        $settingsService = $this->createMock(SettingsService::class);
        $settingsService->method('getPublishingOptions')->willReturn([
            'auto_publish_objects'     => true,
            'auto_publish_attachments' => false,
        ]);

        $eventService = $this->createMock(EventService::class);
        $eventService->method('handleObjectCreateEvents')
            ->willReturn([
                'processed'            => 0,
                'published'            => 0,
                'attachmentsPublished' => 0,
                'errors'               => [],
            ]);

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->never())->method('info');
        $logger->expects($this->never())->method('error');

        \OC::$server->registerService(SettingsService::class, fn() => $settingsService);
        \OC::$server->registerService(EventService::class, fn() => $eventService);
        \OC::$server->registerService(LoggerInterface::class, fn() => $logger);

        $entity = $this->createObjectEntityMock(uuid: 'no-proc-uuid');
        $event = new ObjectCreatedEvent($entity);
        $this->listener->handle($event);
    }
}
