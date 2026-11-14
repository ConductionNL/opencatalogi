<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace Unit\Command;

use OCA\OpenCatalogi\Command\DirectoryBroadcastCommand;
use OCA\OpenCatalogi\Contract\BroadcastServiceInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * `occ opencatalogi:directory:broadcast` broadcasts to every known directory (REQ-IMC-001).
 *
 * @covers \OCA\OpenCatalogi\Command\DirectoryBroadcastCommand
 * @covers \OCA\OpenCatalogi\Command\MaintenanceCommand
 *
 * @spec openspec/changes/integration-maintenance-commands/specs/maintenance-commands/spec.md
 */
class DirectoryBroadcastCommandTest extends TestCase {

	/**
	 * Every target is printed with its outcome; one refusal exits 1.
	 *
	 * @return void
	 */
	public function testBroadcastsToEveryDirectoryAndExitsOneOnAFailure(): void {
		$service = $this->createMock(BroadcastServiceInterface::class);
		$service->expects($this->once())->method('broadcast')->with(null)->willReturn(['https://a.example' => true, 'https://b.example' => false]);

		$tester = new CommandTester(new DirectoryBroadcastCommand($service));

		$this->assertSame(1, $tester->execute([]));
		$this->assertSame("OK https://a.example\nFAILED https://b.example\nBroadcast to 2 directories, 1 failed.\n", $tester->getDisplay());
	}//end testBroadcastsToEveryDirectoryAndExitsOneOnAFailure()

	/**
	 * All accepted exits 0, and --output=json prints the per-target map.
	 *
	 * @return void
	 */
	public function testAllAcceptedExitsZeroAndJsonIsTheMap(): void {
		$service = $this->createMock(BroadcastServiceInterface::class);
		$service->method('broadcast')->willReturn(['https://a.example' => true]);

		$tester = new CommandTester(new DirectoryBroadcastCommand($service));

		$this->assertSame(0, $tester->execute(['--output' => 'json']));
		$this->assertSame(['https://a.example' => true], json_decode($tester->getDisplay(), true));
	}//end testAllAcceptedExitsZeroAndJsonIsTheMap()

	/**
	 * No known directory is not a failure.
	 *
	 * @return void
	 */
	public function testNoTargetsExitsZero(): void {
		$service = $this->createMock(BroadcastServiceInterface::class);
		$service->method('broadcast')->willReturn([]);

		$tester = new CommandTester(new DirectoryBroadcastCommand($service));

		$this->assertSame(0, $tester->execute([]));
		$this->assertSame("Broadcast to 0 directories, 0 failed.\n", $tester->getDisplay());
	}//end testNoTargetsExitsZero()

}//end class
