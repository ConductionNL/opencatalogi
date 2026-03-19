<?php

declare(strict_types=1);

namespace Unit\Listener;

use OCA\OpenCatalogi\Listener\ToolRegistrationListener;
use OCA\OpenCatalogi\Tool\CMSTool;
use OCP\EventDispatcher\Event;
use PHPUnit\Framework\TestCase;

class ToolRegistrationListenerTest extends TestCase
{
    private ToolRegistrationListener $listener;
    private CMSTool $cmsTool;

    protected function setUp(): void
    {
        parent::setUp();
        $this->cmsTool = $this->createMock(CMSTool::class);
        $this->listener = new ToolRegistrationListener($this->cmsTool);
    }

    public function testHandleIgnoresNonToolRegistrationEvent(): void
    {
        $event = $this->createMock(Event::class);

        // Should not throw, just return silently.
        $this->listener->handle($event);
        $this->assertTrue(true);
    }
}
