<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace Unit\Service\Publication;

require_once __DIR__ . '/TermRollFixture.php';

use DateTimeImmutable;
use DateTimeZone;
use DomainException;
use OCA\OpenCatalogi\Service\Publication\InspectionService;
use OCA\OpenCatalogi\Service\Publication\TermRollUnavailableException;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for InspectionService.
 *
 * @covers \OCA\OpenCatalogi\Service\Publication\InspectionService
 * @uses \OCA\OpenCatalogi\Service\Publication\TermRoll
 */
class InspectionServiceTest extends TestCase {
	use TermRollFixture;

	private InspectionService $service;

	protected function setUp(): void {
		$this->service = new InspectionService($this->termRoll());

	}//end setUp()

	/**
	 * A moment, for the tests that need one.
	 *
	 * @param string $when The moment.
	 *
	 * @return DateTimeImmutable
	 */
	private function at(string $when): DateTimeImmutable {
		return new DateTimeImmutable($when, new DateTimeZone('UTC'));
	}//end at()

	public function testTheEndDateIsComputedFromTheTypesTerm(): void {
		$inspection = $this->service->open(
			record: ['id' => 'r1'],
			recordType: ['slug' => 'omgevingsvergunning', 'inspectionTermDays' => 42],
			documents: ['d1', 'd2'],
			openedBy: 'admin',
			start: $this->at('2026-09-01T00:00:00+00:00')
		);

		$this->assertSame('2026-10-13T00:00:00+00:00', $inspection['endDate']);
		$this->assertSame(42, $inspection['termDays']);
		$this->assertSame(['d1', 'd2'], $inspection['documents']);

	}//end testTheEndDateIsComputedFromTheTypesTerm()

	/**
	 * An inspection whose term ends on a Sunday ends on the Monday (Atw art. 1 lid 1).
	 *
	 * @return void
	 */
	public function testAnEndOnASundayRollsToMonday(): void {
		$inspection = $this->service->open(
			record: ['id' => 'r1'],
			recordType: ['slug' => 'omgevingsvergunning', 'inspectionTermDays' => 42],
			documents: ['d1'],
			openedBy: 'admin',
			start: $this->at('2026-11-01T00:00:00+00:00')
		);

		$this->assertSame('2026-12-14T00:00:00+00:00', $inspection['endDate']);
		$this->assertSame('2026-12-13T00:00:00+00:00', $inspection['unrolledEndDate']);
		$this->assertSame('weekend', $inspection['rolledBy']);
		$this->assertSame('2026-11-01T00:00:00+00:00', $inspection['startDate']);
		$this->assertSame(42, $inspection['termDays']);

	}//end testAnEndOnASundayRollsToMonday()

	/**
	 * An end on Christmas Day rolls past both Christmas days and the weekend (Atw art. 3).
	 *
	 * @return void
	 */
	public function testAnEndOnChristmasRollsPastBothHolidays(): void {
		$inspection = $this->service->open(
			record: ['id' => 'r1'],
			recordType: ['slug' => 'omgevingsvergunning', 'inspectionTermDays' => 24],
			documents: ['d1'],
			openedBy: 'admin',
			start: $this->at('2026-12-01T00:00:00+00:00')
		);

		$this->assertSame('2026-12-28T00:00:00+00:00', $inspection['endDate']);
		$this->assertSame('2026-12-25T00:00:00+00:00', $inspection['unrolledEndDate']);
		$this->assertSame('Kerstmis', $inspection['rolledBy']);

	}//end testAnEndOnChristmasRollsPastBothHolidays()

	/**
	 * With no term engine the inspection is refused, not opened on an unrolled end.
	 *
	 * @return void
	 */
	public function testAnUnreachableEngineRefusesToOpen(): void {
		$service = new InspectionService($this->termRoll(engineAvailable: false));

		$this->expectException(TermRollUnavailableException::class);
		$this->expectExceptionMessage('The term engine is unavailable');

		$service->open(
			record: ['id' => 'r1'],
			recordType: ['slug' => 'omgevingsvergunning', 'inspectionTermDays' => 42],
			documents: ['d1'],
			openedBy: 'admin',
			start: $this->at('2026-11-01T00:00:00+00:00')
		);

	}//end testAnUnreachableEngineRefusesToOpen()

