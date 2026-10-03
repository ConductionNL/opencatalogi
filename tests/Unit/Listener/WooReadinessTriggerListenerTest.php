<?php

/**
 * Unit tests for WooReadinessTriggerListener, on the real OpenRegister events.
 *
 * @category Test
 * @package  OCA\OpenCatalogi\Tests\Unit\Listener
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenCatalogi.nl
 */

declare(strict_types=1);

namespace Unit\Listener;

use OCA\OpenCatalogi\BackgroundJob\WooReadinessCheckNow;
use OCA\OpenCatalogi\Listener\WooReadinessTriggerListener;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Event\ObjectCreatedEvent;
use OCA\OpenRegister\Event\ObjectUpdatedEvent;
use OCP\BackgroundJob\IJobList;
use OCP\IAppConfig;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * A catalogue switched on for Woo queues one readiness check.
 */
class WooReadinessTriggerListenerTest extends TestCase {

	private MockObject&IJobList $jobList;

	private WooReadinessTriggerListener $listener;

	protected function setUp(): void {
		parent::setUp();
		$config = $this->createMock(IAppConfig::class);
		$config->method('getValueString')->willReturnCallback(
			static fn (string $app, string $key, string $default = ''): string => match ($key) {
				'catalog_register' => '1',
				'catalog_schema' => '5',
				default => $default,
			}
		);
		$this->jobList = $this->createMock(IJobList::class);
		$this->listener = new WooReadinessTriggerListener($config, $this->jobList);
	}

	/**
	 * A catalogue entity.
	 *
	 * @param bool   $woo    Whether it publishes a Woo sitemap.
	 * @param string $schema The schema id.
	 */
	private function catalogue(bool $woo, string $schema = '5'): ObjectEntity {
		$catalogue = new ObjectEntity();
		$catalogue->setRegister('1');
		$catalogue->setSchema($schema);
		$catalogue->setObject(['slug' => 'woo-a', 'hasWooSitemap' => $woo]);
		return $catalogue;
	}

	/** REQ-WIH-004: a catalogue is switched on, and a check is queued. */
	public function testACatalogueIsSwitchedOn(): void {
		$this->jobList->expects($this->once())->method('add')->with(WooReadinessCheckNow::class);
		$this->listener->handle(new ObjectUpdatedEvent($this->catalogue(true), $this->catalogue(false)));
	}

	/** REQ-WIH-004: a catalogue created Woo-enabled queues a check too. */
	public function testACatalogueCreatedWooEnabled(): void {
		$this->jobList->expects($this->once())->method('add')->with(WooReadinessCheckNow::class);
		$this->listener->handle(new ObjectCreatedEvent($this->catalogue(true)));
	}

	/** An update that leaves it on, one that switches it off, and another schema queue nothing. */
	public function testNothingElseQueuesACheck(): void {
		$this->jobList->expects($this->never())->method('add');
		$this->listener->handle(new ObjectUpdatedEvent($this->catalogue(true), $this->catalogue(true)));
		$this->listener->handle(new ObjectUpdatedEvent($this->catalogue(false), $this->catalogue(true)));
		$this->listener->handle(new ObjectUpdatedEvent($this->catalogue(true, '9'), $this->catalogue(false, '9')));
	}
}
