<?php

/**
 * Unit tests for the outcome of one broadcast.
 *
 * @category Test
 * @package  OCA\OpenCatalogi\Tests\Unit\Service\Broadcast
 * @author   Conduction Development Team <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://www.OpenCatalogi.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenCatalogi\Tests\Unit\Service\Broadcast;

use OCA\OpenCatalogi\Service\Broadcast\BroadcastResult;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * A delivered or enqueued broadcast counts as a success; anything else does not.
 *
 * @covers \OCA\OpenCatalogi\Service\Broadcast\BroadcastResult
 */
class BroadcastResultTest extends TestCase {

	/**
	 * Statuses and whether they are a success.
	 *
	 * @return array<string,array{0:string,1:bool}>
	 */
	public static function statuses(): array {
		return [
			'delivered'        => [BroadcastResult::STATUS_DELIVERED, true],
			'enqueued'         => [BroadcastResult::STATUS_ENQUEUED, true],
			'failed'           => [BroadcastResult::STATUS_FAILED, false],
			'unknown'          => ['pending', false],
			'empty'            => ['', false],
			'other letter case' => ['Delivered', false],
		];
	}//end statuses()

	/**
	 * Only the delivered and enqueued statuses are a success.
	 *
	 * @param string $status   The status.
	 * @param bool   $expected Whether it is a success.
	 *
	 * @return void
	 */
	#[DataProvider('statuses')]
	public function testIsSuccessful(string $status, bool $expected): void {
		$result = new BroadcastResult(url: 'https://directory.example.org/api/directory', status: $status);

		$this->assertSame($expected, $result->isSuccessful());
		$this->assertSame('https://directory.example.org/api/directory', $result->url);
		$this->assertSame($status, $result->status);
	}//end testIsSuccessful()
}//end class
