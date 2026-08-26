<?php
/**
 * Unit tests for the RemoveRetiredCronJobs repair step.
 *
 * The step exists because #1119 moved this app's background jobs out of the
 * `OCA\OpenCatalogi\Cron` namespace, and a class rename orphans its `oc_jobs`
 * row: `info.xml`'s `<job>` entries ADD registrations on upgrade and never
 * remove one whose class disappeared. Measured on a live instance carrying the
 * merge — `oc_jobs` held `Cron\DirectorySync` and `Cron\RetentionEvaluation`
 * beside their replacements.
 *
 * A repair step is exactly the kind of code that must fail loudly in tests and
 * quietly in production: it runs during `occ upgrade`, so an exception here
 * aborts an upgrade. These tests assert on WHICH strings are passed and on the
 * continue-after-failure behaviour, because both are invisible at runtime — the
 * step has no return value, and a step that removed the wrong names would look
 * identical to one that worked.
 *
 * @category Test
 * @package  OCA\OpenCatalogi\Tests
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace Unit\Repair;

use OCA\OpenCatalogi\Repair\RemoveRetiredCronJobs;
use OCP\BackgroundJob\IJobList;
use OCP\Migration\IOutput;
use OCP\Migration\IRepairStep;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * @covers \OCA\OpenCatalogi\Repair\RemoveRetiredCronJobs
 *
 * @spec exclude No canonical spec covers the OCA\OpenCatalogi\Cron ->
 *  OCA\OpenCatalogi\BackgroundJob move; ADR-100 Decision 3 is an architecture
 *  record, not a capability spec. Pointing this at an existing spec would
 *  report conformance to a requirement that says nothing about it.
 */
final class RemoveRetiredCronJobsTest extends TestCase {

	/**
	 * The step removes the retired classes, by their full OLD names.
	 *
	 * Asserts the ARGUMENTS, not the call count. The whole value of the step is
	 * which strings it passes: a version that removed the NEW class names would
	 * satisfy a count-only assertion while deleting the two registrations the
	 * app actually depends on.
	 *
	 * @return void
	 */
	public function testRemovesTheRetiredClassesByName(): void {
		$removed = [];

		$jobList = $this->createMock(IJobList::class);
		$jobList->method('remove')->willReturnCallback(
			static function ($class) use (&$removed): void {
				$removed[] = $class;
			}
		);

		$step = new RemoveRetiredCronJobs($jobList, $this->createMock(LoggerInterface::class));
		$step->run($this->createMock(IOutput::class));

		$this->assertSame(
			[
				'OCA\OpenCatalogi\Cron\DirectorySync',
				'OCA\OpenCatalogi\Cron\RetentionEvaluation',
				'OCA\OpenCatalogi\Cron\Broadcast',
			],
			$removed,
			'the step must remove the OLD fully-qualified names; removing the new ones would '
			. 'delete the registrations the app depends on'
		);

	}//end testRemovesTheRetiredClassesByName()

	/**
	 * No surviving class name is touched.
	 *
	 * The failure this guards is a careless widening — a future edit that
	 * removes by short name, or by a `Cron`-substring match, would take the
	 * live `BackgroundJob\*` registrations with it and stop the app's real jobs
	 * without any error.
	 *
	 * @return void
	 */
	public function testNeverRemovesASurvivingRegistration(): void {
		$removed = [];

		$jobList = $this->createMock(IJobList::class);
		$jobList->method('remove')->willReturnCallback(
			static function ($class) use (&$removed): void {
				$removed[] = $class;
			}
		);

		$step = new RemoveRetiredCronJobs($jobList, $this->createMock(LoggerInterface::class));
		$step->run($this->createMock(IOutput::class));

		foreach ($removed as $class) {
			$this->assertStringNotContainsString(
				'BackgroundJob',
				$class,
				'a live BackgroundJob registration must never be removed: ' . $class
			);
		}

	}//end testNeverRemovesASurvivingRegistration()

	/**
	 * A failure on one class does not abort the step or the upgrade.
	 *
	 * A repair step that raises takes the whole `occ upgrade` with it. Trading
	 * a dormant orphaned row for an instance that will not start is the worse
	 * outcome, so the step reports and continues — and "continues" is exactly
	 * the behaviour a later refactor would quietly drop.
	 *
	 * @return void
	 */
	public function testAFailureOnOneClassDoesNotStopTheRest(): void {
		$attempted = [];

		$jobList = $this->createMock(IJobList::class);
		$jobList->method('remove')->willReturnCallback(
			static function ($class) use (&$attempted): void {
				$attempted[] = $class;
				if (str_contains($class, 'DirectorySync') === true) {
					throw new RuntimeException('database is gone');
				}
			}
		);

		$step = new RemoveRetiredCronJobs($jobList, $this->createMock(LoggerInterface::class));
		$step->run($this->createMock(IOutput::class));

		$this->assertContains(
			'OCA\OpenCatalogi\Cron\RetentionEvaluation',
			$attempted,
			'a failure removing the first class must not skip the rest'
		);

	}//end testAFailureOnOneClassDoesNotStopTheRest()

	/**
	 * The step is a repair step and names itself.
	 *
	 * @return void
	 */
	public function testItIsARepairStepWithAName(): void {
		$step = new RemoveRetiredCronJobs(
			$this->createMock(IJobList::class),
			$this->createMock(LoggerInterface::class)
		);

		$this->assertInstanceOf(IRepairStep::class, $step);
		$this->assertNotSame('', trim($step->getName()));

	}//end testItIsARepairStepWithAName()
}//end class
