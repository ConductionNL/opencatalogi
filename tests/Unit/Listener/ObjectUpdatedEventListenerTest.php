<?php

declare(strict_types=1);

namespace Unit\Listener;

use OCA\OpenCatalogi\Listener\ObjectUpdatedEventListener;
use OCA\OpenCatalogi\Service\EventService;
use OCA\OpenCatalogi\Service\SettingsService;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Event\ObjectUpdatedEvent;
use OCP\EventDispatcher\Event;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Tests for ObjectUpdatedEventListener.
 */
class ObjectUpdatedEventListenerTest extends TestCase
{
    private ObjectUpdatedEventListener $listener;

    protected function setUp(): void
    {
        parent::setUp();
        $this->listener = new ObjectUpdatedEventListener();
    }

    /**
     * Create a mock ObjectEntity. All getters are magic (__call) based.
     */
    private function createObjectEntityMock(
        string $uuid = 'test-uuid',
        string $register = 'reg-1',
        string $schema = 'schema-1',
        ?\DateTime $published = null,
        ?\DateTime $depublished = null,
        array $jsonData = []
    ): ObjectEntity&MockObject {
        // The removed object-level @self.published predicate is gone from OR core,
        // so the listener no longer calls getPublished()/getDepublished(). Visibility
        // is governed by the object's own publicatiedatum/depublicatiedatum fields,
        // which arrive via jsonSerialize().
        if ($published !== null) {
            $jsonData['publicatiedatum'] = $published->format('c');
        }

        if ($depublished !== null) {
            $jsonData['depublicatiedatum'] = $depublished->format('c');
        }

        $entity = $this->getMockBuilder(ObjectEntity::class)
            ->disableOriginalConstructor()
            ->addMethods(['getUuid', 'getRegister', 'getSchema'])
            ->onlyMethods(['jsonSerialize'])
            ->getMock();

        $entity->method('jsonSerialize')->willReturn($jsonData);
        $entity->method('getUuid')->willReturn($uuid);
        $entity->method('getRegister')->willReturn($register);
        $entity->method('getSchema')->willReturn($schema);

        return $entity;
    }

    public function testHandleIgnoresNonObjectUpdatedEvent(): void
    {
        $event = $this->createMock(Event::class);

        // Should return early without accessing \OC::$server.
        $this->listener->handle($event);
        $this->assertTrue(true);
    }

    // -------------------------------------------------------------------------
    // isObjectPublished (private)
    // -------------------------------------------------------------------------

    public function testIsObjectPublishedViaReflection(): void
    {
        $method = new \ReflectionMethod(ObjectUpdatedEventListener::class, 'isObjectPublished');
        $method->setAccessible(true);

        $future = (new \DateTime('+10 days'))->format(\DateTimeInterface::ATOM);

        // Not published (no publicatiedatum).
        $this->assertFalse($method->invoke($this->listener, []));
        $this->assertFalse($method->invoke($this->listener, ['@self' => []]));

        // Past publicatiedatum, no depublicatiedatum -> published.
        $this->assertTrue($method->invoke($this->listener, [
            'publicatiedatum' => '2025-01-01T00:00:00+00:00',
        ]));

        // Future publicatiedatum (embargo) -> not yet published.
        $this->assertFalse($method->invoke($this->listener, [
            'publicatiedatum' => $future,
        ]));

        // Past publicatiedatum but depublicatiedatum already passed -> depublished.
        $this->assertFalse($method->invoke($this->listener, [
            'publicatiedatum'   => '2025-01-01T00:00:00+00:00',
            'depublicatiedatum' => '2025-02-01T00:00:00+00:00',
        ]));

        // Past publicatiedatum with a future depublicatiedatum -> still published.
        $this->assertTrue($method->invoke($this->listener, [
            'publicatiedatum'   => '2025-01-01T00:00:00+00:00',
            'depublicatiedatum' => $future,
        ]));
    }

    // -------------------------------------------------------------------------
    // isObjectEntityPublished (private)
    // -------------------------------------------------------------------------

    public function testIsObjectEntityPublishedViaReflection(): void
    {
        $method = new \ReflectionMethod(ObjectUpdatedEventListener::class, 'isObjectEntityPublished');
        $method->setAccessible(true);

        // Not published (no publicatiedatum).
        $entity1 = $this->createObjectEntityMock();
        $this->assertFalse($method->invoke($this->listener, $entity1));

        // Past publicatiedatum, no depublicatiedatum -> published.
        $entity2 = $this->createObjectEntityMock(published: new \DateTime('2025-01-01'));
        $this->assertTrue($method->invoke($this->listener, $entity2));

        // Past publicatiedatum, depublicatiedatum already passed -> depublished.
        $entity3 = $this->createObjectEntityMock(
            published: new \DateTime('2025-01-01'),
            depublished: new \DateTime('2025-06-01')
        );
        $this->assertFalse($method->invoke($this->listener, $entity3));

        // Past publicatiedatum with a future depublicatiedatum -> still published.
        $entity4 = $this->createObjectEntityMock(
            published: new \DateTime('2025-01-01'),
            depublished: new \DateTime('+10 days')
        );
        $this->assertTrue($method->invoke($this->listener, $entity4));
    }

