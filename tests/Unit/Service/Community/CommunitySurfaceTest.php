<?php

declare(strict_types=1);

namespace Unit\Service\Community;

use DateTimeImmutable;
use DateTimeZone;
use DomainException;
use OCA\OpenCatalogi\Service\Community\AtomFeedService;
use OCA\OpenCatalogi\Service\Community\BannerService;
use OCA\OpenCatalogi\Service\Community\NoticeBoardService;
use OCA\OpenCatalogi\Service\Community\StatusPageService;
use OCA\OpenCatalogi\Service\Community\SubscriptionService;
use OCA\OpenCatalogi\Service\Community\VoteService;
use OCA\OpenCatalogi\Service\Publication\PublicationRuleService;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the public and community surface.
 *
 * @covers \OCA\OpenCatalogi\Service\Community\StatusPageService
 * @covers \OCA\OpenCatalogi\Service\Community\SubscriptionService
 * @covers \OCA\OpenCatalogi\Service\Community\BannerService
 * @covers \OCA\OpenCatalogi\Service\Community\NoticeBoardService
 * @covers \OCA\OpenCatalogi\Service\Community\AtomFeedService
 * @covers \OCA\OpenCatalogi\Service\Community\VoteService
 */
class CommunitySurfaceTest extends TestCase {

	private StatusPageService $status;
	private SubscriptionService $subscriptions;
	private BannerService $banners;
	private NoticeBoardService $notices;
	private AtomFeedService $feed;
	private VoteService $votes;

