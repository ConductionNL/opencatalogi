<?php

declare(strict_types=1);

namespace Unit\Settings;

use OCA\OpenCatalogi\Settings\OpenCatalogiAdmin;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\IConfig;
use OCP\IL10N;
use PHPUnit\Framework\TestCase;

class OpenCatalogiAdminTest extends TestCase
{
    private OpenCatalogiAdmin $settings;
    private IConfig $config;
    private IL10N $l10n;

    protected function setUp(): void
    {
        parent::setUp();
        $this->config = $this->createMock(IConfig::class);
        $this->l10n = $this->createMock(IL10N::class);

        $this->settings = new OpenCatalogiAdmin($this->config, $this->l10n);
    }

    public function testGetForm(): void
    {
        $this->config->method('getSystemValue')
            ->with('open_catalogi_setting', true)
            ->willReturn(true);

        $response = $this->settings->getForm();

        $this->assertInstanceOf(TemplateResponse::class, $response);
    }

    public function testGetSection(): void
    {
        $this->assertSame('opencatalogi', $this->settings->getSection());
    }

    public function testGetPriority(): void
    {
        $this->assertSame(10, $this->settings->getPriority());
    }
}
