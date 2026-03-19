<?php

declare(strict_types=1);

namespace Unit\Flow\Events;

use OCA\OpenCatalogi\Flow\Events\CatalogEvent;
use OCP\EventDispatcher\Event;
use OCP\WorkflowEngine\IRuleMatcher;
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

        $this->assertTrue(true);
    }
}
