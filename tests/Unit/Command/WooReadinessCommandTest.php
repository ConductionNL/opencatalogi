<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace Unit\Command;

use OCA\OpenCatalogi\Command\WooReadinessCommand;
use OCA\OpenCatalogi\Contract\HarvesterReadinessServiceInterface;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * `occ opencatalogi:woo:readiness` prints each check and gates a script on the verdict (REQ-IMC-003).
 *
 * @covers \OCA\OpenCatalogi\Command\WooReadinessCommand
 * @covers \OCA\OpenCatalogi\Command\MaintenanceCommand
 *
 * @spec openspec/changes/integration-maintenance-commands/specs/maintenance-commands/spec.md
 */
class WooReadinessCommandTest extends TestCase {

	/**
	 * A ready report prints each check and exits 0.
	 *
	 * @return void
	 */
	public function testAPassingReportExitsZero(): void {
		$tester = new CommandTester(new WooReadinessCommand($this->readiness(report: [
			'verdict' => 'ready',
			'checks' => [
				['id' => 'diwoo-version', 'status' => 'pass'],
				['id' => 'sitemap', 'status' => 'pass', 'catalogSlug' => 'woo'],
			],
		])));

		$this->assertSame(0, $tester->execute([]));
		$this->assertSame("PASS diwoo-version\nPASS sitemap [woo]\nVerdict: ready\n", $tester->getDisplay());
	}//end testAPassingReportExitsZero()

	/**
	 * A failed robots.txt check is printed with its reason and exits 1.
	 *
	 * @return void
	 */
	public function testAFailingReportExitsOneAndNamesTheReason(): void {
		$report = [
			'verdict' => 'not-ready',
			'checks' => [
				['id' => 'robots-txt', 'status' => 'fail', 'reason' => 'http-404'],
				['id' => 'sitemap', 'status' => 'skipped', 'reason' => 'robots-txt-failed', 'catalogSlug' => 'woo'],
			],
		];
		$tester = new CommandTester(new WooReadinessCommand($this->readiness(report: $report)));

		$this->assertSame(1, $tester->execute([]));
		$this->assertSame(
			"FAIL robots-txt: http-404\nSKIPPED sitemap [woo]: robots-txt-failed\nVerdict: not-ready\n",
			$tester->getDisplay()
		);

		$this->assertSame(1, $tester->execute(['--output' => 'json']));
		$this->assertSame($report, json_decode($tester->getDisplay(), true));
	}//end testAFailingReportExitsOneAndNamesTheReason()

	/**
	 * Without a Woo-enabled catalogue no check runs and the command exits 1, as the button refuses.
	 *
	 * @return void
	 */
	public function testNoWooCatalogueRunsNoCheck(): void {
		$service = $this->createMock(HarvesterReadinessServiceInterface::class);
		$service->method('hasWooEnabledCatalogs')->willReturn(false);
		$service->expects($this->never())->method('runCheck');

		$tester = new CommandTester(new WooReadinessCommand($service));

		$this->assertSame(1, $tester->execute([]));
		$this->assertSame("No catalogue is enabled for the Woo index, so there is nothing to check.\n", $tester->getDisplay());
	}//end testNoWooCatalogueRunsNoCheck()

	/**
	 * A check that throws exits 1 with the reason.
	 *
	 * @return void
	 */
	public function testAStoppedCheckExitsOne(): void {
		$service = $this->createMock(HarvesterReadinessServiceInterface::class);
		$service->method('hasWooEnabledCatalogs')->willReturn(true);
		$service->method('runCheck')->willThrowException(new RuntimeException('no base URL'));

		$tester = new CommandTester(new WooReadinessCommand($service));

		$this->assertSame(1, $tester->execute([]));
		$this->assertSame("The check stopped: no base URL\n", $tester->getDisplay());
	}//end testAStoppedCheckExitsOne()

	/**
	 * A readiness service with Woo catalogues that answers the report once.
	 *
	 * @param array<string, mixed> $report The report.
	 *
	 * @return HarvesterReadinessServiceInterface The double.
	 */
	private function readiness(array $report): HarvesterReadinessServiceInterface {
		$service = $this->createMock(HarvesterReadinessServiceInterface::class);
		$service->method('hasWooEnabledCatalogs')->willReturn(true);
		$service->expects($this->atLeastOnce())->method('runCheck')->willReturn($report);

		return $service;
	}//end readiness()

}//end class
