<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace Unit\Command;

use OCA\OpenCatalogi\Command\RetentionEvaluateCommand;
use OCA\OpenCatalogi\Contract\RetentionServiceInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * `occ opencatalogi:retention:evaluate` reports first and acts only with --apply (REQ-IMC-002).
 *
 * @covers \OCA\OpenCatalogi\Command\RetentionEvaluateCommand
 * @covers \OCA\OpenCatalogi\Command\MaintenanceCommand
 *
 * @spec openspec/changes/integration-maintenance-commands/specs/maintenance-commands/spec.md
 */
class RetentionEvaluateCommandTest extends TestCase {

	/**
	 * Without --apply it asks for a dry run and lists each publication with its action.
	 *
	 * @return void
	 */
	public function testWithoutApplyItListsWhatWouldHappen(): void {
		$service = $this->createMock(RetentionServiceInterface::class);
		$service->expects($this->once())->method('evaluate')->with(true)->willReturn([
			'expiringSoon' => 0,
			'reviewRequired' => 1,
			'depublished' => 1,
			'archived' => 0,
			'planned' => [
				['id' => 'p1', 'title' => 'Permit', 'action' => 'depublished'],
				['id' => 'p2', 'title' => 'Letter', 'action' => 'reviewRequired'],
			],
		]);

		$tester = new CommandTester(new RetentionEvaluateCommand($service));

		$this->assertSame(0, $tester->execute([]));
		$this->assertSame(
			"depublished p1 (Permit)\nreviewRequired p2 (Letter)\n"
			. "Dry run, nothing changed: 0 expiring soon, 1 for review, 1 depublished, 0 archived.\n",
			$tester->getDisplay()
		);
	}//end testWithoutApplyItListsWhatWouldHappen()

	/**
	 * With --apply it runs the evaluation the daily job runs and says so in the JSON.
	 *
	 * @return void
	 */
	public function testApplyActsAsTheDailyJob(): void {
		$counts = ['expiringSoon' => 2, 'reviewRequired' => 0, 'depublished' => 0, 'archived' => 1];
		$service = $this->createMock(RetentionServiceInterface::class);
		$service->expects($this->once())->method('evaluate')->with(false)->willReturn($counts);

		$tester = new CommandTester(new RetentionEvaluateCommand($service));

		$this->assertSame(0, $tester->execute(['--apply' => true, '--output' => 'json']));
		$this->assertSame($counts + ['applied' => true], json_decode($tester->getDisplay(), true));
	}//end testApplyActsAsTheDailyJob()

	/**
	 * A wrong output format is refused before anything is evaluated.
	 *
	 * @return void
	 */
	public function testAWrongFormatEvaluatesNothing(): void {
		$service = $this->createMock(RetentionServiceInterface::class);
		$service->expects($this->never())->method('evaluate');

		$this->assertSame(2, (new CommandTester(new RetentionEvaluateCommand($service)))->execute(['--apply' => true, '--output' => 'csv']));
	}//end testAWrongFormatEvaluatesNothing()

}//end class
