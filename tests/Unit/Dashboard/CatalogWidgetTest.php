<?php

declare(strict_types=1);

namespace Unit\Dashboard;

use OCA\OpenCatalogi\Dashboard\CatalogWidget;
use OCP\IL10N;
use OCP\IURLGenerator;
use PHPUnit\Framework\TestCase;

class CatalogWidgetTest extends TestCase
{
    private CatalogWidget $widget;
    private IL10N $l10n;
    private IURLGenerator $url;

    protected function setUp(): void
    {
        parent::setUp();
        $this->l10n = $this->createMock(IL10N::class);
        $this->url = $this->createMock(IURLGenerator::class);

        $this->l10n->method('t')->willReturnCallback(fn ($text) => $text);

        $this->widget = new CatalogWidget($this->l10n, $this->url);
    }

    public function testGetId(): void
    {
        $this->assertSame('opencatalogi_catalogi_widget', $this->widget->getId());
    }

    public function testGetTitle(): void
    {
        $this->assertSame('Catalogi Overview', $this->widget->getTitle());
    }

    public function testGetOrder(): void
    {
        $this->assertSame(10, $this->widget->getOrder());
    }

    public function testGetIconClass(): void
    {
        $this->assertSame('icon-catalogi-widget', $this->widget->getIconClass());
    }

    public function testGetUrl(): void
    {
        $this->assertNull($this->widget->getUrl());
    }

    public function testLoadDoesNotThrow(): void
    {
        // load() calls Util::addScript and Util::addStyle (static methods).
        // In the Nextcloud test environment, these are available.
        $this->widget->load();

        // If we reach here, no exception was thrown.
        $this->assertTrue(true);
    }
}
