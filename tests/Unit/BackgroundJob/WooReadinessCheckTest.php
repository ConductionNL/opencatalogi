<?php

/**
 * Unit tests for the daily Woo readiness check.
 *
 * @category Test
 * @package  OCA\OpenCatalogi\Tests\Unit\BackgroundJob
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenCatalogi.nl
 */

declare(strict_types=1);

namespace Unit\BackgroundJob;

use OCA\OpenCatalogi\BackgroundJob\WooReadinessCheck;
use OCA\OpenCatalogi\Service\DirectoryService;
use OCA\OpenCatalogi\Service\SettingsService;
use OCA\OpenCatalogi\Service\SitemapService;
use OCA\OpenCatalogi\Service\WooReadinessService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\Http\Client\IClientService;
use OCP\IAppConfig;
use OCP\IURLGenerator;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * The daily job checks only while a catalogue is Woo-enabled.
 */
class WooReadinessCheckTest extends TestCase {

	/** REQ-WIH-004: nothing is Woo-enabled, so no outbound request and the stored report stays. */
	public function testNothingIsWooEnabled(): void {
		$client = $this->createMock(IClientService::class);
		$client->expects($this->never())->method('newClient');
		$config = $this->createMock(IAppConfig::class);
		$config->expects($this->never())->method('setValueString');
		$settings = $this->getMockBuilder(SettingsService::class)
			->disableOriginalConstructor()
			->onlyMethods(['getSettings'])
			->getMock();
		$settings->method('getSettings')->willReturn(['configuration' => []]);

		$readiness = new WooReadinessService(
			$client,
			$this->createMock(DirectoryService::class),
			$this->createMock(SitemapService::class),
			$settings,
			$config,
			$this->createMock(IURLGenerator::class)
		);

		$this->assertNull($readiness->runWhenEnabled());

		$job = new WooReadinessCheck($this->createMock(ITimeFactory::class), $readiness, $this->createMock(LoggerInterface::class));
		$run = new \ReflectionMethod($job, 'run');
		$run->invoke($job, null);
	}

	/** REQ-WIH-004: with a Woo-enabled catalogue the job runs the check. */
	public function testAWooCatalogueGetsItsCheck(): void {
		$readiness = $this->getMockBuilder(WooReadinessService::class)
			->disableOriginalConstructor()
			->onlyMethods(['runWhenEnabled'])
			->getMock();
		$readiness->expects($this->once())->method('runWhenEnabled')->willReturn(['verdict' => 'ready']);

		$job = new WooReadinessCheck($this->createMock(ITimeFactory::class), $readiness, $this->createMock(LoggerInterface::class));
		$run = new \ReflectionMethod($job, 'run');
		$run->invoke($job, null);
	}
}
