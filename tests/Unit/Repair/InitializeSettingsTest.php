<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace Unit\Repair;

use OCA\OpenCatalogi\BackgroundJob\FinishUpgradeAfterOpenRegister;
use OCA\OpenCatalogi\Contract\SettingsServiceInterface;
use OCA\OpenCatalogi\Repair\InitializeSettings;
use OCA\OpenCatalogi\Service\SettingsService;
use OCP\App\IAppManager;
use OCP\BackgroundJob\IJobList;
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
			->with(SettingsServiceInterface::class)
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

	/**
	 * A configured instance and a settings service whose import call is recorded in `$order`.
	 *
	 * @param list<string> $order The calls in the order they happen.
	 * @param IJobList     $jobs  The job list the step may queue on.
	 *
	 * @return void
	 */
	private function importRecordedIn(array &$order, IJobList $jobs): void {
		$settingsService = $this->createMock(originalClassName: SettingsService::class);
		$settingsService->method('loadSettings')->willReturnCallback(
			function () use (&$order): array {
				$order[] = 'import';
				return ['registers' => [1], 'schemas' => [1], 'objects' => []];
			}
		);
		$this->appManager->method('getInstalledApps')->willReturn(['openregister', 'opencatalogi']);
		$this->container->method('get')->willReturnCallback(
			static fn (string $id) => match ($id) {
				IJobList::class => $jobs,
				default => $settingsService,
			}
		);
		$this->config->method('getValueString')->willReturn('15');
	}

	/**
	 * OpenRegister waiting for its own upgrade defers the import to a queued job (REQ-PLC-007).
	 *
	 * `occ upgrade` takes opencatalogi before openregister; importing now would land
	 * on OpenRegister's old tables and leave the new schemas out for good.
	 *
	 * @spec openspec/changes/publication-lifecycle-on-or/specs/publications/spec.md#requirement-existing-publications-get-a-stored-state-on-upgrade-req-plc-007
	 *
	 * @return void
	 */
	public function testOpenRegisterWaitingForItsUpgradeDefersTheImportToAJob(): void {
		$order = [];
		$jobs = $this->createMock(originalClassName: IJobList::class);
		$jobs->expects($this->once())->method('add')->with(FinishUpgradeAfterOpenRegister::class);
		$this->importRecordedIn(order: $order, jobs: $jobs);
		$this->appManager->method('isUpgradeRequired')->with('openregister')->willReturn(true);
		$output = $this->createMock(originalClassName: IOutput::class);
		$output->expects($this->once())->method('warning')->with($this->stringContains('run cron to finish the upgrade'));

		$this->repairStep->run(output: $output);

		$this->assertSame(expected: [], actual: $order);
	}

	/**
	 * A current OpenRegister imports at once and queues nothing.
	 *
	 * @spec openspec/changes/publication-lifecycle-on-or/specs/publications/spec.md#requirement-existing-publications-get-a-stored-state-on-upgrade-req-plc-007
	 *
	 * @return void
	 */
	public function testACurrentOpenRegisterImportsAtOnce(): void {
		$order = [];
		$jobs = $this->createMock(originalClassName: IJobList::class);
		$jobs->expects($this->never())->method('add');
		$this->importRecordedIn(order: $order, jobs: $jobs);
		$this->appManager->method('isUpgradeRequired')->willReturn(false);

		$this->repairStep->run(output: $this->createMock(originalClassName: IOutput::class));

		$this->assertSame(expected: ['import'], actual: $order);
	}

	/**
	 * When the job cannot be queued, the import runs as before rather than not at all.
	 *
	 * @spec openspec/changes/publication-lifecycle-on-or/specs/publications/spec.md#requirement-existing-publications-get-a-stored-state-on-upgrade-req-plc-007
	 *
	 * @return void
	 */
	public function testAJobThatCannotBeQueuedLeavesTheImportRunning(): void {
		$order = [];
		$jobs = $this->createMock(originalClassName: IJobList::class);
		$jobs->method('add')->willThrowException(new \RuntimeException('job list unavailable'));
		$this->importRecordedIn(order: $order, jobs: $jobs);
		$this->appManager->method('isUpgradeRequired')->willReturn(true);

		$this->repairStep->run(output: $this->createMock(originalClassName: IOutput::class));

		$this->assertSame(expected: ['import'], actual: $order);
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
