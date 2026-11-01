<?php

/**
 * Unit tests for RetentionQueue.
 *
 * @category Test
 * @package  OCA\OpenCatalogi\Tests
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

namespace Unit\Service;

use DateTimeImmutable;
use DateTimeZone;
use OCA\OpenCatalogi\Service\RetentionQueue;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for RetentionQueue.
 *
 * @covers \OCA\OpenCatalogi\Service\RetentionQueue
 */
class RetentionQueueTest extends TestCase {

	/**
	 * The moment every case runs at.
	 *
	 * @return DateTimeImmutable
	 */
	private static function now(): DateTimeImmutable {
		return new DateTimeImmutable('2026-06-15T12:00:00', new DateTimeZone('UTC'));
	}//end now()

	/**
	 * The query scopes the search and ends at the warning window.
	 *
	 * @return void
	 */
	public function testQueryEndsAtTheWarningWindow(): void {
		$query = (new RetentionQueue(now: self::now()))->query(scope: ['register' => '5', 'schema' => '6'], windowDays: 30);

		$this->assertSame(
			[
				'@self' => ['register' => '5', 'schema' => '6'],
				'retentionExpiresAt' => ['$lte' => '2026-07-15T12:00:00+00:00'],
				'_limit' => 1000,
			],
			$query
		);
	}//end testQueryEndsAtTheWarningWindow()

	/**
	 * Publications and the count each adds to.
	 *
	 * @return array<string, array{0: array<string, mixed>, 1: string|null}>
	 */
	public static function buckets(): array {
		return [
			'archived' => [['status' => 'archived', 'retentionExpiresAt' => '2000-01-01'], 'archived'],
			'no expiry' => [['status' => 'published'], null],
			'empty expiry' => [['retentionExpiresAt' => ''], null],
			'unreadable expiry' => [['retentionExpiresAt' => 'not a date'], null],
			'future expiry' => [['retentionExpiresAt' => '2026-06-20T00:00:00+00:00'], 'expiringSoon'],
			'expired, default action' => [['retentionExpiresAt' => '2026-06-01T00:00:00+00:00'], 'reviewRequired'],
			'expired at now' => [['retentionExpiresAt' => '2026-06-15T12:00:00+00:00'], 'reviewRequired'],
			'expired, review action' => [['retentionExpiresAt' => '2026-06-01', 'retentionAction' => 'review'], 'reviewRequired'],
			'expired, other action' => [['retentionExpiresAt' => '2026-06-01', 'retentionAction' => 'depublish'], null],
		];
	}//end buckets()

	/**
	 * Each publication adds to the count its status, expiry and action call for.
	 *
	 * @param array<string, mixed> $data   The publication.
	 * @param string|null          $bucket The expected summary key.
	 *
	 * @return void
	 */
	#[DataProvider('buckets')]
	public function testBucket(array $data, ?string $bucket): void {
		$this->assertSame($bucket, (new RetentionQueue(now: self::now()))->bucket(data: $data));
	}//end testBucket()
}//end class
