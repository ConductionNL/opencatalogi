<?php

declare(strict_types=1);

namespace Unit\Flow\Events;

use OCA\OpenCatalogi\Flow\Events\PublicationEvent;
use PHPUnit\Framework\TestCase;

class PublicationEventTest extends TestCase
{
    private PublicationEvent $event;

    protected function setUp(): void
    {
        parent::setUp();
        $this->event = new PublicationEvent();
    }

    public function testGetName(): void
    {
        $this->assertSame('Publication', $this->event->getName());
    }

    public function testGetEvents(): void
    {
        $this->assertSame([], $this->event->getEvents());
    }

    public function testIsLegitimatedForUserId(): void
    {
        $this->assertTrue($this->event->isLegitimatedForUserId('admin'));
    }
}
