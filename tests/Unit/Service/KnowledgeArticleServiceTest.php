<?php

declare(strict_types=1);

namespace Unit\Service;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use OCA\OpenCatalogi\Service\KnowledgeArticleService;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for KnowledgeArticleService.
 *
 * @covers \OCA\OpenCatalogi\Service\KnowledgeArticleService
 */
class KnowledgeArticleServiceTest extends TestCase {

	private KnowledgeArticleService $service;

	protected function setUp(): void {
		$this->service = new KnowledgeArticleService(salt: 'instance-secret');

	}//end setUp()

	public function testAVerdictIsCountedAndShown(): void {
		$outcome = $this->service->recordVerdict(
			article: ['id' => 'a1', 'helpfulCount' => 2, 'notHelpfulCount' => 1],
			existingVerdicts: [],
			readerToken: 'reader-1',
			helpful: true,
			now: new DateTimeImmutable('2026-09-18T09:00:00+00:00', new DateTimeZone('UTC'))
		);

		$this->assertTrue($outcome['counted']);
		$this->assertSame(3, $outcome['article']['helpfulCount']);
		$this->assertSame(['helpful' => 3, 'notHelpful' => 1], $this->service->publicCounts(article: $outcome['article']));
		$this->assertSame('2026-09-18T09:00:00+00:00', $outcome['verdict']['recordedAt']);

	}//end testAVerdictIsCountedAndShown()

	public function testOneReaderCountsOnce(): void {
		$existing = [['readerHash' => $this->service->readerHash(readerToken: 'reader-1'), 'helpful' => true]];

		$outcome = $this->service->recordVerdict(
			article: ['id' => 'a1', 'helpfulCount' => 3, 'notHelpfulCount' => 1],
			existingVerdicts: $existing,
			readerToken: 'reader-1',
			helpful: false
		);

		$this->assertFalse($outcome['counted']);
		$this->assertSame(3, $outcome['article']['helpfulCount']);
		$this->assertSame(1, $outcome['article']['notHelpfulCount']);
		$this->assertNull($outcome['verdict']);

	}//end testOneReaderCountsOnce()

	public function testAnotherReaderStillCounts(): void {
		$existing = [['readerHash' => $this->service->readerHash(readerToken: 'reader-1'), 'helpful' => true]];

		$outcome = $this->service->recordVerdict(
			article: ['id' => 'a1', 'helpfulCount' => 3, 'notHelpfulCount' => 0],
			existingVerdicts: $existing,
			readerToken: 'reader-2',
			helpful: false
		);

		$this->assertTrue($outcome['counted']);
		$this->assertSame(1, $outcome['article']['notHelpfulCount']);

	}//end testAnotherReaderStillCounts()

	/**
	 * The stored verdict never carries the token, so the count can be published
	 * without the reader being identifiable from what is stored.
	 */
	public function testTheStoredVerdictHoldsAHashAndNeverTheToken(): void {
		$outcome = $this->service->recordVerdict(
			article: ['id' => 'a1'],
			existingVerdicts: [],
			readerToken: 'reader@example.org',
			helpful: true
		);

		$serialised = json_encode($outcome['verdict']);
		$this->assertIsString($serialised);
		$this->assertStringNotContainsString('reader@example.org', $serialised);
		$this->assertSame(64, strlen($outcome['verdict']['readerHash']));

	}//end testTheStoredVerdictHoldsAHashAndNeverTheToken()

	public function testADifferentSaltGivesADifferentHash(): void {
		$other = new KnowledgeArticleService(salt: 'another-secret');

		$this->assertNotSame(
			$this->service->readerHash(readerToken: 'reader-1'),
			$other->readerHash(readerToken: 'reader-1')
		);

	}//end testADifferentSaltGivesADifferentHash()

	/**
	 * The extraction produces a draft, not a publication. This is the failure
	 * that matters on a publication surface: an answer written to one applicant
	 * going public because an action said "article".
	 */
	public function testTheExtractionProducesADraftThatIsNotPublic(): void {
		$draft = $this->service->extractDraft(
			case: ['id' => 'c1', 'title' => 'Vraag over de Woo', 'answer' => 'U kunt het verzoek hier indienen.']
		);

		$this->assertTrue($draft['draft']);
		$this->assertFalse($this->service->isPublic(article: $draft));
		$this->assertSame('c1', $draft['sourceCase']);
		$this->assertSame('U kunt het verzoek hier indienen.', $draft['body']);

	}//end testTheExtractionProducesADraftThatIsNotPublic()

	public function testTheCaseIsUnchangedByTheExtraction(): void {
		$case = ['id' => 'c1', 'title' => 'Vraag over de Woo', 'answer' => 'U kunt het verzoek hier indienen.'];
		$before = $case;

		$this->service->extractDraft(case: $case);

		$this->assertSame($before, $case);

	}//end testTheCaseIsUnchangedByTheExtraction()

	public function testACaseWithNoAnswerIsRefused(): void {
		$this->expectException(InvalidArgumentException::class);

		$this->service->extractDraft(case: ['id' => 'c1', 'title' => 'Vraag', 'answer' => '   ']);

	}//end testACaseWithNoAnswerIsRefused()

	public function testAPublishedArticleIsPublicAndADraftIsNot(): void {
		$this->assertTrue($this->service->isPublic(article: ['draft' => false]));
		$this->assertFalse($this->service->isPublic(article: ['draft' => true]));
		$this->assertFalse($this->service->isPublic(article: []));

	}//end testAPublishedArticleIsPublicAndADraftIsNot()
}//end class
