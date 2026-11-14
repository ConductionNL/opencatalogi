<?php

/**
 * Unit tests for UsageCsvRollUp.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace Unit\Service;

use OCA\OpenCatalogi\Contract\UsageCounterServiceInterface;
use OCA\OpenCatalogi\Service\UsageCsvRollUp;
use PHPUnit\Framework\TestCase;

/**
 * Counter rows are rolled up to one CSV line per publication, with views and
 * downloads summed and other kinds, negative counts and anonymous rows ignored.
 *
 * @covers \OCA\OpenCatalogi\Service\UsageCsvRollUp
 */
class UsageCsvRollUpTest extends TestCase {

	public function testRowsAreSummedPerPublication(): void {
		$rows = [
			['publication' => 'p-1', 'category' => 'infocat001', 'published' => '2026-01-01', 'kind' => UsageCounterServiceInterface::KIND_VIEW, 'count' => 3],
			['publication' => 'p-1', 'category' => 'other', 'kind' => UsageCounterServiceInterface::KIND_DOWNLOAD, 'count' => '2'],
			['publication' => 'p-1', 'kind' => UsageCounterServiceInterface::KIND_VIEW, 'count' => 4],
			['publication' => 'p-2', 'kind' => UsageCounterServiceInterface::KIND_DOWNLOAD, 'count' => -5],
			['publication' => 'p-2', 'kind' => 'share', 'count' => 9],
			['publication' => '', 'kind' => UsageCounterServiceInterface::KIND_VIEW, 'count' => 1],
			['kind' => UsageCounterServiceInterface::KIND_VIEW, 'count' => 1],
		];

		$this->assertSame(
			[
				'p-1' => ['publication' => 'p-1', 'category' => 'infocat001', 'published' => '2026-01-01', 'views' => 7, 'downloads' => 2],
				'p-2' => ['publication' => 'p-2', 'category' => '', 'published' => '', 'views' => 0, 'downloads' => 0],
			],
			UsageCsvRollUp::rollUp($rows)
		);
	}

	public function testNoRowsRollUpToNothing(): void {
		$this->assertSame([], UsageCsvRollUp::rollUp([]));
	}
}
