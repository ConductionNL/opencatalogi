<?php

declare(strict_types=1);

namespace Unit\Flow\Events;

use OCA\OpenCatalogi\Flow\Events\ListingEvent;
use PHPUnit\Framework\TestCase;

class ListingEventTest extends TestCase
{
    private ListingEvent $event;

    protected function setUp(): void
    {
        parent::setUp();
        $this->event = new ListingEvent();
    }

    public function testGetName(): void
    {
        $this->assertSame('Listing', $this->event->getName());
    }

    public function testGetEvents(): void
    {
        $this->assertSame([], $this->event->getEvents());
    }

    public function testIsLegitimatedForUserId(): void
    {
        $this->assertTrue($this->event->isLegitimatedForUserId('user1'));
    }
}
