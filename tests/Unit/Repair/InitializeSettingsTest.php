<?php

declare(strict_types=1);

namespace Unit\Repair;

use OCA\OpenCatalogi\Repair\InitializeSettings;
use OCA\OpenCatalogi\Service\SettingsService;
use OCP\App\IAppManager;
use OCP\IConfig;
use OCP\Migration\IOutput;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;

class InitializeSettingsTest extends TestCase
{
    private InitializeSettings $repairStep;
    private IConfig $config;
    private IAppManager $appManager;
    private ContainerInterface $container;

    protected function setUp(): void
    {
        parent::setUp();
        $this->config = $this->createMock(IConfig::class);
        $this->appManager = $this->createMock(IAppManager::class);
        $this->container = $this->createMock(ContainerInterface::class);

        $this->repairStep = new InitializeSettings(
            $this->config,
            $this->appManager,
            $this->container
        );
    }

    public function testGetName(): void
    {
        $this->assertSame('Initialize OpenCatalogi settings', $this->repairStep->getName());
    }

    public function testRunWhenOpenRegisterNotInstalled(): void
    {
        $output = $this->createMock(IOutput::class);

        $this->appManager->method('getInstalledApps')
            ->willReturn(['files', 'calendar']);

        $output->expects($this->once())->method('startProgress')->with(2);
        $output->expects($this->once())->method('warning')
            ->with($this->stringContains('OpenRegister app is not installed'));
        $output->expects($this->once())->method('finishProgress');

        $this->repairStep->run($output);
    }

    public function testRunWhenOpenRegisterInstalled(): void
    {
        $output = $this->createMock(IOutput::class);
        $settingsService = $this->createMock(SettingsService::class);

        $this->appManager->method('getInstalledApps')
            ->willReturn(['openregister', 'opencatalogi']);

        $this->container->method('get')
            ->with(SettingsService::class)
            ->willReturn($settingsService);

        $settingsService->method('loadSettings')
            ->willReturn([
                'registers' => [1, 2],
                'schemas'   => [1, 2, 3],
                'objects'   => [],
            ]);

        $output->expects($this->once())->method('startProgress')->with(2);
        $output->expects($this->exactly(2))->method('info');
        $output->expects($this->once())->method('finishProgress');

        $this->repairStep->run($output);
    }

    public function testRunHandlesExceptionGracefully(): void
    {
        $output = $this->createMock(IOutput::class);
        $settingsService = $this->createMock(SettingsService::class);

        $this->appManager->method('getInstalledApps')
            ->willReturn(['openregister']);

        $this->container->method('get')
            ->willReturn($settingsService);

        $settingsService->method('loadSettings')
            ->willThrowException(new \RuntimeException('Config file missing'));

        $output->expects($this->once())->method('warning')
            ->with($this->stringContains('Failed to load configuration'));

        $this->repairStep->run($output);
    }
}
