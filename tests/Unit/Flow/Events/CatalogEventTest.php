<?php

declare(strict_types=1);

namespace Unit\Flow\Events;

use OCA\OpenCatalogi\Flow\Events\CatalogEvent;
use PHPUnit\Framework\TestCase;

class CatalogEventTest extends TestCase
{
    private CatalogEvent $event;

    protected function setUp(): void
    {
        parent::setUp();
        $this->event = new CatalogEvent();
    }

    public function testGetName(): void
    {
        $this->assertSame('Catalog', $this->event->getName());
    }

    public function testGetEvents(): void
    {
        $this->assertSame([], $this->event->getEvents());
    }

    public function testIsLegitimatedForUserId(): void
    {
        $this->assertTrue($this->event->isLegitimatedForUserId('admin'));
        $this->assertTrue($this->event->isLegitimatedForUserId(''));
    }
}
