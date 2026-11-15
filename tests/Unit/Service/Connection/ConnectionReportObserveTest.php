<?php

/**
 * How ConnectionReporter handles an outcome it cannot work out, and the
 * report memory it writes for a throttled key.
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

use OCA\OpenCatalogi\Service\Connection\ConnectionReporter;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Pins the observe step of ConnectionReporter.
 */
class ConnectionReportObserveTest extends TestCase {

	/**
	 * An outcome that cannot be worked out is logged with its key, nothing is
	 * sent and nothing is remembered.
	 *
	 * @return void
	 */
	public function testAnOutcomeThatThrowsIsLoggedAndNotSent(): void {
		$dispatcher = $this->createMock(IEventDispatcher::class);
		$dispatcher->expects($this->never())->method('dispatchTyped');

		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->expects($this->never())->method('setValueString');

		$clock = $this->createMock(ITimeFactory::class);
		$clock->expects($this->never())->method('getTime');

		$warnings = [];
		$logger = $this->createMock(LoggerInterface::class);
		$logger->method('warning')->willReturnCallback(
			static function (string $message, array $context = []) use (&$warnings): void {
				$warnings[] = [$message, $context];
			}
		);

		$reporter = new ConnectionReporter($dispatcher, $appConfig, $clock, $logger);
		$report = new \ReflectionMethod($reporter, 'reportObserved');
		$sent = $report->invoke(
			$reporter,
			'broadcast',
			static function (): array {
				throw new RuntimeException('cannot tell');
			}
		);

		$this->assertFalse($sent);
		$this->assertSame(
			[['OpenCatalogi: could not report a connection to integriq', ['key' => 'broadcast', 'exception' => 'cannot tell']]],
			$warnings
		);
	}//end testAnOutcomeThatThrowsIsLoggedAndNotSent()

	/**
	 * A sent report of a throttled key remembers its status and the moment read
	 * once before the send; an unthrottled key reads the clock but remembers nothing.
	 *
	 * @return void
	 */
	public function testAThrottledReportRemembersTheMomentReadBeforeTheSend(): void {
		$times = [1_800_000_000, 1_800_000_999];
		$clock = $this->createMock(ITimeFactory::class);
		$clock->expects($this->exactly(2))->method('getTime')->willReturnCallback(
			static function () use (&$times): int {
				return array_shift($times);
			}
		);

		$stored = [];
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturn('');
		$appConfig->method('setValueString')->willReturnCallback(
			static function (string $app, string $key, string $value) use (&$stored): bool {
				$stored[] = [$app, $key, $value];
				return true;
			}
		);

		$reporter = new ConnectionReporter($this->createMock(IEventDispatcher::class), $appConfig, $clock, $this->createMock(LoggerInterface::class));

		$this->assertTrue($reporter->reportBroadcast(['https://peer.example.nl/api' => true]));
		$this->assertTrue($reporter->reportWooNotConfigured());
		$this->assertSame([['opencatalogi', 'connection_report_broadcast', 'configured|1800000000']], $stored);
	}//end testAThrottledReportRemembersTheMomentReadBeforeTheSend()
}//end class
