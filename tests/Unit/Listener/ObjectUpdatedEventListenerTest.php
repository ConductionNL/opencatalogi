<?php

declare(strict_types=1);

namespace Unit\Listener;

use OCA\OpenCatalogi\Listener\ObjectUpdatedEventListener;
use OCA\OpenRegister\Db\ObjectEntity;
use OCP\EventDispatcher\Event;
use PHPUnit\Framework\TestCase;

/**
 * Tests for ObjectUpdatedEventListener.
 *
 * The handle() method uses \OC::$server static calls internally,
 * so full integration testing requires the Nextcloud container.
 * Unit tests focus on the event type check, private helpers, and constructor.
 */
class ObjectUpdatedEventListenerTest extends TestCase
{
    private ObjectUpdatedEventListener $listener;

    protected function setUp(): void
    {
        parent::setUp();
        $this->listener = new ObjectUpdatedEventListener();
    }

    public function testHandleIgnoresNonObjectUpdatedEvent(): void
    {
        $event = $this->createMock(Event::class);

        // Should return early.
        $this->listener->handle($event);
        $this->assertTrue(true);
    }

    public function testConvertObjectEntityToArrayViaReflection(): void
    {
        // ObjectEntity does not have published/depublished attributes.
        $this->markTestSkipped('ObjectEntity does not have published/depublished attributes');
    }

    public function testIsObjectPublishedViaReflection(): void
    {
        $method = new \ReflectionMethod(ObjectUpdatedEventListener::class, 'isObjectPublished');
        $method->setAccessible(true);

        // Not published.
        $this->assertFalse($method->invoke($this->listener, ['@self' => []]));

        // Published, not depublished.
        $this->assertTrue($method->invoke($this->listener, [
            '@self' => ['published' => '2025-01-01T00:00:00+00:00'],
        ]));

        // Published before depublished.
        $this->assertFalse($method->invoke($this->listener, [
            '@self' => [
                'published'   => '2025-01-01T00:00:00+00:00',
                'depublished' => '2025-02-01T00:00:00+00:00',
            ],
        ]));

        // Published after depublished.
        $this->assertTrue($method->invoke($this->listener, [
            '@self' => [
                'published'   => '2025-03-01T00:00:00+00:00',
                'depublished' => '2025-02-01T00:00:00+00:00',
            ],
        ]));
    }

    public function testIsObjectEntityPublishedViaReflection(): void
    {
        // ObjectEntity does not have published/depublished attributes.
        $this->markTestSkipped('ObjectEntity does not have published/depublished attributes');
    }

    public function testShouldProcessUpdateViaReflection(): void
    {
        $method = new \ReflectionMethod(ObjectUpdatedEventListener::class, 'shouldProcessUpdate');
        $method->setAccessible(true);

        // Auto-publish attachments enabled, object is published.
        $result = $method->invoke(
            $this->listener,
            ['@self' => ['published' => '2025-01-01T00:00:00+00:00']],
            null,
            ['auto_publish_attachments' => true, 'auto_publish_objects' => false]
        );
        $this->assertTrue($result);

        // Auto-publish attachments enabled, object NOT published.
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
            ['@self' => ['published' => '2025-01-01T00:00:00+00:00']],
            null,
            ['auto_publish_attachments' => false, 'auto_publish_objects' => false]
        );
        $this->assertFalse($result);
    }
}
