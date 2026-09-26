<?php

/**
 * The events OpenCatalogi sends to integriq's connection registry.
 *
 * @category Tests
 * @package  OCA\OpenCatalogi\Tests\Unit\Service\Connection
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
 */

declare(strict_types=1);

namespace Unit\Service\Connection;

use OCA\Integriq\Event\ConnectionRefreshRequestedEvent;
use OCA\Integriq\Event\ConnectionStatusReportedEvent;
use OCA\OpenCatalogi\Service\Connection\ConnectionReporter;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\IAppConfig;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Tests for ConnectionReporter.
 *
 * @covers \OCA\OpenCatalogi\Service\Connection\ConnectionReporter
 */
class ConnectionReporterTest extends TestCase {

	/**
	 * The events dispatched, in order.
	 *
	 * @var array<int, Event>
	 */
	private array $dispatched = [];

	/**
	 * The app-config values the reporter wrote, keyed by config key.
	 *
	 * @var array<string, string>
	 */
	private array $config = [];

	/**
	 * The current Unix time the clock answers.
	 *
	 * @var int
	 */
	private int $now = 1_800_000_000;

	/**
	 * The logger double.
	 *
	 * @var LoggerInterface&MockObject
	 */
	private LoggerInterface&MockObject $logger;

	/**
	 * The dispatcher double, recording every event.
	 *
	 * @var IEventDispatcher&MockObject
	 */
	private IEventDispatcher&MockObject $dispatcher;

	/**
	 * Build the doubles.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->dispatched = [];
		$this->config     = [];

		$this->dispatcher = $this->createMock(IEventDispatcher::class);
		$this->dispatcher->method('dispatchTyped')->willReturnCallback(
			function (Event $event): void {
				$this->dispatched[] = $event;
			}
		);

		$this->logger = $this->createMock(LoggerInterface::class);
	}//end setUp()

	/**
	 * An IAppConfig double backed by $this->config.
	 *
	 * @return IAppConfig&MockObject
	 */
	private function appConfig(): IAppConfig&MockObject {
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(
			fn (string $app, string $key, string $default = ''): string => ($this->config[$key] ?? $default)
		);
		$appConfig->method('setValueString')->willReturnCallback(
			function (string $app, string $key, string $value): bool {
				$this->config[$key] = $value;
				return true;
			}
		);
		$appConfig->method('deleteKey')->willReturnCallback(
			function (string $app, string $key): void {
				unset($this->config[$key]);
			}
		);

		return $appConfig;
	}//end appConfig()

	/**
	 * A clock double answering $this->now.
	 *
	 * @return ITimeFactory&MockObject
	 */
	private function clock(): ITimeFactory&MockObject {
		$clock = $this->createMock(ITimeFactory::class);
		$clock->method('getTime')->willReturnCallback(fn (): int => $this->now);

		return $clock;
	}//end clock()

	/**
	 * The reporter with integriq's events present.
	 *
	 * @return ConnectionReporter
	 */
	private function reporter(): ConnectionReporter {
		return new ConnectionReporter($this->dispatcher, $this->appConfig(), $this->clock(), $this->logger);
	}//end reporter()

	/**
	 * The reporter as it runs without integriq: neither event class resolves.
	 *
	 * @return ConnectionReporter&MockObject
	 */
	private function reporterWithoutIntegriq(): ConnectionReporter&MockObject {
		$reporter = $this->getMockBuilder(ConnectionReporter::class)
			->setConstructorArgs([$this->dispatcher, $this->appConfig(), $this->clock(), $this->logger])
			->onlyMethods(['resolveEventClass'])
			->getMock();
		$reporter->method('resolveEventClass')->willReturn(null);

		return $reporter;
	}//end reporterWithoutIntegriq()

	/**
	 * A sync report reaches integriq with this app, the key, the status and the message.
	 *
	 * @return void
	 */
	public function testADirectorySyncIsReported(): void {
		$sent = $this->reporter()->reportDirectorySync(
			['total_directories' => 1, 'synced_directories' => 1, 'failed_directories' => 0, 'errors' => []]
		);

		$this->assertTrue($sent);
		$this->assertCount(1, $this->dispatched);
		$event = $this->dispatched[0];
		$this->assertInstanceOf(ConnectionStatusReportedEvent::class, $event);
		$this->assertSame('opencatalogi', $event->app);
		$this->assertSame('directory', $event->key);
		$this->assertSame('configured', $event->status);
		$this->assertSame('The directory answered the last sync.', $event->message);
	}//end testADirectorySyncIsReported()

	/**
	 * A readiness check reports every time, with no memory written.
	 *
	 * @return void
	 */
	public function testAReadinessCheckIsNotThrottled(): void {
		$reporter = $this->reporter();

		$this->assertTrue($reporter->reportWooNotConfigured());
		$this->assertTrue($reporter->reportWooNotConfigured());
		$this->assertTrue($reporter->reportWooCheckStopped());
		$this->assertTrue($reporter->reportWooReadiness(['checks' => [['id' => 'registration', 'status' => 'pass']]]));

		$this->assertSame(
			['unconfigured', 'unconfigured', 'error', 'configured'],
			array_map(static fn (Event $event): string => $event->status, $this->dispatched)
		);
		$this->assertSame(['woo-index'], array_values(array_unique(array_map(static fn (Event $event): string => $event->key, $this->dispatched))));
		$this->assertSame([], $this->config);
	}//end testAReadinessCheckIsNotThrottled()

