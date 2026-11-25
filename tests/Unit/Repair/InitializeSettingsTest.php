<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace Unit\Repair;

use OCA\OpenCatalogi\Contract\SettingsServiceInterface;
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
	 *
	 * @return void
	 */
	private function importRecordedIn(array &$order): void {
		$settingsService = $this->createMock(originalClassName: SettingsService::class);
		$settingsService->method('loadSettings')->willReturnCallback(
			function () use (&$order): array {
				$order[] = 'import';
				return ['registers' => [1], 'schemas' => [1], 'objects' => []];
			}
		);
		$this->appManager->method('getInstalledApps')->willReturn(['openregister', 'opencatalogi']);
		$this->container->method('get')->willReturn($settingsService);
		$this->config->method('getValueString')->willReturn('15');
	}

	/**
	 * OpenRegister waiting for its own upgrade is upgraded before the import (REQ-PLC-007).
	 *
	 * `occ upgrade` takes opencatalogi before openregister; importing first would
	 * land on OpenRegister's old tables and leave the new schemas out for good.
	 *
	 * @spec openspec/changes/publication-lifecycle-on-or/specs/publications/spec.md#requirement-existing-publications-get-a-stored-state-on-upgrade-req-plc-007
	 *
	 * @return void
	 */
	public function testOpenRegisterWaitingForItsUpgradeIsUpgradedBeforeTheImport(): void {
		$order = [];
		$this->importRecordedIn(order: $order);
		$this->appManager->method('isUpgradeRequired')->with('openregister')->willReturn(true);
		$this->appManager->expects($this->once())->method('upgradeApp')->with('openregister')
			->willReturnCallback(
				function () use (&$order): bool {
					$order[] = 'upgrade openregister';
					return true;
				}
			);

		$this->repairStep->run(output: $this->createMock(originalClassName: IOutput::class));

		$this->assertSame(expected: ['upgrade openregister', 'import'], actual: $order);
	}

	/**
	 * A current OpenRegister is left alone.
	 *
	 * @spec openspec/changes/publication-lifecycle-on-or/specs/publications/spec.md#requirement-existing-publications-get-a-stored-state-on-upgrade-req-plc-007
	 *
	 * @return void
	 */
	public function testACurrentOpenRegisterIsNotUpgraded(): void {
		$order = [];
		$this->importRecordedIn(order: $order);
		$this->appManager->method('isUpgradeRequired')->willReturn(false);
		$this->appManager->expects($this->never())->method('upgradeApp');

		$this->repairStep->run(output: $this->createMock(originalClassName: IOutput::class));

		$this->assertSame(expected: ['import'], actual: $order);
	}

	/**
	 * A failed OpenRegister upgrade is reported and the import still runs.
	 *
	 * @spec openspec/changes/publication-lifecycle-on-or/specs/publications/spec.md#requirement-existing-publications-get-a-stored-state-on-upgrade-req-plc-007
	 *
	 * @return void
	 */
	public function testAFailedOpenRegisterUpgradeIsReportedAndTheImportStillRuns(): void {
		$order = [];
		$this->importRecordedIn(order: $order);
		$this->appManager->method('isUpgradeRequired')->willReturn(true);
		$this->appManager->method('upgradeApp')->willThrowException(new \RuntimeException('migration failed'));
		$output = $this->createMock(originalClassName: IOutput::class);
		$output->expects($this->once())->method('warning')
			->with($this->stringContains('OpenRegister could not be upgraded first: migration failed'));

		$this->repairStep->run(output: $output);

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