	protected function setUp(): void {
		$this->status = new StatusPageService();
		$this->subscriptions = new SubscriptionService(salt: 'test-salt');
		$this->banners = new BannerService();
		$this->notices = new NoticeBoardService();
		$this->feed = new AtomFeedService(new PublicationRuleService(), $this->notices);
		$this->votes = new VoteService(salt: 'test-salt');

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

	public function testAReaderSeesWhatIsDownWithItsMessage(): void {
		$page = $this->status->render(
			components: [
				$this->status->setState(
					component: 'DigiD',
					state: 'unavailable',
					message: 'Inloggen met DigiD werkt tijdelijk niet.',
					setBy: 'beheerder',
					now: $this->at('2026-09-18T09:00:00+00:00')
				),
			],
			stalenessHours: 24,
			now: $this->at('2026-09-18T10:00:00+00:00')
		);

		$this->assertSame('unavailable', $page['components'][0]['state']);
		$this->assertSame('Inloggen met DigiD werkt tijdelijk niet.', $page['components'][0]['message']);
		$this->assertFalse($page['components'][0]['stale']);

	}//end testAReaderSeesWhatIsDownWithItsMessage()

	/**
	 * A page that is out of date is worse than none. A state nobody touched in
	 * the staleness period must not render as a confident green.
	 */
	public function testAStaleStateDoesNotReadAsGreen(): void {
		$page = $this->status->render(
			components: [
				[
					'component' => 'De balie',
					'state' => 'available',
					'stateSetAt' => '2026-09-01T09:00:00+00:00',
				],
			],
			stalenessHours: 24,
			now: $this->at('2026-09-18T10:00:00+00:00')
		);

		$this->assertTrue($page['components'][0]['stale']);
		$this->assertSame('2026-09-01T09:00:00+00:00', $page['components'][0]['stateSetAt']);
		$this->assertSame(1, $page['stale']);

	}//end testAStaleStateDoesNotReadAsGreen()

	public function testAStateWithNoReadableDateIsStaleRatherThanFresh(): void {
		$page = $this->status->render(
			components: [['component' => 'Zwembad', 'state' => 'available', 'stateSetAt' => 'ooit']],
			stalenessHours: 24,
			now: $this->at('2026-09-18T10:00:00+00:00')
		);

		$this->assertTrue($page['components'][0]['stale']);

	}//end testAStateWithNoReadableDateIsStaleRatherThanFresh()

	/**
	 * The page probes nothing. The rendered answer says so, so a caller reading
	 * a green does not take it for a measurement.
	 */
	public function testThePageSaysItProbedNothing(): void {
		$this->assertFalse($this->status->render(components: [])['probed']);

	}//end testThePageSaysItProbedNothing()

	public function testAStateOutsideTheKnownSetIsRefused(): void {
		$this->expectException(DomainException::class);

		$this->status->setState(component: 'DigiD', state: 'probably-fine', message: '', setBy: 'a');

	}//end testAStateOutsideTheKnownSetIsRefused()

	/**
	 * Subscribing somebody else to an alert stream is a way to send mail on
	 * their behalf, so an unconfirmed address is never a recipient.
	 */
	public function testAnUnconfirmedAddressIsNeverARecipient(): void {
		$requested = $this->subscriptions->request(address: 'reader@example.org', scope: 'status');

		$this->assertFalse($this->subscriptions->isRecipient(subscription: $requested['subscription']));
		$this->assertSame(
			[],
			$this->subscriptions->recipients(subscriptions: [$requested['subscription']], scope: 'status')
		);

	}//end testAnUnconfirmedAddressIsNeverARecipient()

	public function testAConfirmedAddressIsARecipientAndIsToldOnce(): void {
		$requested = $this->subscriptions->request(address: 'reader@example.org', scope: 'status');
		$confirmed = $this->subscriptions->confirm(
			subscription: $requested['subscription'],
			token: $requested['token']
		);

		$this->assertTrue($this->subscriptions->isRecipient(subscription: $confirmed));
		$this->assertSame(
			['reader@example.org'],
			$this->subscriptions->recipients(subscriptions: [$confirmed, $confirmed], scope: 'status')
		);

	}//end testAConfirmedAddressIsARecipientAndIsToldOnce()

	public function testAConfirmationWithTheWrongTokenIsRefused(): void {
		$requested = $this->subscriptions->request(address: 'reader@example.org', scope: 'status');

		$this->expectException(DomainException::class);

		$this->subscriptions->confirm(subscription: $requested['subscription'], token: 'not-the-token');

	}//end testAConfirmationWithTheWrongTokenIsRefused()

	public function testTheStoredSubscriptionHoldsAHashAndNeverTheToken(): void {
		$requested = $this->subscriptions->request(address: 'reader@example.org', scope: 'status');

		$this->assertStringNotContainsString(
			$requested['token'],
			(string)json_encode($requested['subscription'])
		);

	}//end testTheStoredSubscriptionHoldsAHashAndNeverTheToken()

	public function testASubscriptionToAnotherScopeIsNotARecipientOfThisOne(): void {
		$other = $this->subscriptions->confirm(
			...$this->orderedConfirm(scope: 'notice-board')
		);

		$this->assertSame([], $this->subscriptions->recipients(subscriptions: [$other], scope: 'status'));

	}//end testASubscriptionToAnotherScopeIsNotARecipientOfThisOne()

	/**
	 * Build the arguments for a confirm() call on a fresh subscription.
	 *
	 * @param string $scope The scope.
	 *
	 * @return array{subscription: array<string, mixed>, token: string}
	 */
	private function orderedConfirm(string $scope): array {
		$requested = $this->subscriptions->request(address: 'reader@example.org', scope: $scope);

		return ['subscription' => $requested['subscription'], 'token' => $requested['token']];

	}//end orderedConfirm()

	public function testABannerShowsInsideItsPeriodAndIsGoneAfterIt(): void {
		$banner = [
			'id' => 'b1',
			'body' => 'Onderhoud op zaterdag.',
			'startDate' => '2026-09-18T00:00:00+00:00',
			'endDate' => '2026-09-20T00:00:00+00:00',
		];

		$this->assertTrue($this->banners->isCurrent(banner: $banner, now: $this->at('2026-09-19T00:00:00+00:00')));
		$this->assertFalse($this->banners->isCurrent(banner: $banner, now: $this->at('2026-09-21T00:00:00+00:00')));
		$this->assertFalse($this->banners->isCurrent(banner: $banner, now: $this->at('2026-09-17T00:00:00+00:00')));

	}//end testABannerShowsInsideItsPeriodAndIsGoneAfterIt()

	public function testADismissalIsRememberedForThatUserAndNotForOthers(): void {
		$banner = [
			'id' => 'b1',
			'body' => 'Onderhoud.',
			'startDate' => '2026-09-18T00:00:00+00:00',
			'endDate' => '2026-09-20T00:00:00+00:00',
			'dismissable' => true,
		];
		$now = $this->at('2026-09-19T00:00:00+00:00');

		$this->assertSame([], $this->banners->forUser(banners: [$banner], dismissedIds: ['b1'], now: $now));
		$this->assertCount(1, $this->banners->forUser(banners: [$banner], dismissedIds: [], now: $now));

	}//end testADismissalIsRememberedForThatUserAndNotForOthers()

	public function testANonDismissableBannerKeepsShowing(): void {
		$banner = [
			'id' => 'b1',
			'body' => 'Kritiek onderhoud.',
			'startDate' => '2026-09-18T00:00:00+00:00',
			'endDate' => '2026-09-20T00:00:00+00:00',
			'dismissable' => false,
		];

		$this->assertCount(
			1,
			$this->banners->forUser(banners: [$banner], dismissedIds: ['b1'], now: $this->at('2026-09-19T00:00:00+00:00'))
		);

	}//end testANonDismissableBannerKeepsShowing()

	public function testABannerWithNoReadableEndDateIsRefused(): void {
		$this->expectException(DomainException::class);

		$this->banners->validate(banner: ['body' => 'Iets', 'startDate' => '2026-09-18', 'endDate' => 'ooit']);

	}//end testABannerWithNoReadableEndDateIsRefused()

	/**
	 * Reusing `publication` for a storingsmelding would put it in the sitemap
	 * and in the DiWoo feed. That is the wrong place.
	 */
	public function testANoticeIsAbsentFromTheSitemap(): void {
		$entries = [
			['@type' => 'publication', 'title' => 'Besluit'],
			['@type' => 'notice', 'title' => 'Storingsmelding'],
		];

		$inSitemap = $this->notices->excludeNotices(entries: $entries);

		$this->assertCount(1, $inSitemap);
		$this->assertSame('Besluit', $inSitemap[0]['title']);
		$this->assertFalse($this->notices->belongsInSitemap(entry: ['@type' => 'notice']));

	}//end testANoticeIsAbsentFromTheSitemap()

	public function testCommentsAreOffUnlessSomebodyTurnsThemOn(): void {
		$board = $this->notices->validateBoard(board: ['title' => 'Mededelingen', 'catalog' => 'c1']);

		$this->assertFalse($board['commentsEnabled']);
		$this->assertFalse($this->notices->commentsOffered(board: $board));

	}//end testCommentsAreOffUnlessSomebodyTurnsThemOn()

	public function testABoardWithCommentsAndNoModeratorIsRefusedWithTheReason(): void {
		$this->expectException(DomainException::class);
		$this->expectExceptionMessageMatches('/moderator/');

		$this->notices->validateBoard(board: ['title' => 'Mededelingen', 'commentsEnabled' => true]);

	}//end testABoardWithCommentsAndNoModeratorIsRefusedWithTheReason()

	public function testABoardWithCommentsAndAModeratorIsAccepted(): void {
		$board = $this->notices->validateBoard(
			board: ['title' => 'Mededelingen', 'commentsEnabled' => true, 'moderator' => 'redactie']
		);

		$this->assertTrue($this->notices->commentsOffered(board: $board));

	}//end testABoardWithCommentsAndAModeratorIsAccepted()

	/**
	 * The rule that publishes a besluit, for the feed tests.
	 *
	 * @return array<string, mixed>
	 */
	private function feedRule(): array {
		return [
			'recordType' => 'besluit',
			'enabled' => true,
			'anonymousProperties' => ['id', 'title', 'publicationDate'],
			'conditions' => [['property' => 'status', 'operator' => 'equals', 'value' => 'definitief']],
		];

	}//end feedRule()

	public function testAReaderWatchesTwoPublishedRecordsWithoutAnAccount(): void {
		$entries = $this->feed->entries(
			records: [
				['@type' => 'besluit', 'status' => 'definitief', 'id' => 'r1', 'title' => 'Een', 'publicationDate' => '2026-09-01'],
				['@type' => 'besluit', 'status' => 'definitief', 'id' => 'r2', 'title' => 'Twee', 'publicationDate' => '2026-09-02'],
			],
			notices: [],
			rules: [$this->feedRule()]
		);

		$this->assertCount(2, $entries);
		$this->assertSame(['Een', 'Twee'], array_column($entries, 'title'));

	}//end testAReaderWatchesTwoPublishedRecordsWithoutAnAccount()

	/**
	 * The access check is the publication's, run per entry. A draft is absent
	 * because it is not published, not because the feed has its own opinion.
	 */
	public function testADraftNeverReachesTheFeed(): void {
		$entries = $this->feed->entries(
			records: [
				['@type' => 'besluit', 'status' => 'definitief', 'id' => 'r1', 'title' => 'Gepubliceerd'],
				['@type' => 'besluit', 'status' => 'concept', 'id' => 'r2', 'title' => 'Concept'],
			],
			notices: [],
			rules: [$this->feedRule()]
		);

		$this->assertCount(1, $entries);
		$this->assertSame('Gepubliceerd', $entries[0]['title']);

		$atom = $this->feed->toAtom(catalogTitle: 'Zuiderdorp', selfUrl: 'https://example.org/feed', entries: $entries);
		$this->assertStringNotContainsString('Concept', $atom);

	}//end testADraftNeverReachesTheFeed()

	public function testAnExpiredNoticeIsAbsentFromTheFeed(): void {
		$entries = $this->feed->entries(
			records: [],
			notices: [
				['id' => 'n1', 'title' => 'Nu', 'startDate' => '2026-09-01', 'endDate' => '2026-12-01'],
				['id' => 'n2', 'title' => 'Voorbij', 'startDate' => '2026-01-01', 'endDate' => '2026-02-01'],
			],
			rules: [],
			now: $this->at('2026-09-18T00:00:00+00:00')
		);

		$this->assertCount(1, $entries);
		$this->assertSame('Nu', $entries[0]['title']);

	}//end testAnExpiredNoticeIsAbsentFromTheFeed()

	public function testTheAtomDocumentEscapesWhatCameFromAnEntry(): void {
		$atom = $this->feed->toAtom(
			catalogTitle: 'Zuiderdorp',
			selfUrl: 'https://example.org/feed',
			entries: [['kind' => 'notice', 'id' => 'n1', 'title' => '<script>alert(1)</script>', 'updated' => '2026-09-01']]
		);

		$this->assertStringNotContainsString('<script>', $atom);
		$this->assertStringContainsString('&lt;script&gt;', $atom);

	}//end testTheAtomDocumentEscapesWhatCameFromAnEntry()

	public function testOneReaderVotesOnce(): void {
		$first = $this->votes->cast(
			recordId: 'r1',
			existingVotes: [],
			readerToken: 'reader-1',
			value: 'voor',
			allowedValues: ['voor', 'tegen']
		);
		$this->assertTrue($first['counted']);

		$second = $this->votes->cast(
			recordId: 'r1',
			existingVotes: [$first['vote']],
			readerToken: 'reader-1',
			value: 'tegen',
			allowedValues: ['voor', 'tegen']
		);
		$this->assertFalse($second['counted']);
		$this->assertNull($second['vote']);

	}//end testOneReaderVotesOnce()

	public function testTheDistributionIsReadableAndNoVoterIsIdentifiable(): void {
		$votes = [
			['record' => 'r1', 'readerHash' => $this->votes->readerHash(readerToken: 'a'), 'value' => 'voor'],
			['record' => 'r1', 'readerHash' => $this->votes->readerHash(readerToken: 'b'), 'value' => 'voor'],
			['record' => 'r1', 'readerHash' => $this->votes->readerHash(readerToken: 'c'), 'value' => 'tegen'],
		];

		$distribution = $this->votes->distribution(votes: $votes);

		$this->assertSame(3, $distribution['total']);
		$this->assertSame(['tegen' => 1, 'voor' => 2], $distribution['distribution']);

		// A reader hash is stable across records, so publishing the hashes
		// would let anyone correlate one person's votes across every item.
		$serialised = (string)json_encode($distribution);
		foreach (['a', 'b', 'c'] as $reader) {
			$this->assertStringNotContainsString($this->votes->readerHash(readerToken: $reader), $serialised);
		}

	}//end testTheDistributionIsReadableAndNoVoterIsIdentifiable()

	public function testAVoteOnADraftIsNotAccepted(): void {
		$this->assertFalse($this->votes->acceptsVotes(record: ['votingEnabled' => true, 'draft' => true]));
		$this->assertFalse($this->votes->acceptsVotes(record: ['draft' => false]));
		$this->assertTrue($this->votes->acceptsVotes(record: ['votingEnabled' => true, 'draft' => false]));

	}//end testAVoteOnADraftIsNotAccepted()

	public function testAValueTheRecordDoesNotAcceptIsRefused(): void {
		$this->expectException(DomainException::class);

		$this->votes->cast(
			recordId: 'r1',
			existingVotes: [],
			readerToken: 'reader-1',
			value: 'misschien',
			allowedValues: ['voor', 'tegen']
		);

	}//end testAValueTheRecordDoesNotAcceptIsRefused()
}//end class
