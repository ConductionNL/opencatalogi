<?php

/**
 * The places that hand a sync, a broadcast, a readiness check or a save to ConnectionReporter.
 *
 * ConnectionReporterTest proves what an event says. These tests prove each
 * one is asked for, and that a class built without a reporter answers exactly
 * as before. The reporter is an optional last constructor argument, so a
 * call site that forgets it is a silent no-op: only a test like this notices.
 *
 * @category Tests
 * @package  OCA\OpenCatalogi\Tests\Unit\Controller
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
 * @spec openspec/changes/adopt-connection-registry/specs/app-connections/spec.md#requirement-req-oc-conn-003-opencatalogi-reports-what-a-sync-a-broadcast-and-a-readiness-check-met
 * @spec openspec/changes/adopt-connection-registry/specs/app-connections/spec.md#requirement-req-oc-conn-002-a-save-asks-integriq-to-look-again
 */

declare(strict_types=1);

namespace Unit\Controller;

use OCA\OpenCatalogi\Controller\SettingsController;
use OCA\OpenCatalogi\Controller\SetupController;
use OCA\OpenCatalogi\Controller\WooReadinessController;
use OCA\OpenCatalogi\Service\Broadcast\BroadcastResult;
use OCA\OpenCatalogi\Service\BroadcastService;
use OCA\OpenCatalogi\Service\Connection\ConnectionReporter;
use OCA\OpenCatalogi\Service\DemoDataService;
use OCA\OpenCatalogi\Service\DirectoryService;
use OCA\OpenCatalogi\Service\Federation\FederationHostPolicy;
use OCA\OpenCatalogi\Service\SettingsService;
use OCA\OpenCatalogi\Service\WooReadinessService;
use OCP\App\IAppManager;
use OCP\AppFramework\Http;
use OCP\IAppConfig;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IURLGenerator;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Tests for the call sites of ConnectionReporter.
 *
 * @covers \OCA\OpenCatalogi\Controller\WooReadinessController
 * @covers \OCA\OpenCatalogi\Controller\SettingsController
 * @covers \OCA\OpenCatalogi\Controller\SetupController
 * @covers \OCA\OpenCatalogi\Service\BroadcastService
 * @covers \OCA\OpenCatalogi\Service\DirectoryService
 */
class ConnectionReportCallersTest extends TestCase {

	/**
	 * A reporter double that expects nothing unless a test says so.
	 *
	 * @return ConnectionReporter&MockObject
	 */
	private function reporter(): ConnectionReporter&MockObject {
		return $this->createMock(ConnectionReporter::class);
	}//end reporter()

	/**
	 * The readiness controller around a service double.
	 *
	 * @param WooReadinessService     $service  The readiness service double.
	 * @param ConnectionReporter|null $reporter The reporter, or none.
	 *
	 * @return WooReadinessController
	 */
	private function readinessController(WooReadinessService $service, ?ConnectionReporter $reporter): WooReadinessController {
		return new WooReadinessController('opencatalogi', $this->createMock(IRequest::class), $service, $reporter);
	}//end readinessController()

	/**
	 * A check without a Woo catalog reports unconfigured and keeps its 409.
	 *
	 * @return void
	 */
	public function testAReadinessCheckWithoutAWooCatalogReportsAndKeepsItsConflict(): void {
		$service = $this->createMock(WooReadinessService::class);
		$service->method('hasWooEnabledCatalogs')->willReturn(false);
		$service->expects($this->never())->method('runCheck');

		$reporter = $this->reporter();
		$reporter->expects($this->once())->method('reportWooNotConfigured')->willReturn(true);
		$reporter->expects($this->never())->method('reportWooReadiness');

		$with    = $this->readinessController($service, $reporter)->run();
		$without = $this->readinessController($service, null)->run();

		$this->assertSame(Http::STATUS_CONFLICT, $with->getStatus());
		$this->assertSame($without->getData(), $with->getData());
		$this->assertSame($without->getStatus(), $with->getStatus());
	}//end testAReadinessCheckWithoutAWooCatalogReportsAndKeepsItsConflict()

	/**
	 * A check that ran reports the report it returns, unchanged.
	 *
	 * @return void
	 */
	public function testAReadinessCheckThatRanReportsItsReport(): void {
		$report  = ['verdict' => 'ready', 'checks' => [['id' => 'robots-txt', 'status' => 'pass']]];
		$service = $this->createMock(WooReadinessService::class);
		$service->method('hasWooEnabledCatalogs')->willReturn(true);
		$service->method('runCheck')->willReturn($report);

		$reporter = $this->reporter();
		$reporter->expects($this->once())->method('reportWooReadiness')->with($report)->willReturn(true);
		$reporter->expects($this->never())->method('reportWooCheckStopped');

		$response = $this->readinessController($service, $reporter)->run();

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame($report, $response->getData());
	}//end testAReadinessCheckThatRanReportsItsReport()

