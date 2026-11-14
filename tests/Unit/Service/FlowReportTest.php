<?php

/**
 * Unit tests for FlowReport.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace Unit\Service;

use DateTimeImmutable;
use OCA\OpenCatalogi\Service\FlowReport;
use PHPUnit\Framework\TestCase;

/**
 * A flow run is summarised with ATOM dates and its failed-step count, and the
 * schedule is read from the flow's trigger node.
 *
 * @covers \OCA\OpenCatalogi\Service\FlowReport
 */
class FlowReportTest extends TestCase {

	/**
	 * A run double answering the given values.
	 *
	 * @param array<string, mixed> $values Getter name => value.
	 */
	private function flowRun(array $values): object {
		return new class($values) {
			public function __construct(private array $values) {
			}

			public function __call(string $name, array $arguments): mixed {
				return ($this->values[$name] ?? null);
			}
		};
	}

	public function testARunIsSummarisedWithItsFailedSteps(): void {
		$summary = (new FlowReport())->runSummary(
			$this->flowRun(
				[
					'getUuid' => 'run-1',
					'getStatus' => 'finished',
					'getTrigger' => 'schedule',
					'getCreated' => new DateTimeImmutable('2026-11-13T06:00:00+00:00'),
					'getUpdated' => new DateTimeImmutable('2026-11-13T06:05:00+00:00'),
					'getResumeAt' => null,
					'getError' => null,
					'getLog' => [['status' => 'done'], ['status' => 'failed'], ['status' => 'failed'], ['status' => 'done'], ['status' => 'done']],
				]
			)
		);

		$this->assertSame(
			[
				'uuid' => 'run-1',
				'status' => 'finished',
				'trigger' => 'schedule',
				'started' => '2026-11-13T06:00:00+00:00',
				'updated' => '2026-11-13T06:05:00+00:00',
				'resumeAt' => null,
				'error' => null,
				'failedSteps' => 2,
			],
			$summary
		);
	}

	public function testARunWithoutLogOrDatesHasNoFailedSteps(): void {
		$summary = (new FlowReport())->runSummary($this->flowRun(['getCreated' => '2026-11-13', 'getError' => 'timeout']));

		$this->assertNull($summary['started']);
		$this->assertSame('timeout', $summary['error']);
		$this->assertSame(0, $summary['failedSteps']);
	}

	public function testTheScheduleComesFromTheTriggerNode(): void {
		$schedule = (new FlowReport())->schedule(
			[
				['type' => 'openregister.http-request', 'config' => ['cron' => 'ignored']],
				['type' => 'openregister.trigger-schedule', 'config' => ['runAs' => 'admin', 'cron' => '0 3 * * *']],
			]
		);

		$this->assertSame(['runAs' => 'admin', 'cron' => '0 3 * * *'], $schedule);
	}

	public function testAFlowWithoutTriggerHasNoSchedule(): void {
		$this->assertSame(['runAs' => null, 'cron' => null], (new FlowReport())->schedule([['config' => []]]));
		$this->assertSame(['runAs' => null, 'cron' => null], (new FlowReport())->schedule([['type' => 'openregister.trigger-schedule']]));
	}
}
