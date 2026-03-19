<?php

declare(strict_types=1);

namespace Unit\Listener;

use OCA\OpenCatalogi\Listener\CatalogCacheEventListener;
use OCP\EventDispatcher\Event;
use PHPUnit\Framework\TestCase;

/**
 * Tests for CatalogCacheEventListener.
 *
 * The handle() method uses \OC::$server static calls internally,
 * so full integration testing requires the Nextcloud container.
 * Unit tests focus on the event type guard.
 */
class CatalogCacheEventListenerTest extends TestCase
{
    private CatalogCacheEventListener $listener;

    protected function setUp(): void
    {
        parent::setUp();
        $this->listener = new CatalogCacheEventListener();
    }

    public function testHandleIgnoresUnsupportedEvent(): void
    {
        $event = $this->createMock(Event::class);

        // Should return early without accessing \OC::$server.
        $this->listener->handle($event);
        $this->assertTrue(true);
    }
}
