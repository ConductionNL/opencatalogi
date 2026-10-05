<?php

/**
 * Unit tests for CommentPeriodService.
 *
 * A comment period is the window in which a reader still has a right, so the
 * failures that matter are the two opposite ones: inviting comment on a draft
 * whose period closed, and withholding a form from a reader whose period is still
 * open. Both are tested, and so is the third: offering the wrong remedy's form,
 * which costs a reader the right rather than the chance.
 *
 * The term engine is faked as a duck-typed object, the same way the consumed
 * ObjectService is faked elsewhere in this suite. The fake does the arithmetic
 * the real engine does for the one case the roll exists for, which is a period
 * landing on a non-working day.
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

namespace Unit\Service\Publication;

use DateTimeImmutable;
use DomainException;
use OCA\OpenCatalogi\Service\Publication\CommentPeriodService;
use OCA\OpenCatalogi\Service\Publication\TermRoll;
use OCA\OpenCatalogi\Service\Publication\TermRollUnavailableException;
use OCP\IAppConfig;
use OCP\IURLGenerator;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;

/**
 * A duck-typed stand-in for the engine's calendar.
 */
class FakeWorkingCalendar {
}//end class

/**
 * A duck-typed stand-in for the engine's calendar service.
 */
class FakeCalendarService {

	public function resolve(?string $slug, ?string $organisation): FakeWorkingCalendar {
		return new FakeWorkingCalendar();
	}//end resolve()
}//end class

/**
 * A duck-typed stand-in for the engine's calculator. Weekends are non-working,
 * which is enough to exercise the roll without reimplementing Easter.
 */
class FakeSlaCalculator {

	public function add(\DateTimeInterface $from, float $value, string $unit, ?object $calendar): DateTimeImmutable {
		return DateTimeImmutable::createFromInterface($from)->modify(sprintf('%+d days', (int)$value));
	}//end add()

	public function roll(\DateTimeInterface $moment, string $roll, ?object $calendar): array {
		$at = DateTimeImmutable::createFromInterface($moment);
		$day = (int)$at->format('N');
		if ($day < 6) {
			return ['at' => $at, 'unrolledAt' => null, 'rolledBy' => null];
		}

		$rolled = $at->modify('+' . (8 - $day) . ' days');

		return ['at' => $rolled, 'unrolledAt' => $at, 'rolledBy' => 'weekend'];
	}//end roll()
}//end class

/**
 * Unit tests for CommentPeriodService.
 */
class CommentPeriodServiceTest extends TestCase {

	private CommentPeriodService $service;

	/**
	 * Build the service over a faked engine.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->service = $this->build(engineAvailable: true);

	}//end setUp()

	/**
	 * Build the service, with or without an engine behind it.
	 *
	 * @param bool $engineAvailable Whether the container binds the engine.
	 *
	 * @return CommentPeriodService The service.
	 */
	private function build(bool $engineAvailable): CommentPeriodService {
		$container = $this->createMock(ContainerInterface::class);
		if ($engineAvailable === true) {
			$container->method('get')->willReturnCallback(
				static function (string $id) {
					if ($id === 'OCA\OpenRegister\Service\Flow\Timer\SlaCalculator') {
						return new FakeSlaCalculator();
					}

					if ($id === 'OCA\OpenRegister\Service\Flow\Timer\WorkingCalendarService') {
						return new FakeCalendarService();
					}

					throw new \RuntimeException('not bound: ' . $id);
				}
			);
		} else {
			$container->method('get')->willThrowException(new \RuntimeException('not installed'));
		}

		$config = $this->createMock(IAppConfig::class);
		$config->method('getValueString')->willReturn('');

		$urls = $this->createMock(IURLGenerator::class);
		$urls->method('getAbsoluteURL')->willReturnCallback(
			static fn (string $path): string => 'https://example.org' . $path
		);

		return new CommentPeriodService(new TermRoll($container), $config, $urls);

	}//end build()

	/**
	 * A period opens with its computed end, its remedy and its form.
	 *
	 * @return void
	 */
	public function testAPeriodOpensWithItsComputedEnd(): void {
		$period = $this->period(start: '2026-03-02T09:00:00+00:00', termDays: 42);

		$this->assertSame('pub-1', $period['publication']);
		$this->assertSame('2026-03-02T09:00:00+00:00', $period['startDate']);
		$this->assertSame('2026-04-13T09:00:00+00:00', $period['endDate']);
		$this->assertSame(42, $period['termDays']);
		$this->assertSame('zienswijze', $period['legalRemedy']);
		$this->assertStringContainsString('zienswijze', $period['reactionFormUrl']);

	}//end testAPeriodOpensWithItsComputedEnd()

