<?php

/**
 * Unit tests for WooRequestService.
 *
 * The record is what a statutory term hangs on, so the tests here are about the
 * two things that make it usable: a request that arrived is stored with a
 * reference nobody else can address, and a report that counts a term met or
 * missed never flatters the organisation by counting an undecided request as met.
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

namespace Unit\Service\Woo;

use DateTimeImmutable;
use DomainException;
use OCA\OpenCatalogi\Service\Woo\StatutoryTerm;
use OCA\OpenCatalogi\Service\Woo\WooRequestService;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for WooRequestService.
 */
class WooRequestServiceTest extends TestCase {

	private WooRequestService $service;

	/**
	 * Build the service under test.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->service = new WooRequestService();

	}//end setUp()

	/**
	 * A request that says what it asks for is stored, with a minted reference.
	 *
	 * @return void
	 */
	public function testAReceivedRequestIsMintedWithAReference(): void {
		$record = $this->service->receive(
			input: [
				'requestedInformation' => 'Every email about the bridge contract.',
				'requesterName' => 'J. de Vries',
				'requesterEmail' => 'j@example.org',
				'channel' => 'web',
			],
			receivedBy: 'alice',
			now: new DateTimeImmutable('2026-03-02T09:00:00+00:00')
		);

		$this->assertMatchesRegularExpression('/^WOO-2026-[0-9A-F]{6}$/', $record['reference']);
		$this->assertSame('2026-03-02T09:00:00+00:00', $record['receivedAt']);
		$this->assertSame('received', $record['status']);
		$this->assertSame('web', $record['channel']);
		$this->assertSame('alice', $record['receivedBy']);
		$this->assertSame(StatutoryTerm::BASIS_TERM, $record['termBasis']);

	}//end testAReceivedRequestIsMintedWithAReference()

	/**
	 * Neither nullable date-time property is WRITTEN as null.
	 *
	 * Both are `format: date-time` in the shipped schema. A null written against
	 * a date-time passes every unit test and is refused by the register the first
	 * time it runs live, which is a class of bug this project has shipped four
	 * times.
	 *
	 * @return void
	 */
	public function testNoNullIsWrittenAgainstADateTimeProperty(): void {
		$record = $this->service->receive(
			input: ['requestedInformation' => 'Anything.'],
			receivedBy: 'alice'
		);

		$this->assertArrayNotHasKey('dueAt', $record, 'dueAt must be absent, never null, until a term is armed.');
		$this->assertArrayNotHasKey('decidedAt', $record, 'decidedAt must be absent, never null, until a decision is made.');

		foreach ($record as $key => $value) {
			$this->assertNotNull($value, 'No property may be written as null: ' . $key);
		}

	}//end testNoNullIsWrittenAgainstADateTimeProperty()

	/**
	 * Two requests never get the same reference.
	 *
	 * @return void
	 */
	public function testReferencesDoNotCollide(): void {
		$seen = [];
		for ($i = 0; $i < 200; $i++) {
			$seen[] = $this->service->mintReference(now: new DateTimeImmutable('2026-03-02T09:00:00+00:00'));
		}

		$this->assertCount(200, array_unique($seen));

	}//end testReferencesDoNotCollide()

	/**
	 * A request that asks for nothing is refused.
	 *
	 * @return void
	 */
	public function testARequestForNothingIsRefused(): void {
		$this->expectException(DomainException::class);
		$this->service->receive(input: ['requestedInformation' => '   '], receivedBy: 'alice');

	}//end testARequestForNothingIsRefused()

	/**
	 * An unknown channel is refused, not read as the nearest one.
	 *
	 * @return void
	 */
	public function testAnUnknownChannelIsRefused(): void {
		$this->expectException(DomainException::class);
		$this->service->receive(
			input: ['requestedInformation' => 'Anything.', 'channel' => 'carrier-pigeon'],
			receivedBy: 'alice'
		);

	}//end testAnUnknownChannelIsRefused()

	/**
	 * The term is recorded on the request, and an absent due moment leaves the
	 * property absent rather than null.
	 *
	 * @return void
	 */
	public function testWithTermRecordsTheTermAndNeverWritesANullDueDate(): void {
		$record = $this->service->receive(input: ['requestedInformation' => 'Anything.'], receivedBy: 'alice');

		$armed = $this->service->withTerm(
			request: $record,
			term: ['timer' => 't-1', 'dueAt' => '2026-03-30T09:00:00+00:00', 'extensionCount' => 0, 'basis' => 'Woo art. 4.4 lid 1']
		);
		$this->assertSame('t-1', $armed['termTimer']);
		$this->assertSame('2026-03-30T09:00:00+00:00', $armed['dueAt']);

		// A SUSPENDED term has no fire moment at all, which is exactly the case
		// that would otherwise write a null into a date-time property.
		$suspended = $this->service->withTerm(
			request: $armed,
			term: ['timer' => 't-1', 'dueAt' => null, 'extensionCount' => 0]
		);
		$this->assertArrayNotHasKey('dueAt', $suspended);

	}//end testWithTermRecordsTheTermAndNeverWritesANullDueDate()

