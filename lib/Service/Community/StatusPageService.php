<?php

/**
 * OpenCatalogi Status Page Service.
 *
 * A public status page listing named components with the state somebody set on
 * them. It probes nothing. A status page that infers a component's health from
 * a health check tells the reader that the monitoring is up, which is the
 * failure mode every incident starts with.
 *
 * A state nobody has touched for the configured staleness period is shown as
 * stale, with the date it was last set. A page that is out of date is worse
 * than no page, and the one thing it must not do is render a confident green
 * over a fact nobody has checked since Tuesday.
 *
 * @category Service
 * @package  OCA\OpenCatalogi\Service\Community
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2024 Conduction B.V. <info@conduction.nl>
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenCatalogi.nl
 *
 * @spec openspec/changes/the-public-and-community-surface/specs/public-and-community-surface/spec.md#requirement-a-public-status-page-says-what-is-running-req-pcs-101
 */

declare(strict_types=1);

namespace OCA\OpenCatalogi\Service\Community;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use DomainException;

/**
 * Renders the status page from states an administrator set.
 *
 * @spec openspec/changes/the-public-and-community-surface/specs/public-and-community-surface/spec.md#requirement-a-public-status-page-says-what-is-running-req-pcs-101
 */
class StatusPageService {

	/**
	 * The states a component may be in.
	 *
	 * @var array<int, string>
	 */
	public const STATES = ['available', 'degraded', 'unavailable', 'maintenance'];

	/**
	 * How long a state stays current, in hours, when nothing else is configured.
	 *
	 * @var integer
	 */
	public const DEFAULT_STALENESS_HOURS = 24;

	/**
	 * Set a component's state.
	 *
	 * The setter is a person or an integration, and it is recorded. There is no
	 * path in this class that derives a state from anything, which is what
	 * makes "the page probes nothing" true rather than merely intended.
	 *
	 * @param string $component The component a reader recognises.
	 * @param string $state The state.
	 * @param string $message What a reader should do about it.
	 * @param string $setBy Who set it.
	 * @param DateTimeInterface|null $now The moment; defaults to now.
	 *
	 * @return array<string, mixed> The status to save.
	 *
	 * @throws DomainException When the state is not one this page knows.
	 *
	 * @spec openspec/changes/the-public-and-community-surface/specs/public-and-community-surface/spec.md#requirement-a-public-status-page-says-what-is-running-req-pcs-101
	 */
	public function setState(
		string $component,
		string $state,
		string $message,
		string $setBy,
		?DateTimeInterface $now = null,
	): array {
		if (in_array($state, self::STATES, true) === false) {
			throw new DomainException(
				message: 'A component cannot be set to "' . $state . '": the status page knows only '
					. implode(', ', self::STATES) . '.'
			);
		}

		if (trim($component) === '') {
			throw new DomainException(message: 'A status needs the component it is about.');
		}

		$moment = ($now === null)
			? new DateTimeImmutable('now', new DateTimeZone('UTC'))
			: DateTimeImmutable::createFromInterface($now);

		return [
			'component' => trim($component),
			'state' => $state,
			'message' => $message,
			'stateSetAt' => $moment->format(DateTimeInterface::ATOM),
			'stateSetBy' => $setBy,
		];

	}//end setState()

	/**
	 * The page as an anonymous reader sees it.
	 *
	 * @param array<int, array<string, mixed>> $components The stored statuses.
	 * @param integer $stalenessHours How long a state stays current.
	 * @param DateTimeInterface|null $now The moment; defaults to now.
	 *
	 * @return array{components: array<int, array<string, mixed>>, stale: integer, probed: boolean}
	 *
	 * @spec openspec/changes/the-public-and-community-surface/specs/public-and-community-surface/spec.md#requirement-a-public-status-page-says-what-is-running-req-pcs-101
	 */
	public function render(
		array $components,
		int $stalenessHours = self::DEFAULT_STALENESS_HOURS,
		?DateTimeInterface $now = null,
	): array {
		$moment = ($now === null)
			? new DateTimeImmutable('now', new DateTimeZone('UTC'))
			: DateTimeImmutable::createFromInterface($now);

		$rendered = [];
		$stale = 0;

		foreach ($components as $component) {
			if (is_array($component) === false) {
				continue;
			}

			$setAt = trim((string)($component['stateSetAt'] ?? ''));
			$isStale = true;

			if ($setAt !== '') {
				try {
					$setAtMoment = new DateTimeImmutable($setAt);
					$ageHours = (($moment->getTimestamp() - $setAtMoment->getTimestamp()) / 3600);
					$isStale = ($ageHours > $stalenessHours);
				} catch (\Throwable $e) {
					// A date nobody can read is not evidence of freshness.
					$isStale = true;
				}
			}

			if ($isStale === true) {
				$stale++;
			}

			$rendered[] = [
				'component' => (string)($component['component'] ?? ''),
				'state' => (string)($component['state'] ?? ''),
				'message' => (string)($component['message'] ?? ''),
				'stateSetAt' => ($setAt === '' ? null : $setAt),
				'stale' => $isStale,
				'order' => (int)($component['order'] ?? 0),
			];
		}

		usort(
			$rendered,
			static fn (array $left, array $right): int => ($left['order'] <=> $right['order'])
		);

		return [
			'components' => $rendered,
			'stale' => $stale,

			// Stated in the response rather than only in a comment, so a caller
			// can see that these states were set and not measured.
			'probed' => false,
		];

	}//end render()

	/**
	 * Whether a state change happened between two statuses.
	 *
	 * Used to decide whether the notification dialect has anything to announce.
	 * A saved status whose state did not move is not a change, and telling
	 * every subscriber otherwise is how a status page trains people to ignore
	 * it.
	 *
	 * @param array<string, mixed>|null $before The status before.
	 * @param array<string, mixed> $after The status after.
	 *
	 * @return boolean True when the state moved.
	 *
	 * @spec openspec/changes/the-public-and-community-surface/specs/public-and-community-surface/spec.md#requirement-a-reader-subscribes-to-changes-on-the-status-page-req-pcs-102
	 */
	public function stateChanged(?array $before, array $after): bool {
		if ($before === null) {
			return true;
		}

		return ((string)($before['state'] ?? '') !== (string)($after['state'] ?? ''));

	}//end stateChanged()
}//end class