	/**
	 * An end that lands on a non-working day rolls, and the period records where
	 * it was and why it moved.
	 *
	 * A date that moved with no explanation is one somebody will challenge and
	 * nobody can defend.
	 *
	 * @return void
	 */
	public function testAnEndOnANonWorkingDayRollsAndSaysWhy(): void {
		// 2026-03-02 is a Monday; 40 days later is Saturday 2026-04-11.
		$period = $this->period(start: '2026-03-02T09:00:00+00:00', termDays: 40);

		$this->assertSame('2026-04-13T09:00:00+00:00', $period['endDate'], 'The end must roll to the Monday.');
		$this->assertSame('2026-04-11T09:00:00+00:00', $period['unrolledEndDate']);
		$this->assertSame('weekend', $period['rolledBy']);

	}//end testAnEndOnANonWorkingDayRollsAndSaysWhy()

	/**
	 * A period whose end did not move carries neither field, so a reader of the
	 * record can tell a date that moved from one that did not.
	 *
	 * @return void
	 */
	public function testAnUnrolledEndCarriesNoRollExplanation(): void {
		$period = $this->period(start: '2026-03-02T09:00:00+00:00', termDays: 42);

		$this->assertArrayNotHasKey('unrolledEndDate', $period);
		$this->assertArrayNotHasKey('rolledBy', $period);

	}//end testAnUnrolledEndCarriesNoRollExplanation()

	/**
	 * With no engine, the roll is refused rather than computed here.
	 *
	 * @return void
	 */
	public function testWithNoEngineTheEndDateIsRefusedNotGuessed(): void {
		$service = $this->build(engineAvailable: false);

		$this->expectException(TermRollUnavailableException::class);
		$service->open(
			publication: ['id' => 'pub-1'],
			termDays: 42,
			legalRemedy: 'zienswijze',
			announcementUrl: 'https://officielebekendmakingen.nl/stcrt-2026-1',
			automaticWithdrawal: false,
			openedBy: 'alice'
		);

	}//end testWithNoEngineTheEndDateIsRefusedNotGuessed()

	/**
	 * The form follows the remedy, both ways.
	 *
	 * @return void
	 */
	public function testTheFormFollowsTheRemedy(): void {
		$zienswijze = $this->service->reactionFormUrl(publicationId: 'pub-1', legalRemedy: 'zienswijze');
		$bezwaar = $this->service->reactionFormUrl(publicationId: 'pub-1', legalRemedy: 'bezwaar');

		$this->assertStringContainsString('/zienswijze', $zienswijze);
		$this->assertStringContainsString('/bezwaar', $bezwaar);
		$this->assertNotSame($zienswijze, $bezwaar);

	}//end testTheFormFollowsTheRemedy()

	/**
	 * A third remedy is refused.
	 *
	 * @return void
	 */
	public function testAThirdRemedyIsRefused(): void {
		$this->expectException(DomainException::class);
		$this->period(start: '2026-03-02T09:00:00+00:00', termDays: 42, remedy: 'beroep');

	}//end testAThirdRemedyIsRefused()

	/**
	 * A term of no days is refused.
	 *
	 * @return void
	 */
	public function testATermOfNoDaysIsRefused(): void {
		$this->expectException(DomainException::class);
		$this->period(start: '2026-03-02T09:00:00+00:00', termDays: 0);

	}//end testATermOfNoDaysIsRefused()

	/**
	 * A period with no publication is refused.
	 *
	 * @return void
	 */
	public function testAPeriodWithNoPublicationIsRefused(): void {
		$this->expectException(DomainException::class);
		$this->service->open(
			publication: [],
			termDays: 42,
			legalRemedy: 'zienswijze',
			announcementUrl: 'https://officielebekendmakingen.nl/stcrt-2026-1',
			automaticWithdrawal: false,
			openedBy: 'alice'
		);

	}//end testAPeriodWithNoPublicationIsRefused()

	/**
	 * A missing announcement is refused: a period nobody was told about is not a
	 * public comment period.
	 *
	 * @return void
	 */
	public function testAMissingAnnouncementIsRefused(): void {
		$this->expectException(DomainException::class);
		$this->period(start: '2026-03-02T09:00:00+00:00', termDays: 42, announcement: '');

	}//end testAMissingAnnouncementIsRefused()

	/**
	 * An internal link cannot stand in for an official announcement.
	 *
	 * @return void
	 */
	public function testAnInternalLinkIsNotAnAnnouncement(): void {
		$this->expectException(DomainException::class);
		$this->period(
			start: '2026-03-02T09:00:00+00:00',
			termDays: 42,
			announcement: 'https://intranet.example.org/draft-decision'
		);

	}//end testAnInternalLinkIsNotAnAnnouncement()

	/**
	 * A subdomain of the announcement platform is accepted.
	 *
	 * @return void
	 */
	public function testASubdomainOfTheAnnouncementPlatformIsAccepted(): void {
		$period = $this->period(
			start: '2026-03-02T09:00:00+00:00',
			termDays: 42,
			announcement: 'https://zoek.officielebekendmakingen.nl/stcrt-2026-1'
		);

		$this->assertSame('https://zoek.officielebekendmakingen.nl/stcrt-2026-1', $period['announcementUrl']);

	}//end testASubdomainOfTheAnnouncementPlatformIsAccepted()

