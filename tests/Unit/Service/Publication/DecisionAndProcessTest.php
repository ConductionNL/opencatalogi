<?php

declare(strict_types=1);

namespace Unit\Service\Publication;

use DateTimeImmutable;
use DateTimeZone;
use DomainException;
use OCA\OpenCatalogi\Service\Publication\DecisionPublicationValidator;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the decision-type validation, the walked process and the
 * zienswijze round.
 *
 * @covers \OCA\OpenCatalogi\Service\Publication\DecisionPublicationValidator
 */
class DecisionAndProcessTest extends TestCase {

	private DecisionPublicationValidator $validator;

	protected function setUp(): void {
		$this->validator = new DecisionPublicationValidator();

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

	/**
	 * The whole verdict, key for key, for each way validate() can answer.
	 */
	public function testValidateAnswersWithTheWholeVerdictInEachCase(): void {
		$this->assertSame(
			['publishable' => true, 'reasons' => [], 'responseDate' => null, 'publicationText' => 'Zie bijlage.'],
			$this->validator->validate(
				decision: ['publicationDate' => 'binnenkort'],
				decisionType: ['publicationObligation' => false, 'responseTermDays' => 0, 'publicationText' => ' Zie bijlage. ']
			)
		);

		$this->assertSame(
			[
				'publishable' => false,
				'reasons' => [
					'This decision type obliges publication, and the decision carries no publication date.',
					'This decision type obliges publication but declares no statutory response term.',
				],
				'responseDate' => null,
				'publicationText' => null,
			],
			$this->validator->validate(decision: [], decisionType: ['publicationObligation' => true])
		);

		$this->assertSame(
			[
				'publishable' => false,
				'reasons' => ['This decision type obliges publication but declares no statutory response term.'],
				'responseDate' => null,
				'publicationText' => null,
			],
			$this->validator->validate(
				decision: ['publicationDate' => '2026-09-01T00:00:00+00:00'],
				decisionType: ['publicationObligation' => true, 'responseTermDays' => -3]
			)
		);

		$this->assertSame(
			[
				'publishable' => false,
				'reasons' => ['The publication date on this decision cannot be read as a date: "binnenkort".'],
				'responseDate' => null,
				'publicationText' => 'Tegen dit besluit kunt u bezwaar maken.',
			],
			$this->validator->validate(decision: ['publicationDate' => 'binnenkort'], decisionType: $this->obligedType())
		);

		$this->assertSame(
			[
				'publishable' => true,
				'reasons' => [],
				'responseDate' => '2026-10-13T00:00:00+00:00',
				'publicationText' => 'Tegen dit besluit kunt u bezwaar maken.',
			],
			$this->validator->validate(
				decision: ['publicationDate' => '2026-09-01T00:00:00+00:00'],
				decisionType: $this->obligedType()
			)
		);

	}//end testValidateAnswersWithTheWholeVerdictInEachCase()

	public function testApplyingARefusedValidationThrows(): void {
		$validation = $this->validator->validate(decision: [], decisionType: $this->obligedType());

		$this->expectException(DomainException::class);

		$this->validator->applyValidated(decision: [], validation: $validation);

	}//end testApplyingARefusedValidationThrows()

}//end class
