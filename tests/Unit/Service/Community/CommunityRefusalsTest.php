<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace Unit\Service\Community;

use DateTimeImmutable;
use DateTimeZone;
use DomainException;
use OCA\OpenCatalogi\Service\Community\AtomFeedService;
use OCA\OpenCatalogi\Service\Community\BannerService;
use OCA\OpenCatalogi\Service\Community\NoticeBoardService;
use OCA\OpenCatalogi\Service\Publication\PublicationRuleService;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for what the community surface refuses or skips: unreadable
 * dates, missing titles, malformed notices, and the feed's own clock.
 *
 * @covers \OCA\OpenCatalogi\Service\Community\BannerService
 * @covers \OCA\OpenCatalogi\Service\Community\NoticeBoardService
 * @covers \OCA\OpenCatalogi\Service\Community\AtomFeedService
 * @uses \OCA\OpenCatalogi\Service\Publication\PublicationRuleService
 */
class CommunityRefusalsTest extends TestCase {

	private BannerService $banners;
	private NoticeBoardService $notices;
	private AtomFeedService $feed;

	protected function setUp(): void {
		$this->banners = new BannerService();
		$this->notices = new NoticeBoardService();
		$this->feed = new AtomFeedService(new PublicationRuleService(), $this->notices);

	}//end setUp()

	/**
	 * A moment.
	 *
	 * @param string $when The moment.
	 *
	 * @return DateTimeImmutable
	 */
	private function at(string $when): DateTimeImmutable {
		return new DateTimeImmutable($when, new DateTimeZone('UTC'));

	}//end at()

	public function testABannerWithUnreadableDatesIsNotShown(): void {
		$this->assertFalse(
			$this->banners->isCurrent(
				banner: ['startDate' => 'niet een datum', 'endDate' => '2026-12-01'],
				now: $this->at('2026-09-18T00:00:00+00:00')
			)
		);

	}//end testABannerWithUnreadableDatesIsNotShown()

	public function testANoticeWithUnreadableDatesIsNotCurrent(): void {
		$this->assertFalse(
			$this->notices->isCurrent(
				notice: ['startDate' => '2026-09-01', 'endDate' => 'nooit'],
				now: $this->at('2026-09-18T00:00:00+00:00')
			)
		);

	}//end testANoticeWithUnreadableDatesIsNotCurrent()

	public function testANoticeIsCurrentOnItsLastMomentAndNotAfter(): void {
		$notice = ['startDate' => '2026-09-01T00:00:00+00:00', 'endDate' => '2026-09-30T00:00:00+00:00'];

		$this->assertTrue($this->notices->isCurrent(notice: $notice, now: $this->at('2026-09-30T00:00:00+00:00')));
		$this->assertFalse($this->notices->isCurrent(notice: $notice, now: $this->at('2026-09-30T00:00:01+00:00')));

	}//end testANoticeIsCurrentOnItsLastMomentAndNotAfter()

	public function testABoardWithoutATitleIsRefused(): void {
		$this->expectException(DomainException::class);
		$this->expectExceptionMessage('A notice board needs a title.');

		$this->notices->validateBoard(board: ['title' => '   ']);

	}//end testABoardWithoutATitleIsRefused()

	public function testABoardWithoutCommentsStoresNoModerator(): void {
		$board = $this->notices->validateBoard(board: ['title' => 'Mededelingen', 'moderator' => '  ']);

		$this->assertFalse($board['commentsEnabled']);
		$this->assertNull($board['moderator']);
		$this->assertFalse($this->notices->commentsOffered(board: $board));

	}//end testABoardWithoutCommentsStoresNoModerator()

	public function testANoticeWithoutABoardIsRefused(): void {
		$this->expectException(DomainException::class);
		$this->expectExceptionMessage('A notice belongs to a board.');

		$this->notices->validateNotice(notice: ['title' => 'Werkzaamheden']);

	}//end testANoticeWithoutABoardIsRefused()

	public function testANoticeWithoutATitleIsRefused(): void {
		$this->expectException(DomainException::class);
		$this->expectExceptionMessage('A notice needs a title.');

		$this->notices->validateNotice(notice: ['board' => 'b1', 'title' => '']);

	}//end testANoticeWithoutATitleIsRefused()