	/**
	 * The three states, derived from the clock.
	 *
	 * @return void
	 */
	public function testThePeriodReportsOneOfThreeStates(): void {
		$period = ['startDate' => '2026-03-02T00:00:00+00:00', 'endDate' => '2026-04-13T00:00:00+00:00'];

		$this->assertSame(
			'upcoming',
			$this->service->state(period: $period, now: new DateTimeImmutable('2026-03-01T00:00:00+00:00'))
		);
		$this->assertSame(
			'open',
			$this->service->state(period: $period, now: new DateTimeImmutable('2026-03-20T00:00:00+00:00'))
		);
		$this->assertSame(
			'closed',
			$this->service->state(period: $period, now: new DateTimeImmutable('2026-04-14T00:00:00+00:00'))
		);

	}//end testThePeriodReportsOneOfThreeStates()

	/**
	 * An unreadable period refuses rather than defaulting either way.
	 *
	 * @return void
	 */
	public function testAnUnreadablePeriodRefuses(): void {
		$this->expectException(DomainException::class);
		$this->service->state(period: ['startDate' => 'not a date', 'endDate' => 'nor this']);

	}//end testAnUnreadablePeriodRefuses()

	/**
	 * A closed period offers no form; an open one does.
	 *
	 * @return void
	 */
	public function testTheFormIsOfferedOnlyWhileThePeriodIsOpen(): void {
		$period = [
			'publication' => 'pub-1',
			'startDate' => '2026-03-02T00:00:00+00:00',
			'endDate' => '2026-04-13T00:00:00+00:00',
			'legalRemedy' => 'zienswijze',
			'reactionFormUrl' => 'https://example.org/form',
			'announcementUrl' => 'https://officielebekendmakingen.nl/stcrt-2026-1',
		];

		$open = $this->service->publicView(period: $period, now: new DateTimeImmutable('2026-03-20T00:00:00+00:00'));
		$this->assertSame('open', $open['state']);
		$this->assertSame('https://example.org/form', $open['reactionFormUrl']);

		$closed = $this->service->publicView(period: $period, now: new DateTimeImmutable('2026-04-14T00:00:00+00:00'));
		$this->assertSame('closed', $closed['state']);
		$this->assertNull($closed['reactionFormUrl']);

	}//end testTheFormIsOfferedOnlyWhileThePeriodIsOpen()

	/**
	 * A withdrawal is due only for a closed period that asked for one and has
	 * not had one.
	 *
	 * @return void
	 */
	public function testAWithdrawalIsDueOnlyOnceAndOnlyWhenAskedFor(): void {
		$base = ['startDate' => '2026-03-02T00:00:00+00:00', 'endDate' => '2026-04-13T00:00:00+00:00'];
		$closed = new DateTimeImmutable('2026-04-14T00:00:00+00:00');
		$open = new DateTimeImmutable('2026-03-20T00:00:00+00:00');

		$this->assertTrue(
			$this->service->withdrawalDue(period: ($base + ['automaticWithdrawal' => true]), now: $closed)
		);
		$this->assertFalse(
			$this->service->withdrawalDue(period: ($base + ['automaticWithdrawal' => false]), now: $closed),
			'A period that did not ask for a withdrawal is left alone.'
		);
		$this->assertFalse(
			$this->service->withdrawalDue(period: ($base + ['automaticWithdrawal' => true]), now: $open),
			'An open period is not withdrawn.'
		);
		$this->assertFalse(
			$this->service->withdrawalDue(
				period: ($base + ['automaticWithdrawal' => true, 'withdrawnAt' => '2026-04-14T00:00:00+00:00']),
				now: $closed
			),
			'A withdrawal happens once.'
		);
		$this->assertFalse(
			$this->service->withdrawalDue(
				period: ['automaticWithdrawal' => true, 'startDate' => 'bad', 'endDate' => 'bad'],
				now: $closed
			),
			'An unreadable period is never withdrawn on a guess.'
		);

	}//end testAWithdrawalIsDueOnlyOnceAndOnlyWhenAskedFor()

	/**
	 * Open a period with the usual arguments.
	 *
	 * @param string $start When it opens.
	 * @param int $termDays How long it runs.
	 * @param string $remedy The legal remedy.
	 * @param string $announcement The official announcement.
	 *
	 * @return array<string, mixed> The period.
	 */
	private function period(
		string $start,
		int $termDays,
		string $remedy = 'zienswijze',
		string $announcement = 'https://officielebekendmakingen.nl/stcrt-2026-1',
	): array {
		return $this->service->open(
			publication: ['id' => 'pub-1'],
			termDays: $termDays,
			legalRemedy: $remedy,
			announcementUrl: $announcement,
			automaticWithdrawal: false,
			openedBy: 'alice',
			start: new DateTimeImmutable($start)
		);

	}//end period()
}//end class