	/**
	 * The receipt carries the clock, and says so when there is no clock.
	 *
	 * @return void
	 */
	public function testTheReceiptCarriesTheReferenceAndTheDueDate(): void {
		$receipt = $this->service->receipt(
			request: [
				'reference' => 'WOO-2026-ABCDEF',
				'receivedAt' => '2026-03-02T09:00:00+00:00',
				'dueAt' => '2026-03-30T09:00:00+00:00',
			]
		);

		$this->assertSame('WOO-2026-ABCDEF', $receipt['reference']);
		$this->assertSame('2026-03-30T09:00:00+00:00', $receipt['dueAt']);
		$this->assertSame(28, $receipt['termDays']);
		$this->assertSame(14, $receipt['extensionDays']);
		$this->assertTrue($receipt['termArmed']);

		$unarmed = $this->service->receipt(request: ['reference' => 'WOO-2026-ABCDEF']);
		$this->assertFalse($unarmed['termArmed'], 'A receipt with no due date must say the term was not armed.');
		$this->assertNull($unarmed['dueAt']);

	}//end testTheReceiptCarriesTheReferenceAndTheDueDate()

	/**
	 * A batch is attached to the request, and the request moves on.
	 *
	 * @return void
	 */
	public function testAttachingABatchMovesTheRequestOn(): void {
		$attached = $this->service->attachBatch(
			request: ['status' => 'received'],
			batchUuid: 'batch-7'
		);

		$this->assertSame('batch-7', $attached['batch']);
		$this->assertSame('in_progress', $attached['status']);

	}//end testAttachingABatchMovesTheRequestOn()

	/**
	 * Attaching no batch is refused.
	 *
	 * @return void
	 */
	public function testAttachingNoBatchIsRefused(): void {
		$this->expectException(DomainException::class);
		$this->service->attachBatch(request: ['status' => 'received'], batchUuid: '  ');

	}//end testAttachingNoBatchIsRefused()

	/**
	 * The report counts met, missed, running and suspended, and never reports a
	 * share of nothing as full compliance.
	 *
	 * @return void
	 */
	public function testTermsReportCountsEveryOutcome(): void {
		$now = new DateTimeImmutable('2026-04-01T00:00:00+00:00');

		$report = $this->service->termsReport(
			requests: [
				// Decided inside the term.
				['reference' => 'A', 'status' => 'decided', 'dueAt' => '2026-03-30T00:00:00+00:00', 'decidedAt' => '2026-03-20T00:00:00+00:00'],
				// Decided after the term.
				['reference' => 'B', 'status' => 'decided', 'dueAt' => '2026-03-10T00:00:00+00:00', 'decidedAt' => '2026-03-20T00:00:00+00:00'],
				// Undecided and already past its due date: MISSED, not running.
				['reference' => 'C', 'status' => 'in_progress', 'dueAt' => '2026-03-20T00:00:00+00:00'],
				// Undecided with time left.
				['reference' => 'D', 'status' => 'in_progress', 'dueAt' => '2026-04-20T00:00:00+00:00'],
				// Held while clarification is awaited.
				['reference' => 'E', 'status' => 'awaiting_clarification'],
				// No readable term at all: named as a gap, never counted as met.
				['reference' => 'F', 'status' => 'in_progress'],
			],
			now: $now
		);

		$this->assertSame(6, $report['total']);
		$this->assertSame(
			['met' => 1, 'missed' => 2, 'running' => 1, 'suspended' => 1, 'unknown' => 1],
			$report['counts']
		);
		$this->assertSame(round((1 / 3), 4), $report['metShare']);

	}//end testTermsReportCountsEveryOutcome()

	/**
	 * With nothing decided, the met share is absent rather than full.
	 *
	 * @return void
	 */
	public function testAShareOfNothingIsNotFullCompliance(): void {
		$report = $this->service->termsReport(
			requests: [['reference' => 'A', 'status' => 'in_progress', 'dueAt' => '2099-01-01T00:00:00+00:00']],
			now: new DateTimeImmutable('2026-04-01T00:00:00+00:00')
		);

		$this->assertNull($report['metShare']);

	}//end testAShareOfNothingIsNotFullCompliance()
}//end class
