<?php

/**
 * Unit tests for the queued Plooi delivery and the queued Woo readiness check.
 *
 * @category Test
 * @package  OCA\OpenCatalogi\Tests\Unit\BackgroundJob
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenCatalogi.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace Unit\BackgroundJob;

use DateTimeImmutable;
use OCA\OpenCatalogi\BackgroundJob\PlooiDelivery;
use OCA\OpenCatalogi\BackgroundJob\SavedSearchMatchingJob;
use OCA\OpenCatalogi\BackgroundJob\WooReadinessCheck;
use OCA\OpenCatalogi\BackgroundJob\WooReadinessCheckNow;
use OCA\OpenCatalogi\Contract\HarvesterReadinessServiceInterface;
use OCA\OpenCatalogi\Contract\PlooiDeliveryServiceInterface;
use OCA\OpenCatalogi\Contract\SavedSearchMatcherInterface;
use OCP\App\IAppManager;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IConfig;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * A queued job hands its argument to the service, and a failing service is
 * logged instead of failing the job.
 *
 * @covers \OCA\OpenCatalogi\BackgroundJob\PlooiDelivery
 * @covers \OCA\OpenCatalogi\BackgroundJob\WooReadinessCheckNow
 * @covers \OCA\OpenCatalogi\BackgroundJob\WooReadinessCheck
 * @covers \OCA\OpenCatalogi\BackgroundJob\SavedSearchMatchingJob
 */
class QueuedDeliveryAndReadinessJobsTest extends TestCase {

	/**
	 * Run a job's protected run() with an argument.
	 *
	 * @param object $job      The job.
	 * @param mixed  $argument The queued argument.
	 *
	 * @return void
	 */
	private static function runJob(object $job, mixed $argument): void {
		(new \ReflectionMethod($job, 'run'))->invoke($job, $argument);
	}//end runJob()

	/**
	 * The queued publication is delivered with its register and schema.
	 *
	 * @return void
	 */
	public function testAQueuedPublicationIsDelivered(): void {
		$service = $this->createMock(PlooiDeliveryServiceInterface::class);
		$service->expects($this->once())->method('deliver')->with('pub-1', '7', '12')->willReturn(['status' => 'delivered']);
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->never())->method('error');

		self::runJob(new PlooiDelivery($this->createMock(ITimeFactory::class), $service, $logger), ['uuid' => 'pub-1', 'register' => 7, 'schema' => 12]);
	}//end testAQueuedPublicationIsDelivered()

	/**
	 * Without a uuid nothing is delivered; a missing register or schema goes as empty.
	 *
	 * @return void
	 */
	public function testWithoutAUuidNothingIsDelivered(): void {
		$service = $this->createMock(PlooiDeliveryServiceInterface::class);
		$service->expects($this->once())->method('deliver')->with('pub-2', '', '')->willReturn(null);
		$job = new PlooiDelivery($this->createMock(ITimeFactory::class), $service, $this->createMock(LoggerInterface::class));

		self::runJob($job, null);
		self::runJob($job, 'pub-1');
		self::runJob($job, ['uuid' => '']);
		self::runJob($job, ['register' => '7']);
		self::runJob($job, ['uuid' => 'pub-2']);
	}//end testWithoutAUuidNothingIsDelivered()

	/**
	 * A failed delivery is logged with the publication and the reason, and does not escape.
	 *
	 * @return void
	 */
	public function testAFailedDeliveryIsLogged(): void {
		$service = $this->createMock(PlooiDeliveryServiceInterface::class);
		$service->method('deliver')->willThrowException(new RuntimeException('plooi down'));
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('error')->with('[PlooiDelivery] Delivery of pub-1 failed: plooi down');

		self::runJob(new PlooiDelivery($this->createMock(ITimeFactory::class), $service, $logger), ['uuid' => 'pub-1']);
	}//end testAFailedDeliveryIsLogged()

	/**
	 * The queued readiness check runs the check and logs its verdict; without a Woo catalogue it logs nothing.
	 *
	 * @return void
	 */
	public function testTheQueuedCheckLogsItsVerdict(): void {
		$readiness = $this->createMock(HarvesterReadinessServiceInterface::class);
		$readiness->expects($this->exactly(2))->method('runWhenEnabled')->willReturnOnConsecutiveCalls(['verdict' => 'ready'], null);
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('info')->with('[WooReadinessCheckNow] verdict: ready');
		$logger->expects($this->never())->method('error');
		$job = new WooReadinessCheckNow($this->createMock(ITimeFactory::class), $readiness, $logger);

		self::runJob($job, null);
		self::runJob($job, null);
	}//end testTheQueuedCheckLogsItsVerdict()

	/**
	 * A failing check is logged with its exception by the queued and by the daily job, and escapes neither.
	 *
	 * @return void
	 */
	public function testAFailingCheckIsLoggedByBothJobs(): void {
		$failure   = new RuntimeException('harvester unreachable');
		$readiness = $this->createMock(HarvesterReadinessServiceInterface::class);
		$readiness->method('runWhenEnabled')->willThrowException($failure);

		$now = $this->createMock(LoggerInterface::class);
		$now->expects($this->once())->method('error')->with('[WooReadinessCheckNow] the check failed: harvester unreachable', ['exception' => $failure]);
		self::runJob(new WooReadinessCheckNow($this->createMock(ITimeFactory::class), $readiness, $now), null);

		$daily = $this->createMock(LoggerInterface::class);
		$daily->expects($this->once())->method('error')->with('[WooReadinessCheck] the check failed: harvester unreachable', ['exception' => $failure]);
		self::runJob(new WooReadinessCheck($this->createMock(ITimeFactory::class), $readiness, $daily), null);
	}//end testAFailingCheckIsLoggedByBothJobs()

	/**
	 * An unreadable instance time zone falls back to Amsterdam, and a failing match run is logged and does not escape.
	 *
	 * @return void
	 */
	public function testTheMatchRunFallsBackAndLogsItsFailure(): void {
		$failure = new RuntimeException('index gone');
		$matcher = $this->createMock(SavedSearchMatcherInterface::class);
		$matcher->expects($this->once())->method('run')
			->with($this->callback(static fn (DateTimeImmutable $now): bool => $now->getTimezone()->getName() === 'Europe/Amsterdam'))
			->willThrowException($failure);
		$apps = $this->createMock(IAppManager::class);
		$apps->method('isInstalled')->with('portaliq')->willReturn(true);
		$config = $this->createMock(IConfig::class);
		$config->method('getSystemValue')->with('default_timezone', 'Europe/Amsterdam')->willReturn('Not/AZone');
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('error')->with('[SavedSearchMatchingJob] the run failed: index gone', ['exception' => $failure]);

		self::runJob(new SavedSearchMatchingJob($this->createMock(ITimeFactory::class), $matcher, $apps, $config, $logger), null);
	}//end testTheMatchRunFallsBackAndLogsItsFailure()
}//end class
