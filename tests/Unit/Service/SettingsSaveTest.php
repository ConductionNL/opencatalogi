<?php

/**
 * A settings save writes the values and sets off what a save sets off.
 *
 * @category Test
 * @package  OCA\OpenCatalogi\Tests
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://www.OpenCatalogi.nl
 *
 * @spec openspec/changes/admin-settings/tasks.md#task-1.5
 */

declare(strict_types=1);

namespace Unit\Service;

use InvalidArgumentException;
use OCA\OpenCatalogi\BackgroundJob\PublicationLinksRewrite;
use OCA\OpenCatalogi\Contract\ConnectionReporterInterface;
use OCA\OpenCatalogi\Service\RegisterSchemaLinkService;
use OCA\OpenCatalogi\Service\SettingsService;
use OCP\App\IAppManager;
use OCP\BackgroundJob\IJobList;
use OCP\IAppConfig;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Tests for SettingsService::saveSettings().
 */
class SettingsSaveTest extends TestCase {

	/**
	 * The stored app config, by key.
	 *
	 * @var array<string, string>
	 */
	private array $stored = [];

	/**
	 * The jobs queued, by class.
	 *
	 * @var array<int, string>
	 */
	private array $queued = [];

	/**
	 * A service over the stored config, with these collaborators in its container.
	 *
	 * @param ConnectionReporterInterface|null $reporter The connection reporter, or none.
	 * @param bool                             $jobs     Whether the container holds a job list.
	 *
	 * @return SettingsService
	 */
	private function service(?ConnectionReporterInterface $reporter = null, bool $jobs = true): SettingsService {
		$config = $this->createMock(originalClassName: IAppConfig::class);
		$config->method('setValueString')->willReturnCallback(
			function (string $app, string $key, string $value): bool {
				$this->stored[$key] = $value;
				return true;
			}
		);
		$config->method('getValueString')->willReturnCallback(
			fn (string $app, string $key, string $default = ''): string => ($this->stored[$key] ?? $default)
		);

		$jobList = $this->createMock(originalClassName: IJobList::class);
		$jobList->method('add')->willReturnCallback(
			function (string $job): void {
				$this->queued[] = $job;
			}
		);

		$container = $this->createMock(originalClassName: ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			static function (string $id) use ($reporter, $jobList, $jobs): object {
				if ($id === ConnectionReporterInterface::class && $reporter !== null) {
					return $reporter;
				}

				if ($id === IJobList::class && $jobs === true) {
					return $jobList;
				}

				throw new RuntimeException('not registered: ' . $id);
			}
		);

		return new SettingsService(
			config: $config,
			container: $container,
			appManager: $this->createMock(originalClassName: IAppManager::class),
			logger: $this->createMock(originalClassName: LoggerInterface::class),
			registerSchemaLink: $this->createMock(originalClassName: RegisterSchemaLinkService::class)
		);
	}//end service()

	/**
	 * A reporter double.
	 *
	 * @return ConnectionReporterInterface&MockObject
	 */
	private function reporter(): ConnectionReporterInterface&MockObject {
		return $this->createMock(originalClassName: ConnectionReporterInterface::class);
	}//end reporter()

	/**
	 * REQ-PTS-001: changing a link setting queues one rewrite of every publication's pages; saving the same value queues none.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/archive/2026-11-25-publication-tells-its-source/specs/publications/spec.md#requirement-a-publication-carries-its-public-page-and-its-officer-page-req-pts-001
	 */
	public function testAChangedLinkSettingQueuesOneRewrite(): void {
		$service = $this->service();

		$service->saveSettings(data: ['publication_url_template' => 'https://new.example/{id}']);
		$this->assertSame(expected: [PublicationLinksRewrite::class], actual: $this->queued);

		$service->saveSettings(data: ['publication_url_template' => 'https://new.example/{id}', 'dcat_publisher_name' => 'Gemeente']);
		$this->assertCount(expectedCount: 1, haystack: $this->queued);

		$service->saveSettings(data: ['publication_url_catalog' => 'woo']);
		$this->assertCount(expectedCount: 2, haystack: $this->queued);
	}//end testAChangedLinkSettingQueuesOneRewrite()

	/**
	 * A save hands the keys it wrote to the reporter, and answers the persisted values.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/adopt-connection-registry/specs/app-connections/spec.md#requirement-req-oc-conn-002-a-save-asks-integriq-to-look-again
	 */
	public function testASaveHandsTheWrittenKeysToTheReporter(): void {
		$reporter = $this->reporter();
		$reporter->expects($this->once())
			->method('refreshFromSave')
			->with(['catalog_register', 'woo_index_registration_status'])
			->willReturn(['woo-index']);

		$saved = $this->service(reporter: $reporter)->saveSettings(
			data: ['woo_index_registration_status' => 'registered', 'catalog_register' => '3', 'not_a_setting' => 'x']
		);

		$this->assertSame(expected: ['catalog_register' => '3', 'woo_index_registration_status' => 'registered'], actual: $saved);
		$this->assertSame(expected: [], actual: $this->queued);
	}//end testASaveHandsTheWrittenKeysToTheReporter()

	/**
	 * A refused value writes nothing, so nothing is asked or queued.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/adopt-connection-registry/specs/app-connections/spec.md#requirement-req-oc-conn-002-a-save-asks-integriq-to-look-again
	 */
	public function testARefusedValueAsksAndQueuesNothing(): void {
		$reporter = $this->reporter();
		$reporter->expects($this->never())->method('refreshFromSave');

		try {
			$this->service(reporter: $reporter)->saveSettings(data: ['publication_url_template' => ['a', 'b']]);
			$this->fail(message: 'A list value was accepted');
		} catch (InvalidArgumentException) {
			$this->addToAssertionCount(count: 1);
		}

		$this->assertSame(expected: [], actual: $this->stored);
		$this->assertSame(expected: [], actual: $this->queued);
	}//end testARefusedValueAsksAndQueuesNothing()

	/**
	 * Without integriq or a job list the save still saves.
	 *
	 * @return void
	 */
	public function testWithoutReporterOrJobListTheSaveStillSaves(): void {
		$saved = $this->service(reporter: null, jobs: false)->saveSettings(data: ['publication_url_template' => 'https://new.example/{id}']);

		$this->assertSame(expected: ['publication_url_template' => 'https://new.example/{id}'], actual: $saved);
		$this->assertSame(expected: [], actual: $this->queued);
	}//end testWithoutReporterOrJobListTheSaveStillSaves()
}//end class
