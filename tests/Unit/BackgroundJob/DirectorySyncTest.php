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

	public function testIntervalIsSetTo1Hour(): void {
		$reflection = new \ReflectionClass($this->job);
		$prop = $reflection->getProperty('interval');
		$prop->setAccessible(true);

		$this->assertSame(3600, $prop->getValue($this->job));
	}
}
