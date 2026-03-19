<?php

declare(strict_types=1);

namespace Unit\Flow\Events;

use OCA\OpenCatalogi\Flow\Events\AttachmentEvent;
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
}
