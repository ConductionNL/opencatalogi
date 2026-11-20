<?php

/**
 * Unit tests for SeedWooProcessingActivity.
 *
 * @category Test
 * @package  Unit\Repair
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

namespace Unit\Repair;

use OCA\OpenCatalogi\Repair\SeedWooProcessingActivity;
use OCA\OpenRegister\Db\Verwerkingsactiviteit;
use OCA\OpenRegister\Db\VerwerkingsactiviteitMapper;
use OCP\App\IAppManager;
use OCP\Migration\IOutput;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;

/**
 * The Woo processing activity is seeded once and never overwritten (REQ-WAB-001).
 */
#[CoversClass(SeedWooProcessingActivity::class)]
class SeedWooProcessingActivityTest extends TestCase {

	/**
	 * The step over the given mapper.
	 *
	 * @param VerwerkingsactiviteitMapper $mapper  The mapper.
	 * @param bool                        $enabled Whether OpenRegister is enabled.
	 *
	 * @return SeedWooProcessingActivity The step.
	 */
	private function step(VerwerkingsactiviteitMapper $mapper, bool $enabled = true): SeedWooProcessingActivity {
		$apps = $this->createMock(IAppManager::class);
		$apps->method('isEnabledForAnyone')->with('openregister')->willReturn($enabled);
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->with('OCA\OpenRegister\Db\VerwerkingsactiviteitMapper')->willReturn($mapper);

		return new SeedWooProcessingActivity($apps, $container);

	}//end step()

	/**
	 * Without the activity, one is inserted under the code the schemas name, as a legal obligation.
	 *
	 * @return void
	 */
	public function testTheActivityIsSeededWhenAbsent(): void {
		$inserted = null;
		$mapper   = $this->createMock(VerwerkingsactiviteitMapper::class);
		$mapper->method('findByCode')->with(SeedWooProcessingActivity::CODE)->willReturn(null);
		$mapper->expects($this->once())->method('insert')->willReturnCallback(
			static function (Verwerkingsactiviteit $entity) use (&$inserted): Verwerkingsactiviteit {
				$inserted = $entity;
				return $entity;
			}
		);

		$this->step($mapper)->run($this->createMock(IOutput::class));

		$this->assertSame('opencatalogi-woo-records', $inserted->getCode());
		$this->assertSame('legal_obligation', $inserted->getLegalBasis());
		$this->assertStringContainsString('Woo', (string) $inserted->getPurpose());

	}//end testTheActivityIsSeededWhenAbsent()

	/**
	 * An existing activity is left alone.
	 *
	 * @return void
	 */
	public function testAnExistingActivityIsKept(): void {
		$mapper = $this->createMock(VerwerkingsactiviteitMapper::class);
		$mapper->method('findByCode')->willReturn(new Verwerkingsactiviteit());
		$mapper->expects($this->never())->method('insert');

		$this->step($mapper)->run($this->createMock(IOutput::class));

	}//end testAnExistingActivityIsKept()

	/**
	 * Without OpenRegister, or with a failing mapper, the step warns and the upgrade goes on.
	 *
	 * @return void
	 */
	public function testItWarnsInsteadOfFailing(): void {
		$mapper = $this->createMock(VerwerkingsactiviteitMapper::class);
		$mapper->expects($this->never())->method('insert');
		$output = $this->createMock(IOutput::class);
		$output->expects($this->once())->method('warning')->with($this->stringContains('not enabled'));
		$this->step($mapper, false)->run($output);

		$failing = $this->createMock(VerwerkingsactiviteitMapper::class);
		$failing->method('findByCode')->willThrowException(new \RuntimeException('no table'));
		$second = $this->createMock(IOutput::class);
		$second->expects($this->once())->method('warning')->with($this->stringContains('could not be seeded'));
		$this->step($failing)->run($second);

	}//end testItWarnsInsteadOfFailing()
}//end class