	public function testANoticeWithAnUnreadablePeriodIsRefusedWithTheCause(): void {
		try {
			$this->notices->validateNotice(
				notice: ['board' => 'b1', 'title' => 'Werkzaamheden', 'startDate' => 'morgen ergens', 'endDate' => '2026-12-01']
			);
			$this->fail('An unreadable period was accepted.');
		} catch (DomainException $e) {
			$this->assertSame('A notice needs a period this app can read.', $e->getMessage());
			$this->assertNotNull($e->getPrevious());
		}

	}//end testANoticeWithAnUnreadablePeriodIsRefusedWithTheCause()

	public function testANoticeThatEndsBeforeItStartsIsRefused(): void {
		$this->expectException(DomainException::class);
		$this->expectExceptionMessage('A notice cannot end before it starts.');

		$this->notices->validateNotice(
			notice: ['board' => 'b1', 'title' => 'Werkzaamheden', 'startDate' => '2026-12-01', 'endDate' => '2026-11-01']
		);

	}//end testANoticeThatEndsBeforeItStartsIsRefused()

	public function testAValidNoticeIsReturnedUnchanged(): void {
		$notice = ['board' => 'b1', 'title' => 'Werkzaamheden', 'startDate' => '2026-11-01', 'endDate' => '2026-12-01'];

		$this->assertSame($notice, $this->notices->validateNotice(notice: $notice));

	}//end testAValidNoticeIsReturnedUnchanged()

	public function testTheFeedSkipsANoticeThatIsNotAnArray(): void {
		$entries = $this->feed->entries(
			records: [],
			notices: ['geen notitie', ['id' => 'n1', 'title' => 'Nu', 'startDate' => '2026-09-01', 'endDate' => '2026-12-01', 'body' => 'Tekst']],
			rules: [],
			now: $this->at('2026-09-18T00:00:00+00:00')
		);

		$this->assertSame(
			[['kind' => 'notice', 'id' => 'n1', 'title' => 'Nu', 'updated' => '2026-09-01', 'summary' => 'Tekst']],
			$entries
		);

	}//end testTheFeedSkipsANoticeThatIsNotAnArray()

	public function testTheFeedIsStampedWithTheGivenMoment(): void {
		$atom = $this->feed->toAtom(
			catalogTitle: 'Zuiderdorp',
			selfUrl: 'https://example.org/feed',
			entries: [],
			now: $this->at('2026-09-18T10:20:30+00:00')
		);

		$this->assertStringContainsString('  <updated>2026-09-18T10:20:30+00:00</updated>', $atom);
		$this->assertStringEndsWith("</feed>\n", $atom);

	}//end testTheFeedIsStampedWithTheGivenMoment()

	public function testAnEntryWithAnUnreadableOrMissingDateTakesTheFeedMoment(): void {
		$atom = $this->feed->toAtom(
			catalogTitle: 'Zuiderdorp',
			selfUrl: 'https://example.org/feed',
			entries: [
				['kind' => 'notice', 'id' => 'n1', 'title' => 'Kapot', 'updated' => 'geen datum'],
				['id' => 'r1', 'title' => 'Leeg'],
			],
			now: $this->at('2026-09-18T10:20:30+00:00')
		);

		$this->assertSame(3, substr_count($atom, '<updated>2026-09-18T10:20:30+00:00</updated>'));
		$this->assertStringContainsString('<id>https://example.org/feed#r1</id>', $atom);
		$this->assertStringContainsString('<category term="record"/>', $atom);
		$this->assertStringContainsString('<category term="notice"/>', $atom);

	}//end testAnEntryWithAnUnreadableOrMissingDateTakesTheFeedMoment()

	public function testAReadableEntryDateIsWrittenInAtomFormat(): void {
		$atom = $this->feed->toAtom(
			catalogTitle: 'Zuiderdorp',
			selfUrl: 'https://example.org/feed',
			entries: [['id' => 'r1', 'title' => 'Een', 'updated' => '2026-09-01T08:00:00+02:00', 'summary' => 'Samenvatting']],
			now: $this->at('2026-09-18T10:20:30+00:00')
		);

		$this->assertStringContainsString('    <updated>2026-09-01T08:00:00+02:00</updated>', $atom);
		$this->assertStringContainsString('<summary>Samenvatting</summary>', $atom);

	}//end testAReadableEntryDateIsWrittenInAtomFormat()

}//end class