    // -------------------------------------------------------------------------
    // shouldProcessUpdate (private)
    // -------------------------------------------------------------------------

    public function testShouldProcessUpdateViaReflection(): void
    {
        $method = new \ReflectionMethod(ObjectUpdatedEventListener::class, 'shouldProcessUpdate');
        $method->setAccessible(true);

        // Auto-publish attachments enabled, object is published (past publicatiedatum).
        $result = $method->invoke(
            $this->listener,
            ['publicatiedatum' => '2025-01-01T00:00:00+00:00'],
            null,
            ['auto_publish_attachments' => true, 'auto_publish_objects' => false]
        );
        $this->assertTrue($result);

        // Auto-publish attachments enabled, object NOT published (no publicatiedatum).
        $result = $method->invoke(
            $this->listener,
            ['@self' => []],
            null,
            ['auto_publish_attachments' => true, 'auto_publish_objects' => false]
        );
        $this->assertFalse($result);

        // Nothing enabled.
        $result = $method->invoke(
            $this->listener,
            ['publicatiedatum' => '2025-01-01T00:00:00+00:00'],
            null,
            ['auto_publish_attachments' => false, 'auto_publish_objects' => false]
        );
        $this->assertFalse($result);

        // Auto-publish objects enabled, object became published (old was unpublished).
        $oldEntity = $this->createObjectEntityMock();
        $result = $method->invoke(
            $this->listener,
            ['publicatiedatum' => '2025-01-01T00:00:00+00:00'],
            $oldEntity,
            ['auto_publish_attachments' => false, 'auto_publish_objects' => true]
        );
        $this->assertTrue($result);

        // Auto-publish objects enabled, object was already published.
        $oldEntityPub = $this->createObjectEntityMock(published: new \DateTime('2024-01-01'));
        $result = $method->invoke(
            $this->listener,
            ['publicatiedatum' => '2025-01-01T00:00:00+00:00'],
            $oldEntityPub,
            ['auto_publish_attachments' => false, 'auto_publish_objects' => true]
        );
        $this->assertFalse($result);

        // Auto-publish objects enabled, no old entity, object not published.
        $result = $method->invoke(
            $this->listener,
            ['@self' => []],
            null,
            ['auto_publish_attachments' => false, 'auto_publish_objects' => true]
        );
        $this->assertFalse($result);
    }

    // -------------------------------------------------------------------------
    // convertObjectEntityToArray (private)
    // -------------------------------------------------------------------------

    public function testConvertObjectEntityToArrayViaReflection(): void
    {
        $method = new \ReflectionMethod(ObjectUpdatedEventListener::class, 'convertObjectEntityToArray');
        $method->setAccessible(true);

        $published = new \DateTime('2025-06-01T12:00:00+00:00');

        $entity = $this->createObjectEntityMock(
            uuid: 'upd-uuid',
            register: 'reg-u',
            schema: 'schema-u',
            published: $published,
            jsonData: ['title' => 'Updated']
        );

        $result = $method->invoke($this->listener, $entity);

        $this->assertSame('upd-uuid', $result['@self']['id']);
        $this->assertSame('upd-uuid', $result['@self']['uuid']);
        $this->assertSame('reg-u', $result['@self']['register']);
        $this->assertSame('schema-u', $result['@self']['schema']);
        // Visibility now travels on the object's own publicatiedatum field; the dead
        // @self.published envelope key is no longer set.
        $this->assertSame($published->format('c'), $result['publicatiedatum']);
        $this->assertArrayNotHasKey('published', $result['@self']);
        $this->assertSame([], $result['@self']['files']);
    }

    public function testConvertObjectEntityToArrayWithoutSelf(): void
    {
        $method = new \ReflectionMethod(ObjectUpdatedEventListener::class, 'convertObjectEntityToArray');
        $method->setAccessible(true);

        $entity = $this->createObjectEntityMock(
            uuid: 'no-self-uuid',
            jsonData: ['data' => 'val']
        );

        $result = $method->invoke($this->listener, $entity);

        $this->assertArrayHasKey('@self', $result);
        $this->assertSame('no-self-uuid', $result['@self']['id']);
    }

    // -------------------------------------------------------------------------
    // handle() integration tests via \OC::$server
    // -------------------------------------------------------------------------