	/**
	 * The link reads the rolled end: past the unrolled Sunday it still works,
	 * up to the rolled Monday, and not after it.
	 *
	 * @return void
	 */
	public function testTheLinkStillWorksOnTheRolledDay(): void {
		$inspection = $this->service->open(
			record: ['id' => 'r1'],
			recordType: ['slug' => 'omgevingsvergunning', 'inspectionTermDays' => 42],
			documents: ['d1'],
			openedBy: 'admin',
			start: $this->at('2026-11-01T00:00:00+00:00')
		);

		$pastTheUnrolledEnd = $this->service->resolveLink(inspection: $inspection, token: $inspection['token'], now: $this->at('2026-12-13T12:00:00+00:00'));
		$this->assertTrue($pastTheUnrolledEnd['readable']);
		$this->assertTrue($this->service->isOpen(inspection: $inspection, now: $this->at('2026-12-14T00:00:00+00:00')));

		$after = $this->service->resolveLink(inspection: $inspection, token: $inspection['token'], now: $this->at('2026-12-14T00:00:01+00:00'));
		$this->assertFalse($after['readable']);
		$this->assertSame('2026-12-14T00:00:00+00:00', $after['endDate']);

	}//end testTheLinkStillWorksOnTheRolledDay()

	public function testTheInspectionIsShapedWholeWithUniqueDocuments(): void {
		$inspection = $this->service->open(
			record: ['title' => 'no id'],
			recordType: ['recordType' => 'aanwijzing', 'inspectionTermDays' => '3'],
			documents: [7, 'd1', '7', 'd1', 'd2'],
			openedBy: 'admin',
			start: new DateTimeImmutable('2026-09-01T12:00:00+02:00')
		);

		$this->assertMatchesRegularExpression('/^[0-9a-f]{32}$/', $inspection['token']);
		unset($inspection['token']);
		$this->assertSame(
			[
				'record' => '',
				'recordType' => 'aanwijzing',
				'documents' => ['7', 'd1', 'd2'],
				'startDate' => '2026-09-01T12:00:00+02:00',
				'endDate' => '2026-09-04T12:00:00+02:00',
				'termDays' => 3,
				'openedBy' => 'admin',
			],
			$inspection
		);

	}//end testTheInspectionIsShapedWholeWithUniqueDocuments()

	public function testAnInspectionWithoutATokenOpensForNobody(): void {
		$inspection = ['documents' => ['d1'], 'startDate' => '2026-01-01T00:00:00+00:00', 'endDate' => '2099-01-01T00:00:00+00:00'];

		$this->assertSame(
			['readable' => false, 'documents' => [], 'reason' => 'unknown-inspection', 'endDate' => null],
			$this->service->resolveLink($inspection, '')
		);
		$this->assertSame(
			['readable' => false, 'documents' => [], 'reason' => 'unknown-inspection', 'endDate' => null],
			$this->service->resolveLink($inspection + ['token' => ''], '')
		);

	}//end testAnInspectionWithoutATokenOpensForNobody()

	public function testAnOpenLinkAnswersTheDocumentsAsAList(): void {
		$inspection = ['token' => 'abc', 'documents' => ['x' => 3, 'y' => 'd2'], 'startDate' => '2026-01-01T00:00:00+00:00', 'endDate' => '2099-01-01T00:00:00+00:00'];

		$this->assertSame(
			['readable' => true, 'documents' => ['3', 'd2'], 'reason' => null, 'endDate' => '2099-01-01T00:00:00+00:00'],
			$this->service->resolveLink($inspection, 'abc', $this->at('2026-06-01T00:00:00+00:00'))
		);
		$this->assertSame(
			['readable' => true, 'documents' => [], 'reason' => null, 'endDate' => '2099-01-01T00:00:00+00:00'],
			$this->service->resolveLink(['documents' => 'd1'] + $inspection, 'abc', $this->at('2026-06-01T00:00:00+00:00'))
		);

	}//end testAnOpenLinkAnswersTheDocumentsAsAList()

	public function testASecondTypeUsesTheSameMechanismWithItsOwnTerm(): void {
		$inspection = $this->service->open(
			record: ['id' => 'r2'],
			recordType: ['slug' => 'bestemmingsplan', 'inspectionTermDays' => 6],
			documents: ['d9'],
			openedBy: 'admin',
			start: $this->at('2026-09-01T00:00:00+00:00')
		);

		$this->assertSame('2026-09-07T00:00:00+00:00', $inspection['endDate']);
		$this->assertSame('bestemmingsplan', $inspection['recordType']);

	}//end testASecondTypeUsesTheSameMechanismWithItsOwnTerm()

