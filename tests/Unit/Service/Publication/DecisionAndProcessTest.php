<?php

declare(strict_types=1);

namespace Unit\Service\Publication;

use DateTimeImmutable;
use DateTimeZone;
use DomainException;
use OCA\OpenCatalogi\Service\Publication\DecisionPublicationValidator;
use OCA\OpenCatalogi\Service\Publication\PublicationProcessService;
use OCA\OpenCatalogi\Service\Publication\ZienswijzeService;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the decision-type validation, the walked process and the
 * zienswijze round.
 *
 * @covers \OCA\OpenCatalogi\Service\Publication\DecisionPublicationValidator
 * @covers \OCA\OpenCatalogi\Service\Publication\PublicationProcessService
 * @covers \OCA\OpenCatalogi\Service\Publication\ZienswijzeService
 */
class DecisionAndProcessTest extends TestCase {

	private DecisionPublicationValidator $validator;
	private PublicationProcessService $process;
	private ZienswijzeService $zienswijze;

	protected function setUp(): void {
		$this->validator = new DecisionPublicationValidator();
		$this->process = new PublicationProcessService();
		$this->zienswijze = new ZienswijzeService();

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

	/**
	 * A besluittype that obliges publication.
	 *
	 * @return array<string, mixed>
	 */
	private function obligedType(): array {
		return [
			'publicationObligation' => true,
			'responseTermDays' => 42,
			'publicationText' => 'Tegen dit besluit kunt u bezwaar maken.',
		];

	}//end obligedType()

	public function testADecisionWithoutAPublicationDateIsRefusedAndTheReasonNamesTheDate(): void {
		$validation = $this->validator->validate(
			decision: ['id' => 'b1', 'title' => 'Kapvergunning'],
			decisionType: $this->obligedType()
		);

		$this->assertFalse($validation['publishable']);
		$this->assertStringContainsString('publication date', implode(' ', $validation['reasons']));

	}//end testADecisionWithoutAPublicationDateIsRefusedAndTheReasonNamesTheDate()

	/**
	 * A date nobody can read is refused as unreadable, not as absent: the two
	 * send the caller to different places.
	 */
	public function testAnUnreadablePublicationDateIsRefusedAsUnreadable(): void {
		$validation = $this->validator->validate(
			decision: ['publicationDate' => 'binnenkort'],
			decisionType: $this->obligedType()
		);

		$this->assertFalse($validation['publishable']);
		$this->assertStringContainsString('cannot be read as a date', implode(' ', $validation['reasons']));

	}//end testAnUnreadablePublicationDateIsRefusedAsUnreadable()

	public function testTheResponseDateIsComputedFromTheTermAndNotTyped(): void {
		$validation = $this->validator->validate(
			decision: ['publicationDate' => '2026-09-01T00:00:00+00:00', 'responseDate' => '2099-01-01'],
			decisionType: $this->obligedType()
		);

		$this->assertTrue($validation['publishable']);
		$this->assertSame('2026-10-13T00:00:00+00:00', $validation['responseDate']);

		$applied = $this->validator->applyValidated(
			decision: ['publicationDate' => '2026-09-01T00:00:00+00:00', 'responseDate' => '2099-01-01'],
			validation: $validation
		);

		$this->assertSame('2026-10-13T00:00:00+00:00', $applied['responseDate']);
		$this->assertSame('Tegen dit besluit kunt u bezwaar maken.', $applied['publicationText']);

	}//end testTheResponseDateIsComputedFromTheTermAndNotTyped()

	public function testATypeWithoutAnObligationPublishesWithoutADate(): void {
		$validation = $this->validator->validate(
			decision: [],
			decisionType: ['publicationObligation' => false]
		);

		$this->assertTrue($validation['publishable']);

	}//end testATypeWithoutAnObligationPublishesWithoutADate()

	public function testApplyingARefusedValidationThrows(): void {
		$validation = $this->validator->validate(decision: [], decisionType: $this->obligedType());

		$this->expectException(DomainException::class);

		$this->validator->applyValidated(decision: [], validation: $validation);

	}//end testApplyingARefusedValidationThrows()

	public function testEveryStepRecordsWhoCompletedItAndWhen(): void {
		$process = $this->process->start(publicationId: 'p1');

		foreach (PublicationProcessService::STEPS as $step) {
			$process = $this->process->complete(
				process: $process,
				step: $step,
				completedBy: 'ambtenaar',
				now: $this->at('2026-09-18T10:00:00+00:00')
			);
		}

		$this->assertSame('complete', $process['state']);
		foreach ($process['steps'] as $recorded) {
			$this->assertSame(PublicationProcessService::COMPLETE, $recorded['status']);
			$this->assertSame('ambtenaar', $recorded['completedBy']);
			$this->assertSame('2026-09-18T10:00:00+00:00', $recorded['completedAt']);
		}

	}//end testEveryStepRecordsWhoCompletedItAndWhen()

	/**
	 * A skipped step is recorded as configured off, never as done. A later
	 * reading has to be able to tell an approval that happened from one that
	 * was never asked for.
	 */
	public function testSkippedStepsAreRecordedAsConfiguredOffAndNotAsDone(): void {
		$process = $this->process->start(
			publicationId: 'p1',
			enabledSteps: ['zienswijze' => false, 'approval' => false]
		);

		$byStep = array_column($process['steps'], null, 'step');
		$this->assertSame(PublicationProcessService::SKIPPED, $byStep['zienswijze']['status']);
		$this->assertSame(PublicationProcessService::SKIPPED, $byStep['approval']['status']);
		$this->assertNull($byStep['zienswijze']['completedBy']);
		$this->assertNotSame(PublicationProcessService::COMPLETE, $byStep['approval']['status']);

		$process = $this->process->complete(process: $process, step: 'documents', completedBy: 'a');
		$process = $this->process->complete(process: $process, step: 'channels', completedBy: 'a');

		$this->assertTrue($this->process->isComplete(process: $process));
		$this->assertSame('complete', $process['state']);

	}//end testSkippedStepsAreRecordedAsConfiguredOffAndNotAsDone()

	public function testAStepConfiguredOffCannotBeCompleted(): void {
		$process = $this->process->start(publicationId: 'p1', enabledSteps: ['approval' => false]);

		$this->expectException(DomainException::class);

		$this->process->complete(process: $process, step: 'approval', completedBy: 'a');

	}//end testAStepConfiguredOffCannotBeCompleted()

	public function testThePublicationIsHeldWhileAnAskIsOpenInsideItsTerm(): void {
		$process = $this->process->start(publicationId: 'p1');
		$ask = $this->zienswijze->raise(
			publicationId: 'p1',
			party: 'J. de Vries',
			channel: 'mijn-overheid-berichtenbox',
			termDays: 14,
			now: $this->at('2026-09-01T00:00:00+00:00')
		);

		$advance = $this->process->mayAdvance(
			process: $process,
			asks: [$ask],
			now: $this->at('2026-09-05T00:00:00+00:00')
		);

		$this->assertFalse($advance['mayAdvance']);
		$this->assertSame('J. de Vries', $advance['heldBy'][0]['party']);

	}//end testThePublicationIsHeldWhileAnAskIsOpenInsideItsTerm()

	public function testAnAskPastItsTermNoLongerHoldsThePublication(): void {
		$ask = $this->zienswijze->raise(
			publicationId: 'p1',
			party: 'J. de Vries',
			channel: 'mijn-overheid-berichtenbox',
			termDays: 14,
			now: $this->at('2026-09-01T00:00:00+00:00')
		);

		$advance = $this->process->mayAdvance(
			process: $this->process->start(publicationId: 'p1'),
			asks: [$ask],
			now: $this->at('2026-10-01T00:00:00+00:00')
		);

		$this->assertTrue($advance['mayAdvance']);

	}//end testAnAskPastItsTermNoLongerHoldsThePublication()

	public function testAnAnsweredAskDoesNotHoldThePublication(): void {
		$ask = $this->zienswijze->raise(
			publicationId: 'p1',
			party: 'J. de Vries',
			channel: 'portal-message',
			termDays: 14,
			now: $this->at('2026-09-01T00:00:00+00:00')
		);
		$ask = $this->zienswijze->answer(
			ask: $ask,
			answer: 'Geen bezwaar.',
			answeredBy: 'J. de Vries',
			now: $this->at('2026-09-03T00:00:00+00:00')
		);

		$advance = $this->process->mayAdvance(
			process: $this->process->start(publicationId: 'p1'),
			asks: [$ask],
			now: $this->at('2026-09-05T00:00:00+00:00')
		);

		$this->assertTrue($advance['mayAdvance']);
		$this->assertSame('J. de Vries', $ask['answeredBy']);
		$this->assertSame('2026-09-03T00:00:00+00:00', $ask['answeredAt']);

	}//end testAnAnsweredAskDoesNotHoldThePublication()

	/**
	 * An ask over a channel that cannot say who answered produces an answer
	 * nobody can rely on, and the answer is what permits the publication.
	 */
	public function testAnUnidentifiedChannelIsRefused(): void {
		$this->expectException(DomainException::class);

		$this->zienswijze->raise(
			publicationId: 'p1',
			party: 'J. de Vries',
			channel: 'anonymous-webform',
			termDays: 14
		);

	}//end testAnUnidentifiedChannelIsRefused()

	public function testAnUnattributedAnswerIsRefused(): void {
		$ask = $this->zienswijze->raise(
			publicationId: 'p1',
			party: 'J. de Vries',
			channel: 'digid',
			termDays: 14
		);

		$this->expectException(DomainException::class);

		$this->zienswijze->answer(ask: $ask, answer: 'Geen bezwaar.', answeredBy: '  ');

	}//end testAnUnattributedAnswerIsRefused()

	public function testAnAskWithAnUnreadableTermHoldsThePublication(): void {
		$advance = $this->process->mayAdvance(
			process: $this->process->start(publicationId: 'p1'),
			asks: [['party' => 'J. de Vries', 'termEndsAt' => 'ooit', 'answeredAt' => null]],
			now: $this->at('2026-09-05T00:00:00+00:00')
		);

		$this->assertFalse($advance['mayAdvance']);
		$this->assertSame('unreadable-term', $advance['heldBy'][0]['reason']);

	}//end testAnAskWithAnUnreadableTermHoldsThePublication()
}//end class
