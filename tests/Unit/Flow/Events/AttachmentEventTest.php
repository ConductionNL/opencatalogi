<?php

declare(strict_types=1);

namespace Unit\Flow\Events;

use OCA\OpenCatalogi\Flow\Events\AttachmentEvent;
use OCP\EventDispatcher\Event;
use OCP\WorkflowEngine\IRuleMatcher;
use PHPUnit\Framework\TestCase;

class AttachmentEventTest extends TestCase
{
    private AttachmentEvent $event;

    protected function setUp(): void
    {
        parent::setUp();
        $this->event = new AttachmentEvent();
    }

    public function testGetName(): void
    {
        $this->assertSame('Attachment', $this->event->getName());
    }

    public function testGetEvents(): void
    {
        $this->assertSame([], $this->event->getEvents());
    }

    public function testIsLegitimatedForUserId(): void
    {
        $this->assertTrue($this->event->isLegitimatedForUserId('admin'));
        $this->assertTrue($this->event->isLegitimatedForUserId('guest'));
    }

    public function testGetIconReturnsString(): void
    {
        $icon = $this->event->getIcon();
        $this->assertIsString($icon);
        $this->assertNotEmpty($icon);
    }

    public function testPrepareRuleMatcherDoesNotThrow(): void
    {
        $ruleMatcher = $this->createMock(IRuleMatcher::class);
        $mockEvent = $this->createMock(Event::class);

        $this->event->prepareRuleMatcher($ruleMatcher, 'test.event', $mockEvent);

        // No-op method - verify it does not throw.
        $this->assertTrue(true);
    }
}