	public function testATypeWithoutATermCannotHaveAnInspectionOpenedOnIt(): void {
		$this->expectException(DomainException::class);

		$this->service->open(
			record: ['id' => 'r1'],
			recordType: ['slug' => 'notitie'],
			documents: ['d1'],
			openedBy: 'admin'
		);

	}//end testATypeWithoutATermCannotHaveAnInspectionOpenedOnIt()

	public function testAnInspectionOverNoDocumentsIsRefused(): void {
		$this->expectException(DomainException::class);

		$this->service->open(
			record: ['id' => 'r1'],
			recordType: ['slug' => 'omgevingsvergunning', 'inspectionTermDays' => 42],
			documents: [],
			openedBy: 'admin'
		);

	}//end testAnInspectionOverNoDocumentsIsRefused()

	/**
	 * The inspection set is chosen at publication, and only those documents are
	 * readable through the link.
	 */
	public function testOnlyTheChosenDocumentsAreReadable(): void {
		$inspection = $this->service->open(
			record: ['id' => 'r1', 'documents' => ['d1', 'd2', 'd3', 'd4', 'd5']],
			recordType: ['slug' => 'omgevingsvergunning', 'inspectionTermDays' => 42],
			documents: ['d2', 'd4'],
			openedBy: 'admin',
			start: $this->at('2026-09-01T00:00:00+00:00')
		);

		$outcome = $this->service->resolveLink(
			inspection: $inspection,
			token: $inspection['token'],
			now: $this->at('2026-09-10T00:00:00+00:00')
		);

		$this->assertTrue($outcome['readable']);
		$this->assertSame(['d2', 'd4'], $outcome['documents']);

	}//end testOnlyTheChosenDocumentsAreReadable()

	/**
	 * The failure that matters: a link that outlives its statutory window
	 * because no job has run yet. The check is on the read, so there is no job
	 * in this test at all.
	 */
	public function testTheLinkStopsWhenTheWindowClosesWithNoJobRun(): void {
		$inspection = $this->service->open(
			record: ['id' => 'r1'],
			recordType: ['slug' => 'omgevingsvergunning', 'inspectionTermDays' => 42],
			documents: ['d1'],
			openedBy: 'admin',
			start: $this->at('2026-09-01T00:00:00+00:00')
		);

		$outcome = $this->service->resolveLink(
			inspection: $inspection,
			token: $inspection['token'],
			now: $this->at('2026-10-14T00:00:00+00:00')
		);

		$this->assertFalse($outcome['readable']);
		$this->assertSame('window-closed', $outcome['reason']);
		$this->assertSame('2026-10-13T00:00:00+00:00', $outcome['endDate']);
		$this->assertSame([], $outcome['documents']);

	}//end testTheLinkStopsWhenTheWindowClosesWithNoJobRun()

	public function testAWrongTokenIsRefusedWithoutRevealingTheWindow(): void {
		$inspection = $this->service->open(
			record: ['id' => 'r1'],
			recordType: ['slug' => 'omgevingsvergunning', 'inspectionTermDays' => 42],
			documents: ['d1'],
			openedBy: 'admin',
			start: $this->at('2026-09-01T00:00:00+00:00')
		);

		$outcome = $this->service->resolveLink(
			inspection: $inspection,
			token: 'not-the-token',
			now: $this->at('2026-09-10T00:00:00+00:00')
		);

		$this->assertFalse($outcome['readable']);
		$this->assertSame('unknown-inspection', $outcome['reason']);
		$this->assertNull($outcome['endDate']);

	}//end testAWrongTokenIsRefusedWithoutRevealingTheWindow()

	/**
	 * An unreadable window refuses rather than defaulting. Read as open it
	 * publishes past the term; read as closed it withholds what is owed.
	 */
	public function testAnUnreadableWindowRefuses(): void {
		$this->expectException(DomainException::class);

		$this->service->isOpen(inspection: ['startDate' => 'ooit', 'endDate' => 'later']);

	}//end testAnUnreadableWindowRefuses()

	public function testTheTokenIsNotGuessable(): void {
		$first = $this->service->open(
			record: ['id' => 'r1'],
			recordType: ['slug' => 'x', 'inspectionTermDays' => 1],
			documents: ['d1'],
			openedBy: 'admin'
		);
		$second = $this->service->open(
			record: ['id' => 'r1'],
			recordType: ['slug' => 'x', 'inspectionTermDays' => 1],
			documents: ['d1'],
			openedBy: 'admin'
		);

		$this->assertNotSame($first['token'], $second['token']);
		$this->assertSame(32, strlen($first['token']));

	}//end testTheTokenIsNotGuessable()
}//end class
