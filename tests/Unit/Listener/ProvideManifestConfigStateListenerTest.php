<?php

declare(strict_types=1);

namespace Unit\Listener;

use OCA\OpenCatalogi\Listener\ProvideManifestConfigStateListener;
use OCP\AppFramework\Http\Events\BeforeTemplateRenderedEvent;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\AppFramework\Services\IInitialState;
use OCP\EventDispatcher\Event;
use OCP\IAppConfig;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Tests for ProvideManifestConfigStateListener.
 *
 * Verifies the manifest-config initial state is provided for the OpenCatalogi
 * SPA `index` render (regardless of controller) and never for anything else.
 */
class ProvideManifestConfigStateListenerTest extends TestCase
{
    private IAppConfig&MockObject $appConfig;

    private IInitialState&MockObject $initialState;

    private ProvideManifestConfigStateListener $listener;

    /**
     * Captured key/value pairs passed to provideInitialState().
     *
     * @var array<string, mixed>
     */
    private array $provided = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->appConfig    = $this->createMock(IAppConfig::class);
        $this->initialState = $this->createMock(IInitialState::class);
        $this->initialState->method('provideInitialState')->willReturnCallback(
            function (string $key, $data): void {
                $this->provided[$key] = $data;
            }
        );
        $this->listener = new ProvideManifestConfigStateListener(
            $this->appConfig,
            $this->initialState
        );
    }

    public function testProvidesConfigStateForOpencatalogiIndex(): void
    {
        // Non-empty for every register/schema key; the directory falls back to its default.
        $this->appConfig->method('getValueString')->willReturnCallback(
            function (string $app, string $key, string $default = ''): string {
                return $key === 'default_directory_url' ? $default : '42';
            }
        );

        $this->listener->handle(
            new BeforeTemplateRenderedEvent(true, new TemplateResponse('opencatalogi', 'index'))
        );

        // 16 register/schema keys + default_directory_url.
        $this->assertCount(17, $this->provided);
        $this->assertSame('42', $this->provided['catalog_register']);
        $this->assertSame('42', $this->provided['publication_schema']);
        $this->assertArrayHasKey('default_directory_url', $this->provided);
        $this->assertNotSame('', $this->provided['default_directory_url']);
    }

    public function testSkipsEmptyConfigValues(): void
    {
        // Only catalog_register is configured; every other key is empty.
        $this->appConfig->method('getValueString')->willReturnCallback(
            function (string $app, string $key, string $default = ''): string {
                if ($key === 'catalog_register') {
                    return '18';
                }

                return $key === 'default_directory_url' ? $default : '';
            }
        );

        $this->listener->handle(
            new BeforeTemplateRenderedEvent(true, new TemplateResponse('opencatalogi', 'index'))
        );

        // Only the one non-empty key + default_directory_url are provided.
        $this->assertCount(2, $this->provided);
        $this->assertSame('18', $this->provided['catalog_register']);
        $this->assertArrayNotHasKey('catalog_schema', $this->provided);
        $this->assertArrayHasKey('default_directory_url', $this->provided);
    }

    public function testIgnoresNonIndexTemplate(): void
    {
        $this->listener->handle(
            new BeforeTemplateRenderedEvent(true, new TemplateResponse('opencatalogi', 'error'))
        );

        $this->assertSame([], $this->provided);
    }

    public function testIgnoresOtherApp(): void
    {
        $this->listener->handle(
            new BeforeTemplateRenderedEvent(true, new TemplateResponse('openregister', 'index'))
        );

        $this->assertSame([], $this->provided);
    }

    public function testIgnoresUnrelatedEvent(): void
    {
        $this->listener->handle(new Event());

        $this->assertSame([], $this->provided);
    }
}
