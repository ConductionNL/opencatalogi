<?php

declare(strict_types=1);

namespace Unit\Service\Publication;

use DateTimeImmutable;
use DateTimeZone;
use DomainException;
use OCA\OpenCatalogi\Service\Publication\InspectionService;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for InspectionService.
 *
 * @covers \OCA\OpenCatalogi\Service\Publication\InspectionService
 */
class InspectionServiceTest extends TestCase {

	private InspectionService $service;

	protected function setUp(): void {
		$this->service = new InspectionService();

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
