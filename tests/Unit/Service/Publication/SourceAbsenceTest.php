<?php

/**
 * Unit tests for SourceAbsence: what a harvested publication becomes when its source drops the record.
 *
 * @category Test
 * @package  OCA\OpenCatalogi\Tests\Unit\Service\Publication
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenCatalogi.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/capability-rows-close-gaps/tasks.md
 */

declare(strict_types=1);

namespace Unit\Service\Publication;

use DateTimeImmutable;
use OCA\OpenCatalogi\Service\Publication\SourceAbsence;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\OpenCatalogi\Service\Publication\SourceAbsence
 */
class SourceAbsenceTest extends TestCase {

	private SourceAbsence $absence;

	private DateTimeImmutable $now;

	protected function setUp(): void {
		$this->absence = new SourceAbsence();
		$this->now = new DateTimeImmutable('2026-11-15T12:00:00+00:00');
	}

	/** An end date arriving means the policy ended the record: depublish. */
	public function testAnArrivingEndDateDepublishes(): void {
		$this->assertSame(
			SourceAbsence::DEPUBLISHED,
			$this->absence->outcome(
				['title' => 'Besluit'],
				['title' => 'Besluit', 'absentAtSource' => true, 'sourceAbsentSince' => '2026-11-14T03:00:00+00:00', 'sourceEndedAt' => '2026-11-14T03:00:00+00:00']
			)
		);
	}

	/** Only the absence flag arriving means the policy keeps the record: flag it. */
	public function testAnArrivingFlagWithoutEndDateFlags(): void {
		$this->assertSame(
			SourceAbsence::FLAGGED,
			$this->absence->outcome(['title' => 'Besluit', 'absentAtSource' => false], ['title' => 'Besluit', 'absentAtSource' => true, 'sourceAbsentSince' => '2026-11-14T03:00:00+00:00'])
		);
	}

	/** A marker that was already there asks for nothing, so a later republication stands. */
	public function testAMarkerThatWasAlreadyThereAsksNothing(): void {
		$ended = ['absentAtSource' => true, 'sourceEndedAt' => '2026-11-14T03:00:00+00:00'];
		$flagged = ['absentAtSource' => true, 'sourceAbsentSince' => '2026-11-14T03:00:00+00:00'];

		$this->assertNull($this->absence->outcome($ended, $ended + ['depublicationDate' => null]));
		$this->assertNull($this->absence->outcome($flagged, $flagged + ['title' => 'Edited']));
		$this->assertNull($this->absence->outcome([], ['title' => 'Besluit']));
		$this->assertNull($this->absence->outcome([], ['absentAtSource' => 'yes', 'sourceEndedAt' => '']));
	}

	/** A flagged record that is then ended is depublished. */
	public function testAFlaggedRecordThatIsEndedLaterIsDepublished(): void {
		$this->assertSame(
			SourceAbsence::DEPUBLISHED,
			$this->absence->outcome(['absentAtSource' => true], ['absentAtSource' => true, 'sourceEndedAt' => '2026-11-14T03:00:00+00:00'])
		);
	}

	/** The depublication date is the source's end date, in UTC. */
	public function testTheDepublicationDateIsTheSourceEndDate(): void {
		$result = $this->absence->depublished(
			['title' => 'Besluit', 'publicationDate' => '2026-01-01T00:00:00+00:00', 'sourceEndedAt' => '2026-11-14T04:00:00+01:00'],
			$this->now
		);

		$this->assertSame(
			['title' => 'Besluit', 'publicationDate' => '2026-01-01T00:00:00+00:00', 'sourceEndedAt' => '2026-11-14T04:00:00+01:00', 'depublicationDate' => '2026-11-14T03:00:00+00:00'],
			$result
		);
	}

	/** An end date in the future or unreadable takes the publication down now. */
	public function testAFutureOrUnreadableEndDateDepublishesNow(): void {
		$future = $this->absence->depublished(['sourceEndedAt' => '2027-01-01T00:00:00+00:00'], $this->now);
		$garbage = $this->absence->depublished(['sourceEndedAt' => 'not a date'], $this->now);

		$this->assertSame('2026-11-15T12:00:00+00:00', $future['depublicationDate']);
		$this->assertSame('2026-11-15T12:00:00+00:00', $garbage['depublicationDate']);
	}

	/** A later scheduled depublication is brought forward. */
	public function testALaterScheduledDepublicationIsBroughtForward(): void {
		$result = $this->absence->depublished(
			['sourceEndedAt' => '2026-11-14T03:00:00+00:00', 'depublicationDate' => '2027-06-01T00:00:00+00:00'],
			$this->now
		);

		$this->assertSame('2026-11-14T03:00:00+00:00', $result['depublicationDate']);
	}

	/** A publication already down by then keeps its own date and is not saved. */
	public function testAnEarlierDepublicationIsKept(): void {
		$this->assertNull(
			$this->absence->depublished(['sourceEndedAt' => '2026-11-14T03:00:00+00:00', 'depublicationDate' => '2026-10-01T00:00:00+00:00'], $this->now)
		);
		$this->assertNull(
			$this->absence->depublished(['sourceEndedAt' => '2026-11-14T03:00:00+00:00', 'depublicationDate' => '2026-11-14T03:00:00+00:00'], $this->now)
		);
	}

	/** The editor's summary names when the source dropped it and when it ended it. */
	public function testTheSummaryNamesTheMarkers(): void {
		$this->assertSame(
			['since' => '2026-11-14T03:00:00+00:00', 'endedAt' => null],
			$this->absence->summary(['absentAtSource' => true, 'sourceAbsentSince' => '2026-11-14T03:00:00+00:00'])
		);
		$this->assertSame(
			['since' => null, 'endedAt' => '2026-11-14T03:00:00+00:00'],
			$this->absence->summary(['sourceEndedAt' => '2026-11-14T03:00:00+00:00', 'sourceAbsentSince' => ''])
		);
	}

	/** A publication its source still carries has no summary. */
	public function testAPublicationAtItsSourceHasNoSummary(): void {
		$this->assertNull($this->absence->summary(['title' => 'Besluit']));
		$this->assertNull($this->absence->summary(['absentAtSource' => false, 'sourceAbsentSince' => '2026-11-14T03:00:00+00:00']));
	}
}
