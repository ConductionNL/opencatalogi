<?php

declare(strict_types=1);

namespace Unit\Repair;

use OCA\OpenCatalogi\Repair\InitializeSettings;
use OCA\OpenCatalogi\Service\SettingsService;
use OCP\App\IAppManager;
use OCP\IAppConfig;
use OCP\Migration\IOutput;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

class InitializeSettingsTest extends TestCase {
	private InitializeSettings $repairStep;
	private IAppManager $appManager;
	private ContainerInterface $container;
	private IAppConfig $config;
	private LoggerInterface $logger;

	protected function setUp(): void {
		parent::setUp();
		$this->appManager = $this->createMock(IAppManager::class);
		$this->container = $this->createMock(ContainerInterface::class);
		$this->config = $this->createMock(IAppConfig::class);
		$this->logger = $this->createMock(LoggerInterface::class);

		$this->repairStep = new InitializeSettings(
			$this->appManager,
			$this->container,
			$this->config,
			$this->logger
		);
	}

	public function testGetName(): void {
		$this->assertSame('Initialize OpenCatalogi settings', $this->repairStep->getName());
	}

	public function testRunWhenOpenRegisterNotInstalled(): void {
		$output = $this->createMock(IOutput::class);

		$this->appManager->method('getInstalledApps')
			->willReturn(['files', 'calendar']);

		$output->expects($this->once())->method('startProgress')->with(2);
		$output->expects($this->once())->method('warning')
			->with($this->stringContains('OpenRegister app is not installed'));
		$output->expects($this->once())->method('finishProgress');

		$this->repairStep->run($output);
	}

	public function testRunWhenOpenRegisterInstalled(): void {
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
				'schemas' => [1, 2, 3],
				'objects' => [],
			]);

		// A SUCCESSFUL IMPORT IS ONE THAT LEFT THE CONFIGURATION BEHIND.
		// The step now verifies its own outcome, so this mock has to represent a
		// real success: `loadSettings()` returning a well-formed result is not
		// sufficient, because a version-skipped import returns exactly that while
		// writing nothing.
		$this->config->method('getValueString')
			->willReturn('15');

		$output->expects($this->once())->method('startProgress')->with(2);
		$output->expects($this->exactly(2))->method('info');
		$output->expects($this->never())->method('warning');
		$output->expects($this->once())->method('finishProgress');

		$this->repairStep->run($output);
	}

	/**
	 * An import that reports success but writes nothing must not pass silently.
	 *
	 * This is the failure the outcome check exists for: `loadSettings()` returns
	 * a well-formed result describing zero work — which is what a version-skipped
	 * import does — and nothing else in the step would notice.
	 */
	public function testRunWarnsWhenTheImportReportedSuccessButWroteNothing(): void {
		$output = $this->createMock(IOutput::class);
		$settingsService = $this->createMock(SettingsService::class);

		$this->appManager->method('getInstalledApps')
			->willReturn(['openregister', 'opencatalogi']);
		$this->container->method('get')->willReturn($settingsService);
		$settingsService->method('loadSettings')->willReturn(
			['registers' => [], 'schemas' => [], 'objects' => []]
		);

		// The configuration key the step exists to write is still empty.
		$this->config->method('getValueString')->willReturn('');

		$output->expects($this->once())->method('warning')
			->with($this->stringContains('did not land'));

		$this->repairStep->run($output);
	}

	public function testRunHandlesExceptionGracefully(): void {
		$output = $this->createMock(IOutput::class);
		$settingsService = $this->createMock(SettingsService::class);

		$this->appManager->method('getInstalledApps')
			->willReturn(['openregister']);

		$this->container->method('get')
			->willReturn($settingsService);

		$settingsService->method('loadSettings')
			->willThrowException(new \RuntimeException('Config file missing'));

		// TWO warnings now: the throw, then the outcome check finding nothing was
		// written. The second is the point — a hard failure and a silent no-op
		// both have to end in a visible statement that the app has no registers.
		$warnings = [];
		$output->method('warning')->willReturnCallback(
			static function (string $message) use (&$warnings): void {
				$warnings[] = $message;
			}
		);

		$this->repairStep->run($output);

		$this->assertCount(2, $warnings);
		$this->assertStringContainsString('Failed to load configuration', $warnings[0]);
		$this->assertStringContainsString('did not land', $warnings[1]);
	}
}