	/**
	 * A check that throws reports that it stopped and keeps its 400.
	 *
	 * @return void
	 */
	public function testAReadinessCheckThatThrowsReportsThatItStopped(): void {
		$service = $this->createMock(WooReadinessService::class);
		$service->method('hasWooEnabledCatalogs')->willReturn(true);
		$service->method('runCheck')->willThrowException(new RuntimeException('boom'));

		$reporter = $this->reporter();
		$reporter->expects($this->once())->method('reportWooCheckStopped')->willReturn(true);
		$reporter->expects($this->never())->method('reportWooReadiness');

		$response = $this->readinessController($service, $reporter)->run();

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$this->assertSame(['error' => 'boom'], $response->getData());
	}//end testAReadinessCheckThatThrowsReportsThatItStopped()

	/**
	 * The settings controller around a service double.
	 *
	 * @param SettingsService         $service  The settings service double.
	 * @param ConnectionReporter|null $reporter The reporter, or none.
	 *
	 * @return SettingsController
	 */
	private function settingsController(SettingsService $service, ?ConnectionReporter $reporter): SettingsController {
		$request = $this->createMock(IRequest::class);
		$request->method('getParams')->willReturn(['woo_index_registration_status' => 'registered']);

		return new SettingsController(
			'opencatalogi',
			$request,
			$service,
			$this->createMock(IL10N::class),
			$this->createMock(IUserSession::class),
			$reporter
		);
	}//end settingsController()

	/**
	 * A settings save hands the keys it wrote to the reporter, and answers as before.
	 *
	 * @return void
	 */
	public function testASettingsSaveHandsTheWrittenKeysToTheReporter(): void {
		$written = ['woo_index_registration_status' => 'registered', 'catalog_register' => '3'];
		$service = $this->createMock(SettingsService::class);
		$service->method('updateSettings')->willReturn($written);

		$reporter = $this->reporter();
		$reporter->expects($this->once())
			->method('refreshFromSave')
			->with(['woo_index_registration_status', 'catalog_register'])
			->willReturn(['woo-index']);

		$with    = $this->settingsController($service, $reporter)->update();
		$without = $this->settingsController($service, null)->update();

		$this->assertSame($written, $with->getData());
		$this->assertSame($without->getData(), $with->getData());
	}//end testASettingsSaveHandsTheWrittenKeysToTheReporter()

	/**
	 * A settings save that fails asks for nothing: no setting changed.
	 *
	 * @return void
	 */
	public function testAFailedSettingsSaveAsksForNothing(): void {
		$service = $this->createMock(SettingsService::class);
		$service->method('updateSettings')->willThrowException(new RuntimeException('write failed'));

		$reporter = $this->reporter();
		$reporter->expects($this->never())->method('refreshFromSave');

		$this->assertSame(500, $this->settingsController($service, $reporter)->update()->getStatus());
	}//end testAFailedSettingsSaveAsksForNothing()

	/**
	 * A setup save hands the keys it wrote to the reporter.
	 *
	 * @return void
	 */
	public function testASetupSaveHandsTheWrittenKeysToTheReporter(): void {
		$request = $this->createMock(IRequest::class);
		$request->method('getParams')->willReturn(['default_directory_url' => 'https://directory.example.nl/api', 'other' => 'x']);

		$reporter = $this->reporter();
		$reporter->expects($this->once())
			->method('refreshFromSave')
			->with(['default_directory_url'])
			->willReturn(['directory']);

		$controller = new SetupController(
			'opencatalogi',
			$request,
			$this->createMock(IAppConfig::class),
			$this->createMock(SettingsService::class),
			$this->createMock(DemoDataService::class),
			$this->createMock(DirectoryService::class),
			$this->createMock(BroadcastService::class),
			$this->createMock(ContainerInterface::class),
			$this->createMock(IL10N::class),
			$this->createMock(LoggerInterface::class),
			$this->createMock(IUserSession::class),
			$reporter
		);

		$this->assertSame(['saved' => ['default_directory_url']], $controller->config()->getData());
	}//end testASetupSaveHandsTheWrittenKeysToTheReporter()