    public function testHandleReturnsEarlyWhenAutoPublishingDisabled(): void
    {
        $settingsService = $this->createMock(SettingsService::class);
        $settingsService->method('getPublishingOptions')->willReturn([
            'auto_publish_objects'     => false,
            'auto_publish_attachments' => false,
        ]);

        $eventService = $this->createMock(EventService::class);
        $eventService->expects($this->never())->method('handleObjectUpdateEvents');

        $logger = $this->createMock(LoggerInterface::class);

        \OC::$server->registerService(SettingsService::class, fn() => $settingsService);
        \OC::$server->registerService(EventService::class, fn() => $eventService);
        \OC::$server->registerService(LoggerInterface::class, fn() => $logger);

        $newEntity = $this->createObjectEntityMock();
        $event = new ObjectUpdatedEvent($newEntity, null);
        $this->listener->handle($event);
        $this->assertTrue(true);
    }

    public function testHandleProcessesObjectWhenAutoPublishEnabled(): void
    {
        $settingsService = $this->createMock(SettingsService::class);
        $settingsService->method('getPublishingOptions')->willReturn([
            'auto_publish_objects'     => true,
            'auto_publish_attachments' => false,
        ]);

        $eventService = $this->createMock(EventService::class);
        $eventService->expects($this->once())
            ->method('handleObjectUpdateEvents')
            ->willReturn([
                'processed'            => 1,
                'published'            => 1,
                'attachmentsPublished' => 0,
                'errors'               => [],
            ]);

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())->method('info')
            ->with($this->stringContains('Processed object update event'));

        \OC::$server->registerService(SettingsService::class, fn() => $settingsService);
        \OC::$server->registerService(EventService::class, fn() => $eventService);
        \OC::$server->registerService(LoggerInterface::class, fn() => $logger);

        $newEntity = $this->createObjectEntityMock(
            uuid: 'new-uuid',
            published: new \DateTime('2025-06-01')
        );
        $oldEntity = $this->createObjectEntityMock();

        $event = new ObjectUpdatedEvent($newEntity, $oldEntity);
        $this->listener->handle($event);
    }

    public function testHandleLogsErrorsFromEventService(): void
    {
        $settingsService = $this->createMock(SettingsService::class);
        $settingsService->method('getPublishingOptions')->willReturn([
            'auto_publish_objects'     => false,
            'auto_publish_attachments' => true,
        ]);

        $eventService = $this->createMock(EventService::class);
        $eventService->method('handleObjectUpdateEvents')
            ->willReturn([
                'processed'            => 0,
                'published'            => 0,
                'attachmentsPublished' => 0,
                'errors'               => ['Attachment error occurred'],
            ]);

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->atLeastOnce())->method('error')
            ->with($this->stringContains('Error processing object update event'));

        \OC::$server->registerService(SettingsService::class, fn() => $settingsService);
        \OC::$server->registerService(EventService::class, fn() => $eventService);
        \OC::$server->registerService(LoggerInterface::class, fn() => $logger);

        $newEntity = $this->createObjectEntityMock(
            uuid: 'err-uuid',
            published: new \DateTime('2025-01-01')
        );

        $event = new ObjectUpdatedEvent($newEntity, null);
        $this->listener->handle($event);
    }

    public function testHandleSkipsProcessingWhenShouldProcessUpdateReturnsFalse(): void
    {
        $settingsService = $this->createMock(SettingsService::class);
        $settingsService->method('getPublishingOptions')->willReturn([
            'auto_publish_objects'     => true,
            'auto_publish_attachments' => false,
        ]);

        $eventService = $this->createMock(EventService::class);
        $eventService->expects($this->never())->method('handleObjectUpdateEvents');

        $logger = $this->createMock(LoggerInterface::class);

        \OC::$server->registerService(SettingsService::class, fn() => $settingsService);
        \OC::$server->registerService(EventService::class, fn() => $eventService);
        \OC::$server->registerService(LoggerInterface::class, fn() => $logger);

        // Both old and new are published -- no status change.
        $newEntity = $this->createObjectEntityMock(
            uuid: 'skip-uuid',
            published: new \DateTime('2025-06-01')
        );
        $oldEntity = $this->createObjectEntityMock(
            published: new \DateTime('2025-01-01')
        );

        $event = new ObjectUpdatedEvent($newEntity, $oldEntity);
        $this->listener->handle($event);
        $this->assertTrue(true);
    }

    public function testHandleCatchesExceptionAndLogs(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->atLeastOnce())->method('error')
            ->with($this->stringContains('Exception in object update event listener'));

        $settingsService = $this->createMock(SettingsService::class);
        $settingsService->method('getPublishingOptions')
            ->willThrowException(new \RuntimeException('Settings broken'));

        \OC::$server->registerService(SettingsService::class, fn() => $settingsService);
        \OC::$server->registerService(LoggerInterface::class, fn() => $logger);

        $newEntity = $this->createObjectEntityMock();
        $event = new ObjectUpdatedEvent($newEntity, null);
        $this->listener->handle($event);
    }
}
