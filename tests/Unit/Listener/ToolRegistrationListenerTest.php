<?php

declare(strict_types=1);

namespace Unit\Listener;

use OCA\OpenCatalogi\Listener\ToolRegistrationListener;
use OCA\OpenCatalogi\Tool\CMSTool;
use OCA\OpenRegister\Event\ToolRegistrationEvent;
use OCA\OpenRegister\Service\ToolRegistry;
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

    /**
     * Test handle() registers the CMS tool when a ToolRegistrationEvent is received.
     */
    public function testHandleRegistersToolOnToolRegistrationEvent(): void
    {
        $this->cmsTool->method('getName')->willReturn('CMS Tool');
        $this->cmsTool->method('getDescription')->willReturn('Manage CMS content');

        // Create a mock ToolRegistry.
        $registry = $this->createMock(ToolRegistry::class);
        $registry->expects($this->once())
            ->method('registerTool')
            ->with(
                'opencatalogi.cms',
                $this->cmsTool,
                $this->callback(function (array $metadata) {
                    return $metadata['name'] === 'CMS Tool'
                        && $metadata['description'] === 'Manage CMS content'
                        && $metadata['icon'] === 'icon-category-office'
                        && $metadata['app'] === 'opencatalogi';
                })
            );

        $event = new ToolRegistrationEvent($registry);
        $this->listener->handle($event);
    }

    /**
     * Test that the listener passes correct metadata from the CMS tool.
     */
    public function testHandlePassesCorrectMetadata(): void
    {
        $this->cmsTool->method('getName')->willReturn('My CMS');
        $this->cmsTool->method('getDescription')->willReturn('My CMS Description');

        $registry = $this->createMock(ToolRegistry::class);
        $registry->expects($this->once())
            ->method('registerTool')
            ->with(
                'opencatalogi.cms',
                $this->cmsTool,
                [
                    'name'        => 'My CMS',
                    'description' => 'My CMS Description',
                    'icon'        => 'icon-category-office',
                    'app'         => 'opencatalogi',
                ]
            );

        $event = new ToolRegistrationEvent($registry);
        $this->listener->handle($event);
    }

    /**
     * Test that handle is idempotent - calling multiple times registers each time.
     */
    public function testHandleCanBeCalledMultipleTimes(): void
    {
        $this->cmsTool->method('getName')->willReturn('CMS');
        $this->cmsTool->method('getDescription')->willReturn('Desc');

        $registry = $this->createMock(ToolRegistry::class);
        $registry->expects($this->exactly(2))->method('registerTool');

        $event = new ToolRegistrationEvent($registry);
        $this->listener->handle($event);
        $this->listener->handle($event);
    }
}
