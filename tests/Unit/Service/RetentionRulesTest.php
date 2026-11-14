<?php

/**
 * Unit tests for RetentionRules.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace Unit\Service;

use DateTimeImmutable;
use DateTimeZone;
use OCA\OpenCatalogi\Service\RetentionRules;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * The retention date arithmetic, the decision outcomes and the defaults check,
 * all against a pinned "now".
 *
 * @covers \OCA\OpenCatalogi\Service\RetentionRules
 */
class RetentionRulesTest extends TestCase {

	private const NOW = '2026-11-13T08:00:00+00:00';

	private RetentionRules $rules;

	protected function setUp(): void {
		$this->rules = new RetentionRules(new DateTimeImmutable(self::NOW, new DateTimeZone('UTC')));
	}

	public function testDefaultsWithKnownActionsAreAccepted(): void {
		$this->rules->assertValidDefaults(['woo' => ['infocat001' => ['action' => 'archive', 'months' => 12], 'infocat002' => 'plain']]);
		$this->addToAssertionCount(1);
	}

	public function testDefaultsThatAreNotPerCategoryAreRefused(): void {
		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('Invalid retention defaults shape for catalog woo');

		$this->rules->assertValidDefaults(['woo' => 'archive']);
	}

	public function testAnUnknownDefaultActionIsRefusedWithItsPlace(): void {
		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('Invalid retention action "shred" for woo/infocat003');

		$this->rules->assertValidDefaults(['woo' => ['infocat003' => ['action' => 'shred']]]);
	}

	public function testTheExpiryAddsTheTermInMonths(): void {
		$this->assertSame('2027-05-01T00:00:00+00:00', $this->rules->expiry('2026-11-01T00:00:00+00:00', 6));
	}

	public function testNoExpiryWithoutADateOrAPositiveTerm(): void {
		$this->assertNull($this->rules->expiry(null, 6));
		$this->assertNull($this->rules->expiry('', 6));
		$this->assertNull($this->rules->expiry('not a date', 6));
		$this->assertNull($this->rules->expiry('2026-11-01', 0));
		$this->assertNull($this->rules->expiry('2026-11-01', null));
	}

	public function testExtendingAddsMonthsToTheCurrentExpiryAndResetsTheEvaluation(): void {
		$data = $this->rules->afterDecision(
			['retentionExpiresAt' => '2027-01-31T00:00:00+00:00', 'retentionLastEvaluatedAt' => '2026-11-01T00:00:00+00:00'],
			['decision' => 'extend', 'months' => 12, 'note' => 'still relevant']
		);

		$this->assertSame('2028-01-31T00:00:00+00:00', $data['retentionExpiresAt']);
		$this->assertSame('still relevant', $data['retentionNote']);
		$this->assertSame('', $data['retentionLastEvaluatedAt']);
	}

	public function testExtendingWithoutAnExpiryCountsFromNow(): void {
		$data = $this->rules->afterDecision([], ['decision' => 'extend', 'months' => 1, 'note' => '']);

		$this->assertSame('2026-12-13T08:00:00+00:00', $data['retentionExpiresAt']);
	}

	public function testExtendingByNoMonthsIsRefused(): void {
		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('Extend requires a positive number of months');

		$this->rules->afterDecision([], ['decision' => 'extend', 'months' => 0, 'note' => '']);
	}

	public function testDepublishingKeepsTheStatusAndStampsTheDepublicationDate(): void {
		$data = $this->rules->afterDecision(['status' => 'published', 'title' => 'Besluit'], ['decision' => 'depublish']);

		$this->assertSame(['status' => 'published', 'title' => 'Besluit', 'depublicationDate' => self::NOW], $data);
	}

	public function testArchivingAndDisposingArchive(): void {
		$this->assertSame('archived', $this->rules->afterDecision(['status' => 'published'], ['decision' => 'archive'])['status']);
		$this->assertSame('archived', $this->rules->afterDecision(['status' => 'published'], ['decision' => 'dispose'])['status']);
	}

	public function testAnUnknownDecisionIsRefused(): void {
		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('Unknown retention decision: keep');

		$this->rules->afterDecision([], ['decision' => 'keep']);
	}

	public function testAnExpiredWithdrawalRecordsTheEvaluation(): void {
		$data = $this->rules->expiredWithdrawal(['status' => 'published'], 'archive');

		$this->assertSame(['status' => 'archived', 'depublicationDate' => self::NOW, 'retentionLastEvaluatedAt' => self::NOW], $data);
	}

	public function testADecisionIsAppendedToTheLogWithItsBasis(): void {
		$data = $this->rules->withDecision(
			['retentionCategory' => 'infocat001', 'retentionDecisionLog' => [['decision' => 'extend']]],
			['decision' => 'archive', 'by' => 'admin', 'note' => 'done']
		);

		$this->assertSame(
			[
				['decision' => 'extend'],
				['decision' => 'archive', 'by' => 'admin', 'at' => self::NOW, 'note' => 'done', 'basis' => 'infocat001'],
			],
			$data['retentionDecisionLog']
		);
	}

	public function testAMalformedLogIsStartedOver(): void {
		$data = $this->rules->withDecision(['retentionDecisionLog' => 'broken'], ['decision' => 'depublish', 'by' => 'u', 'note' => '']);

		$this->assertCount(1, $data['retentionDecisionLog']);
		$this->assertSame('', $data['retentionDecisionLog'][0]['basis']);
	}

	public function testTheObjectIdIsFilledFromSelfOnlyWhenMissing(): void {
		$this->assertSame(['id' => 'uuid-1'], $this->rules->withObjectId(['uuid' => 'uuid-1', 'id' => 7], []));
		$this->assertSame(['id' => '7'], $this->rules->withObjectId(['id' => '7'], []));
		$this->assertSame(['id' => 'kept'], $this->rules->withObjectId(['uuid' => 'uuid-1'], ['id' => 'kept']));
		$this->assertSame([], $this->rules->withObjectId([], []));
	}
}
