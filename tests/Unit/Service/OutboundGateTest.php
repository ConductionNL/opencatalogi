<?php

/**
 * Unit tests for OutboundGate and InstanceMode (REQ-STG-002).
 *
 * @category Test
 * @package  OCA\OpenCatalogi\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/instance-staging-mode
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenCatalogi\Tests\Unit\Service;

use OCA\OpenCatalogi\Service\InstanceMode;
use OCA\OpenCatalogi\Service\OutboundGate;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * The gate opens only on an explicit production mode.
 *
 * @covers \OCA\OpenCatalogi\Service\OutboundGate
 * @covers \OCA\OpenCatalogi\Service\InstanceMode
 */
class OutboundGateTest extends TestCase {

	/**
	 * A gate over an app config answering $mode, or throwing when null.
	 *
	 * @param string|null $mode The stored mode.
	 *
	 * @return OutboundGate
	 */
	private function gate(?string $mode): OutboundGate {
		$config = $this->createMock(IAppConfig::class);
		$stub = $config->method('getValueString')->with('opencatalogi', 'instance_mode', 'production');
		if ($mode === null) {
			$stub->willThrowException(new RuntimeException('database gone'));
		} else {
			$stub->willReturn($mode);
		}

		return new OutboundGate(new InstanceMode($config));
	}//end gate()

	/**
	 * Staging holds every channel, with the reason a dry run records.
	 *
	 * @return void
	 */
	public function testStagingDoesNotAllow(): void {
		$gate = $this->gate('staging');

		foreach (OutboundGate::CHANNELS as $channel) {
			$this->assertFalse($gate->allow($channel), $channel);
		}

		$this->assertSame('plooi held: instance mode staging', $gate->reason('plooi'));
	}//end testStagingDoesNotAllow()

	/**
	 * A mode that cannot be read holds, and the reason says why.
	 *
	 * @return void
	 */
	public function testAnUnreadableModeDoesNotAllow(): void {
		$gate = $this->gate(null);

		$this->assertFalse($gate->allow('national-index'));
		$this->assertSame('national-index held: instance mode unreadable: database gone', $gate->reason('national-index'));
	}//end testAnUnreadableModeDoesNotAllow()

	/**
	 * Production lets every channel send; an unknown stored value holds.
	 *
	 * @return void
	 */
	public function testProductionAllows(): void {
		$gate = $this->gate('production');

		foreach (OutboundGate::CHANNELS as $channel) {
			$this->assertTrue($gate->allow($channel), $channel);
		}

		$this->assertNull($gate->reason('broadcast'));
		$this->assertFalse($this->gate('acceptatie')->allow('broadcast'));
	}//end testProductionAllows()
}//end class