	/**
	 * The same broadcast status reports once an hour, and a changed one after five minutes.
	 *
	 * @return void
	 */
	public function testABroadcastIsThrottled(): void {
		$reporter = $this->reporter();
		$accepted = ['https://peer.example.nl/api' => true];
		$refused  = ['https://peer.example.nl/api' => false];

		$this->assertTrue($reporter->reportBroadcast($accepted));

		$this->now += 600;
		$this->assertFalse($reporter->reportBroadcast($accepted), 'the same status within the hour');

		$this->now += 3000;
		$this->assertTrue($reporter->reportBroadcast($accepted), 'the same status after the hour');

		$this->now += 120;
		$this->assertFalse($reporter->reportBroadcast($refused), 'a changed status within five minutes');

		$this->now += 180;
		$this->assertTrue($reporter->reportBroadcastFromLocalAddress(), 'a changed status after five minutes');

		$this->assertSame(
			['configured', 'configured', 'unconfigured'],
			array_map(static fn (Event $event): string => $event->status, $this->dispatched)
		);
		$this->assertSame('unconfigured|' . $this->now, $this->config['connection_report_broadcast']);
	}//end testABroadcastIsThrottled()

	/**
	 * A save refreshes only the connections whose keys it wrote.
	 *
	 * @return void
	 */
	public function testASaveRefreshesOnlyTheConnectionsItTouched(): void {
		$reporter = $this->reporter();

		$this->assertSame([], $reporter->refreshFromSave(['catalog_register', 'catalog_schema']));
		$this->assertSame([], $this->dispatched);

		$this->assertSame(['woo-index'], $reporter->refreshFromSave(['catalog_register', 'woo_index_registration_status']));
		$this->assertSame(['directory'], $reporter->refreshFromSave(['default_directory_url']));

		$this->assertCount(2, $this->dispatched);
		foreach ($this->dispatched as $event) {
			$this->assertInstanceOf(ConnectionRefreshRequestedEvent::class, $event);
			$this->assertSame('opencatalogi', $event->app);
		}

		$this->assertSame(['woo-index', 'directory'], array_map(static fn (Event $event): ?string => $event->key, $this->dispatched));
	}//end testASaveRefreshesOnlyTheConnectionsItTouched()

	/**
	 * A refresh goes out before the report that follows it, and clears the report memory.
	 *
	 * Under hydra#674 a refresh retires older observations, so a report sent
	 * before it would be retired by it.
	 *
	 * @return void
	 */
	public function testARefreshGoesBeforeTheReportAndClearsTheMemory(): void {
		$this->config['connection_report_broadcast'] = 'configured|' . $this->now;
		$reporter = $this->reporter();

		$reporter->refreshFromSave(['default_directory_url']);
		$reporter->reportDirectorySync(['total_directories' => 1, 'synced_directories' => 0, 'failed_directories' => 1, 'errors' => []]);

		$this->assertInstanceOf(ConnectionRefreshRequestedEvent::class, $this->dispatched[0]);
		$this->assertInstanceOf(ConnectionStatusReportedEvent::class, $this->dispatched[1]);
		$this->assertSame('directory', $this->dispatched[1]->key);
		$this->assertArrayHasKey('connection_report_broadcast', $this->config, 'a directory refresh leaves the broadcast memory alone');
	}//end testARefreshGoesBeforeTheReportAndClearsTheMemory()

	/**
	 * Without integriq nothing is sent, stored or logged.
	 *
	 * @return void
	 */
	public function testWithoutIntegriqNothingIsSentStoredOrLogged(): void {
		$this->logger->expects($this->never())->method($this->anything());
		$reporter = $this->reporterWithoutIntegriq();

		$this->assertFalse($reporter->reportDirectorySync([]));
		$this->assertFalse($reporter->reportBroadcast(['https://peer.example.nl' => true]));
		$this->assertFalse($reporter->reportBroadcastFromLocalAddress());
		$this->assertFalse($reporter->reportWooReadiness([]));
		$this->assertFalse($reporter->reportWooNotConfigured());
		$this->assertFalse($reporter->reportWooCheckStopped());
		$this->assertSame([], $reporter->refreshFromSave(['default_directory_url', 'woo_index_registration_url']));

		$this->assertSame([], $this->dispatched);
		$this->assertSame([], $this->config);
	}//end testWithoutIntegriqNothingIsSentStoredOrLogged()

	/**
	 * With integriq the real event classes resolve by their string names.
	 *
	 * @return void
	 */
	public function testTheEventNamesResolveToIntegriqsClasses(): void {
		$this->assertSame(ConnectionStatusReportedEvent::class, ConnectionReporter::STATUS_EVENT);
		$this->assertSame(ConnectionRefreshRequestedEvent::class, ConnectionReporter::REFRESH_EVENT);
	}//end testTheEventNamesResolveToIntegriqsClasses()

	/**
	 * A listener that throws never reaches the caller, and leaves no memory.
	 *
	 * @return void
	 */
	public function testAThrowingListenerNeverEscapes(): void {
		$dispatcher = $this->createMock(IEventDispatcher::class);
		$dispatcher->method('dispatchTyped')->willThrowException(new RuntimeException('listener failed'));
		$this->logger->expects($this->exactly(2))->method('warning');

		$reporter = new ConnectionReporter($dispatcher, $this->appConfig(), $this->clock(), $this->logger);

		$this->assertFalse($reporter->reportBroadcast(['https://peer.example.nl' => true]));
		$this->assertSame([], $reporter->refreshFromSave(['default_directory_url']));
		$this->assertSame([], $this->config);
	}//end testAThrowingListenerNeverEscapes()
}//end class
