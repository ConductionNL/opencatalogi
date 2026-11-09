<?php

declare(strict_types=1);

namespace Unit\BackgroundJob;

use OCA\OpenCatalogi\BackgroundJob\DirectorySync;
use OCA\OpenCatalogi\Service\DirectoryService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;

class DirectorySyncTest extends TestCase {
	private DirectorySync $job;
	private DirectoryService $directoryService;
	private ITimeFactory $timeFactory;
	private IAppConfig $config;

	protected function setUp(): void {
		parent::setUp();
		$this->timeFactory = $this->createMock(ITimeFactory::class);
		$this->directoryService = $this->createMock(DirectoryService::class);
		// Third constructor argument, added when the sync interval became
		// configurable. The test still built the job with two and every case in
		// this file died with ArgumentCountError before reaching its assertion.
		$this->config = $this->createMock(IAppConfig::class);
		$this->config->method('getValueInt')
			->willReturnCallback(
				static fn (string $app, string $key, int $default = 0): int => $default
			);

		$this->job = new DirectorySync(
			$this->timeFactory,
			$this->directoryService,
			$this->config
		);
	}

	public function testRunCallsDoCronSync(): void {
		$this->directoryService->expects($this->once())
			->method('doCronSync');

		$method = new \ReflectionMethod(DirectorySync::class, 'run');
		$method->setAccessible(true);
		$method->invoke($this->job, []);
	}

	/**
	 * oc-f6: with OpenRegister present the cron sync runs inside its system context,
	 * so the listing writes are not refused as anonymous.
	 */
	public function testRunSyncsInsideTheOpenRegisterSystemContext(): void {
		$inSystemContext = false;
		$objectService = $this->createMock(\OCA\OpenRegister\Service\ObjectService::class);
		$objectService->expects($this->once())
			->method('runAsSystem')
			->willReturnCallback(
				static function (callable $operation) use (&$inSystemContext) {
					$inSystemContext = true;
					try {
						return $operation();
					} finally {
						$inSystemContext = false;
					}
				}
			);

		$container = $this->createMock(\Psr\Container\ContainerInterface::class);
		$container->method('get')->with('OCA\OpenRegister\Service\ObjectService')->willReturn($objectService);
		$appManager = $this->createMock(\OCP\App\IAppManager::class);
		$appManager->method('getInstalledApps')->willReturn(['openregister', 'opencatalogi']);

		$ranInSystemContext = null;
		$this->directoryService->expects($this->once())
			->method('doCronSync')
			->willReturnCallback(
				static function () use (&$inSystemContext, &$ranInSystemContext): array {
					$ranInSystemContext = $inSystemContext;
					return [];
				}
			);

		$job = new DirectorySync($this->timeFactory, $this->directoryService, $this->config, $container, $appManager);
		$method = new \ReflectionMethod(DirectorySync::class, 'run');
		$method->invoke($job, []);

		$this->assertTrue($ranInSystemContext);
	}

	/**
	 * oc-f6: without OpenRegister the job still runs the sync, unelevated.
	 */
	public function testRunWithoutOpenRegisterSyncsWithoutSystemContext(): void {
		$container = $this->createMock(\Psr\Container\ContainerInterface::class);
		$container->expects($this->never())->method('get');
		$appManager = $this->createMock(\OCP\App\IAppManager::class);
		$appManager->method('getInstalledApps')->willReturn(['opencatalogi']);

		$this->directoryService->expects($this->once())->method('doCronSync');

		$job = new DirectorySync($this->timeFactory, $this->directoryService, $this->config, $container, $appManager);
		$method = new \ReflectionMethod(DirectorySync::class, 'run');
		$method->invoke($job, []);
	}

	public function testIntervalIsSetTo1Hour(): void {
		$reflection = new \ReflectionClass($this->job);
		$prop = $reflection->getProperty('interval');
		$prop->setAccessible(true);

		$this->assertSame(3600, $prop->getValue($this->job));
	}
}
