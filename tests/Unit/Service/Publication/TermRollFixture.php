<?php

/**
 * A term engine for tests: weekends and the two Christmas days are
 * non-working, which is enough to exercise a roll without reimplementing the
 * engine's calendar.
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

use DateTimeImmutable;
use DateTimeInterface;
use OCA\OpenCatalogi\Service\Publication\TermRoll;
use OCA\OpenRegister\Service\Flow\Timer\RollRule;
use OCP\App\IAppManager;
use Psr\Container\ContainerInterface;

/**
 * A duck-typed stand-in for the engine's calculator that knows weekends and Christmas.
 */
class HolidaySlaCalculator {

	public function add(DateTimeInterface $from, float $value, string $unit, ?object $calendar): DateTimeImmutable {
		return DateTimeImmutable::createFromInterface($from)->modify(sprintf('%+d days', (int)$value));
	}//end add()

	public function roll(DateTimeInterface $moment, RollRule $rule): array {
		$at = DateTimeImmutable::createFromInterface($moment);
		$rolled = $at;
		$reason = null;
		while (($why = $this->nonWorking(day: $rolled)) !== null) {
			$reason = ($reason ?? $why);
			$rolled = $rolled->modify('+1 day');
		}

		if ($reason === null) {
			return ['at' => $at, 'unrolledAt' => null, 'rolledBy' => null];
		}

		return ['at' => $rolled, 'unrolledAt' => $at, 'rolledBy' => $reason];
	}//end roll()

	private function nonWorking(DateTimeImmutable $day): ?string {
		if (in_array($day->format('m-d'), ['12-25', '12-26'], true) === true) {
			return 'Kerstmis';
		}

		if ((int)$day->format('N') >= 6) {
			return 'weekend';
		}

		return null;
	}//end nonWorking()
}//end class

/**
 * A duck-typed stand-in for the engine's calendar service.
 */
class HolidayCalendarService {

	public function resolve(?string $slug, ?string $organisation): ?object {
		return null;
	}//end resolve()
}//end class

/**
 * Builds a TermRoll over the holiday engine, or over none.
 */
trait TermRollFixture {

	/**
	 * A TermRoll whose engine is reachable, or not.
	 *
	 * @param bool $engineAvailable Whether the container binds the engine.
	 *
	 * @return TermRoll The roll.
	 */
	private function termRoll(bool $engineAvailable = true): TermRoll {
		$container = $this->createMock(ContainerInterface::class);
		if ($engineAvailable === true) {
			$container->method('get')->willReturnCallback(
				static function (string $id) {
					if ($id === 'OCA\OpenRegister\Service\Flow\Timer\SlaCalculator') {
						return new HolidaySlaCalculator();
					}

					if ($id === 'OCA\OpenRegister\Service\Flow\Timer\WorkingCalendarService') {
						return new HolidayCalendarService();
					}

					throw new \RuntimeException('not bound: ' . $id);
				}
			);
		} else {
			$container->method('get')->willThrowException(new \RuntimeException('not installed'));
		}

		$appManager = $this->createMock(IAppManager::class);
		$appManager->method('isInstalled')->willReturn(true);

		return new TermRoll($container, $appManager);
	}//end termRoll()
}//end trait
