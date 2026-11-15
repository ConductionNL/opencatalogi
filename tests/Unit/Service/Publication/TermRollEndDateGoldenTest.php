<?php

/**
 * Golden test for what TermRoll::endDate() makes of the term engine's answer:
 * the landing, the rolled-from moment, and a reason that is only kept when
 * it says something.
 *
 * @category Test
 * @package  OCA\OpenCatalogi\Tests\Unit\Service\Publication
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

require_once __DIR__ . '/CommentPeriodOpenGoldenTest.php';

use DateTimeImmutable;
use OCA\OpenCatalogi\Service\Publication\TermRoll;
use OCA\OpenCatalogi\Service\Publication\TermRollUnavailableException;
use OCP\App\IAppManager;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;

/**
 * A calculator that rolls with a fixed answer.
 */
class AnsweringSlaCalculator extends GoldenSlaCalculator {

	/**
	 * @param array<string, mixed> $answer What roll() answers.
	 */
	public function __construct(private array $answer) {
	}

	public function roll(\DateTimeInterface $moment, \OCA\OpenRegister\Service\Flow\Timer\RollRule $rule): array {
		return $this->answer;
	}//end roll()
}//end class

/**
 * @covers \OCA\OpenCatalogi\Service\Publication\TermRoll
 */
class TermRollEndDateGoldenTest extends TestCase {

	/**
	 * Roll answers and what endDate() makes of each, as text.
	 *
	 * @return array<string, array{0: array<string, mixed>, 1: array<string, string|null>|string}>
	 */
	public static function answers(): array {
		$at = new DateTimeImmutable('2026-03-30T00:00:00+00:00');
		$from = new DateTimeImmutable('2026-03-28T00:00:00+00:00');
		return [
			'rolled' => [['at' => $at, 'unrolledAt' => $from, 'rolledBy' => 'weekend'], ['at' => '2026-03-30T00:00:00+00:00', 'unrolledAt' => '2026-03-28T00:00:00+00:00', 'rolledBy' => 'weekend']],
			'blank reason' => [['at' => $at, 'rolledBy' => '  '], ['at' => '2026-03-30T00:00:00+00:00', 'unrolledAt' => null, 'rolledBy' => null]],
			'reason not text' => [['at' => $at, 'rolledBy' => 5], ['at' => '2026-03-30T00:00:00+00:00', 'unrolledAt' => null, 'rolledBy' => null]],
			'no landing: the added date' => [[], ['at' => '2026-03-01T00:00:00+00:00', 'unrolledAt' => null, 'rolledBy' => null]],
			'a landing that is not a date' => [['at' => 'monday'], 'refused'],
		];
	}

	/**
	 * What endDate() makes of one roll answer.
	 *
	 * @param array<string, mixed> $answer What the engine's roll() answers.
	 * @param array<string, string|null>|string $expected The end date as text, or "refused".
	 *
	 * @return void
	 */
	#[DataProvider('answers')]
	public function testEndDate(array $answer, array|string $expected): void {
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			static fn (string $id): object => $id === 'OCA\OpenRegister\Service\Flow\Timer\SlaCalculator' ? new AnsweringSlaCalculator($answer) : new GoldenCalendarService()
		);
		$appManager = $this->createMock(IAppManager::class);
		$appManager->method('isInstalled')->willReturn(true);

		try {
			$end = (new TermRoll($container, $appManager))->endDate(start: new DateTimeImmutable('2026-02-01T00:00:00+00:00'), days: 28);
			$actual = array_map(static fn ($value) => ($value instanceof \DateTimeInterface ? $value->format(DATE_ATOM) : $value), $end);
		} catch (TermRollUnavailableException $e) {
			$actual = 'refused';
		}

		$this->assertSame($expected, $actual);
	}
}
