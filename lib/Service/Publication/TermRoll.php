<?php

/**
 * Rolls a term's end date off a non-working day, through OpenRegister.
 *
 * The Algemene termijnenwet says a term that ends on a Saturday, a Sunday or a
 * public holiday runs to the next working day. Which day that is depends on the
 * working calendar, and OpenRegister already resolves one, computes Easter by the
 * anonymous Gregorian algorithm, and recomputes a stored deadline when the
 * calendar changes. This class hands the question over and refuses when it
 * cannot: a second implementation of the Algemene termijnenwet behind a statutory
 * period is worse than an honest refusal.
 *
 * @category Service
 * @package  OCA\OpenCatalogi\Service\Publication
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenCatalogi.nl
 *
 * @spec openspec/specs/publication-comment-periods/spec.md#requirement-the-period-ends-on-a-working-day-req-pcp-002
 */

declare(strict_types=1);

namespace OCA\OpenCatalogi\Service\Publication;

use DateTimeImmutable;
use DateTimeInterface;
use OCP\App\IAppManager;
use Psr\Container\ContainerInterface;

/**
 * Computes a rolled end date through the consumed OpenRegister term engine.
 *
 * @spec openspec/specs/publication-comment-periods/spec.md#requirement-the-period-ends-on-a-working-day-req-pcp-002
 */
class TermRoll {

	/**
	 * Cached OpenRegister SlaCalculator.
	 *
	 * @var object|null
	 */
	private ?object $calculator = null;

	/**
	 * Cached OpenRegister WorkingCalendarService.
	 *
	 * @var object|null
	 */
	private ?object $calendars = null;

	/**
	 * Constructor.
	 *
	 * @param ContainerInterface $container Server container, for the consumed term engine.
	 * @param IAppManager $appManager Answers whether OpenRegister is installed.
	 */
	public function __construct(
		private readonly ContainerInterface $container,
		private readonly IAppManager $appManager,
	) {

	}//end __construct()

	/**
	 * The end date of a term of so many days, rolled to the next working day.
	 *
	 * @param DateTimeInterface $start When the term opens.
	 * @param int $days How many days it runs.
	 * @param string|null $calendarSlug The working calendar, or null for the resolved default.
	 * @param string|null $organisation The organisation the calendar belongs to.
	 *
	 * @return array{at: DateTimeImmutable, unrolledAt: DateTimeImmutable|null, rolledBy: string|null} Where it lands, and why it moved.
	 *
	 * @throws TermRollUnavailableException When the term engine cannot be reached.
	 *
	 * @spec openspec/specs/publication-comment-periods/spec.md#requirement-the-period-ends-on-a-working-day-req-pcp-002
	 */
	public function endDate(
		DateTimeInterface $start,
		int $days,
		?string $calendarSlug = null,
		?string $organisation = null,
	): array {
		$calculator = $this->calculator();
		$calendar = $this->calendars()->resolve($calendarSlug, $organisation);

		// Calendar days, because a public comment period is a span of calendar
		// time; only its LANDING is moved off a non-working day.
		$landed = $calculator->add($start, (float)$days, 'calendarDays', $calendar);
		$rolled = $calculator->roll($landed, 'next', $calendar);

		$endsAt = ($rolled['at'] ?? $landed);
		if (($endsAt instanceof DateTimeInterface) === false) {
			throw new TermRollUnavailableException(
				message: 'The term engine answered with no end date, so the comment period has no close it can defend.'
			);
		}

		$rolledBy = ($rolled['rolledBy'] ?? null);
		if (is_string($rolledBy) === false || trim($rolledBy) === '') {
			$rolledBy = null;
		}

		return [
			'at' => $this->immutable(moment: $endsAt),
			'unrolledAt' => $this->immutable(moment: ($rolled['unrolledAt'] ?? null)),
			'rolledBy' => $rolledBy,
		];

	}//end endDate()

	/**
	 * Normalise a moment the engine answered with, or null when it answered none.
	 *
	 * @param mixed $moment The engine's answer.
	 *
	 * @return DateTimeImmutable|null The moment.
	 *
	 * @spec exclude internal helper — normalises a moment from the term engine.
	 */
	private function immutable(mixed $moment): ?DateTimeImmutable {
		if (($moment instanceof DateTimeInterface) === false) {
			return null;
		}

		return new DateTimeImmutable($moment->format('Y-m-d\\TH:i:s.uP'));

	}//end immutable()

	/**
	 * Resolve the consumed calculator.
	 *
	 * @return object The calculator.
	 *
	 * @throws TermRollUnavailableException When it cannot be reached.
	 *
	 * @spec exclude pure framework plumbing — resolves the consumed OR calculator.
	 */
	private function calculator(): object {
		if ($this->calculator === null) {
			// ADR-083: establish availability before the reach, so the refusal
			// names the missing app rather than a container key.
			if ($this->appManager->isInstalled('openregister') === false) {
				throw new TermRollUnavailableException(
					message: 'OpenRegister is not installed, so the end date of this period cannot be rolled off a '
						. 'non-working day. It is not computed here instead.'
				);
			}

			try {
				$this->calculator = $this->container->get('OCA\OpenRegister\Service\Flow\Timer\SlaCalculator');
			} catch (\Throwable $e) {
				throw new TermRollUnavailableException(
					message: 'The term engine is unavailable, so the end date of this period cannot be rolled off a '
						. 'non-working day. It is not computed here instead.',
					code: 0,
					previous: $e
				);
			}
		}

		return $this->calculator;

	}//end calculator()

	/**
	 * Resolve the consumed calendar service.
	 *
	 * @return object The calendar service.
	 *
	 * @throws TermRollUnavailableException When it cannot be reached.
	 *
	 * @spec exclude pure framework plumbing — resolves the consumed OR calendar service.
	 */
	private function calendars(): object {
		if ($this->calendars === null) {
			// No second availability check: endDate() resolves the calculator
			// first, so this method is only reached once ADR-083's check in
			// calculator() has passed. A duplicate guard here would be a branch
			// no run can take.
			try {
				$this->calendars = $this->container->get('OCA\OpenRegister\Service\Flow\Timer\WorkingCalendarService');
			} catch (\Throwable $e) {
				throw new TermRollUnavailableException(
					message: 'No working calendar can be resolved, so there is no answer to which day is the next '
						. 'working one.',
					code: 0,
					previous: $e
				);
			}
		}

		return $this->calendars;

	}//end calendars()
}//end class
