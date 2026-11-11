<?php

/**
 * Golden test for the terms report of WooRequestService::termsReport(): each
 * request's outcome at the boundaries (decided on the due date, due exactly
 * now), the counts, the share met and every report row.
 *
 * @category Test
 * @package  OCA\OpenCatalogi\Tests\Unit\Service\Woo
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://www.OpenCatalogi.nl
 */

declare(strict_types=1);

namespace OCA\OpenCatalogi\Tests\Unit\Service\Woo;

use DateTimeImmutable;
use OCA\OpenCatalogi\Service\Woo\WooRequestService;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\OpenCatalogi\Service\Woo\WooRequestService
 */
class WooTermsReportGoldenTest extends TestCase {

	/**
	 * Every outcome, at its boundaries, and the whole report.
	 *
	 * @return void
	 */
	public function testTheWholeReport(): void {
		$now = '2026-06-01T12:00:00+00:00';
		$requests = [
			['reference' => 'A', 'status' => 'decided', 'dueAt' => '2026-05-01T00:00:00+00:00', 'decidedAt' => '2026-05-01T00:00:00+00:00', 'extensionCount' => '1'],
			['reference' => 'B', 'status' => 'decided', 'dueAt' => '2026-05-01T00:00:00+00:00', 'decidedAt' => '2026-05-01T00:00:01+00:00'],
			['reference' => 'C', 'status' => 'decided', 'decidedAt' => '2026-05-01T00:00:00+00:00'],
			['reference' => 'D', 'status' => 'in_progress', 'dueAt' => $now],
			['reference' => 'E', 'status' => 'in_progress', 'dueAt' => '2026-06-01T11:59:59+00:00'],
			['reference' => 'F', 'status' => 'awaiting_clarification', 'dueAt' => '2026-01-01T00:00:00+00:00', 'decidedAt' => '2026-07-01T00:00:00+00:00'],
			['reference' => 'G', 'status' => 'in_progress', 'dueAt' => 'not a date'],
			['status' => 'in_progress', 'dueAt' => '2026-06-02T00:00:00+00:00', 'decidedAt' => '  '],
		];

		$row = static fn (array $request, string $outcome): array => [
			'reference' => (string)($request['reference'] ?? ''),
			'status' => (string)($request['status'] ?? ''),
			'dueAt' => ($request['dueAt'] ?? null),
			'decidedAt' => ($request['decidedAt'] ?? null),
			'extensionCount' => (int)($request['extensionCount'] ?? 0),
			'outcome' => $outcome,
		];

		$this->assertSame(
			[
				'total' => 8,
				'counts' => ['met' => 1, 'missed' => 2, 'running' => 2, 'suspended' => 1, 'unknown' => 2],
				'metShare' => 0.3333,
				'requests' => [
					$row($requests[0], 'met'),
					$row($requests[1], 'missed'),
					$row($requests[2], 'unknown'),
					$row($requests[3], 'running'),
					$row($requests[4], 'missed'),
					$row($requests[5], 'suspended'),
					$row($requests[6], 'unknown'),
					$row($requests[7], 'running'),
				],
			],
			(new WooRequestService())->termsReport($requests, new DateTimeImmutable($now))
		);
		$this->assertSame(1, (new WooRequestService())->termsReport($requests, new DateTimeImmutable($now))['requests'][0]['extensionCount']);

	}//end testTheWholeReport()

	/**
	 * Nothing decided yet answers no share rather than a perfect one.
	 *
	 * @return void
	 */
	public function testNothingDecidedHasNoShare(): void {
		$report = (new WooRequestService())->termsReport([], new DateTimeImmutable('2026-06-01T12:00:00+00:00'));
		$this->assertSame(['total' => 0, 'counts' => ['met' => 0, 'missed' => 0, 'running' => 0, 'suspended' => 0, 'unknown' => 0], 'metShare' => null, 'requests' => []], $report);

	}//end testNothingDecidedHasNoShare()
}//end class