	/**
	 * A broadcast service whose delivery is a double, so no DNS lookup or POST runs.
	 *
	 * @param string                  $ownUrl   The address this instance advertises.
	 * @param bool                    $accepted Whether the target accepts the broadcast.
	 * @param ConnectionReporter|null $reporter The reporter, or none.
	 *
	 * @return BroadcastService&MockObject
	 */
	private function broadcastService(string $ownUrl, bool $accepted, ?ConnectionReporter $reporter): BroadcastService&MockObject {
		$urlGenerator = $this->createMock(IURLGenerator::class);
		$urlGenerator->method('linkToRoute')->willReturn('/apps/opencatalogi/api/directory');
		$urlGenerator->method('getAbsoluteURL')->willReturn($ownUrl);

		$config = $this->createMock(IAppConfig::class);
		$config->method('getValueString')->willReturnArgument(2);
		$config->method('getValueInt')->willReturnArgument(2);

		$service = $this->getMockBuilder(BroadcastService::class)
			->setConstructorArgs(
				[
					$urlGenerator,
					$this->createMock(ContainerInterface::class),
					$this->createMock(IAppManager::class),
					$this->createMock(LoggerInterface::class),
					$config,
					new FederationHostPolicy($config),
					$reporter,
				]
			)
			->onlyMethods(['enqueueBroadcast'])
			->getMock();

		$status = BroadcastResult::STATUS_FAILED;
		if ($accepted === true) {
			$status = BroadcastResult::STATUS_DELIVERED;
		}

		$service->method('enqueueBroadcast')->willReturnCallback(
			static fn (string $url): BroadcastResult => new BroadcastResult(url: $url, status: $status)
		);

		return $service;
	}//end broadcastService()

	/**
	 * A broadcast reports what the peers answered, and returns the same results.
	 *
	 * @return void
	 */
	public function testABroadcastReportsWhatThePeersAnswered(): void {
		$peer     = 'https://peer.example.nl/apps/opencatalogi/api/directory';
		$reporter = $this->reporter();
		$reporter->expects($this->once())->method('reportBroadcast')->with([$peer => false])->willReturn(true);
		$reporter->expects($this->never())->method('reportBroadcastFromLocalAddress');

		$with    = $this->broadcastService('https://catalogus.example.nl/apps/opencatalogi/api/directory', false, $reporter)->broadcast($peer);
		$without = $this->broadcastService('https://catalogus.example.nl/apps/opencatalogi/api/directory', false, null)->broadcast($peer);

		$this->assertSame([$peer => false], $with);
		$this->assertSame($without, $with);
	}//end testABroadcastReportsWhatThePeersAnswered()

	/**
	 * A broadcast from a local address reports that, and still sends nothing.
	 *
	 * @return void
	 */
	public function testABroadcastFromALocalAddressReportsIt(): void {
		$reporter = $this->reporter();
		$reporter->expects($this->once())->method('reportBroadcastFromLocalAddress')->willReturn(true);
		$reporter->expects($this->never())->method('reportBroadcast');

		$service = $this->broadcastService('http://localhost/apps/opencatalogi/api/directory', true, $reporter);
		$service->expects($this->never())->method('enqueueBroadcast');

		$this->assertSame([], $service->broadcast('https://peer.example.nl/apps/opencatalogi/api/directory'));
	}//end testABroadcastFromALocalAddressReportsIt()

	/**
	 * A broadcast with no peer to tell reports an empty result.
	 *
	 * @return void
	 */
	public function testABroadcastWithNobodyToTellReportsIt(): void {
		$reporter = $this->reporter();
		$reporter->expects($this->once())->method('reportBroadcast')->with([])->willReturn(true);

		$this->assertSame([], $this->broadcastService('https://catalogus.example.nl/api', true, $reporter)->broadcast(null));
	}//end testABroadcastWithNobodyToTellReportsIt()

	/**
	 * A directory sync reports its result, from the one place every sync path passes through.
	 *
	 * @return void
	 */
	public function testADirectorySyncReportsItsResult(): void {
		$reporter = $this->reporter();
		$reporter->expects($this->once())
			->method('reportDirectorySync')
			->with(
				$this->callback(
					static fn (array $results): bool => $results['total_directories'] === 2
						&& $results['synced_directories'] === 1
						&& $results['failed_directories'] === 1
						&& $results['errors'][0]['directory'] === 'https://down.example.nl/api'
				)
			)
			->willReturn(true);

		$service = $this->getMockBuilder(DirectoryService::class)
			->setConstructorArgs(
				[
					$this->createMock(IURLGenerator::class),
					$this->createMock(IAppConfig::class),
					$this->createMock(ContainerInterface::class),
					$this->createMock(IAppManager::class),
					$this->createMock(BroadcastService::class),
					$this->createMock(IRequest::class),
					null,
					$reporter,
				]
			)
			->onlyMethods(['getKnownDirectoryUrls', 'getDefaultDirectoryUrl', 'syncDirectory'])
			->getMock();
		$service->method('getKnownDirectoryUrls')->willReturn(['https://down.example.nl/api']);
		$service->method('getDefaultDirectoryUrl')->willReturn('https://directory.example.nl/api');
		$service->method('syncDirectory')->willReturnCallback(
			static function (string $directoryUrl): array {
				if ($directoryUrl === 'https://down.example.nl/api') {
					throw new RuntimeException('unreachable');
				}

				return ['ok' => true];
			}
		);

		$result = $service->doCronSync();

		$this->assertSame(1, $result['failed_directories']);
	}//end testADirectorySyncReportsItsResult()
}//end class
