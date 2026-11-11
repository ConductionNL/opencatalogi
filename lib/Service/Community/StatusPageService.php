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
use OCA\OpenCatalogi\Contract\StatusChange;
use OCA\OpenCatalogi\Contract\StatusPageServiceInterface;

/**
 * Renders the status page from states an administrator set.
 *
 * @spec openspec/changes/the-public-and-community-surface/specs/public-and-community-surface/spec.md#requirement-a-public-status-page-says-what-is-running-req-pcs-101
 */
class StatusPageService implements StatusPageServiceInterface {

	/**
	 * The states a component may be in.
	 *
	 * @var array<int, string>
	 */
	public const STATES = ['available', 'degraded', 'unavailable', 'maintenance'];

	/**
	 * Set a component's state.
	 *
	 * The setter is a person or an integration, and it is recorded. There is no
	 * path in this class that derives a state from anything, which is what
	 * makes "the page probes nothing" true rather than merely intended.
	 *
	 * @param StatusChange $change The component, its state, the message and who set it.
	 * @param DateTimeInterface|null $now The moment; defaults to now.
	 *
	 * @return array<string, mixed> The status to save.
	 *
	 * @throws DomainException When the state is not one this page knows.
	 *
	 * @spec openspec/changes/the-public-and-community-surface/specs/public-and-community-surface/spec.md#requirement-a-public-status-page-says-what-is-running-req-pcs-101
	 */
	public function setState(StatusChange $change, ?DateTimeInterface $now = null): array {
		self::assertKnownState(state: $change->state);
		if (trim($change->component) === '') {
			throw new DomainException(message: 'A status needs the component it is about.');
		}

		return [
			'component' => trim($change->component),
			'state' => $change->state,
			'message' => $change->message,
			'stateSetAt' => self::momentOf(now: $now)->format(DateTimeInterface::ATOM),
			'stateSetBy' => $change->setBy,
		];

	}//end setState()

	/**
	 * Refuse a state this page does not know.
	 *
	 * @param string $state The state.
	 *
	 * @return void
	 *
	 * @throws DomainException When the state is not one of STATES.
	 */
	private static function assertKnownState(string $state): void {
		if (in_array($state, self::STATES, true) === false) {
			throw new DomainException(
				message: 'A component cannot be set to "' . $state . '": the status page knows only '
					. implode(', ', self::STATES) . '.'
			);
		}
	}//end assertKnownState()

	/**
	 * The given moment, else now in UTC.
	 *
	 * @param DateTimeInterface|null $now The moment.
	 *
	 * @return DateTimeImmutable
	 */
	private static function momentOf(?DateTimeInterface $now): DateTimeImmutable {
		if ($now === null) {
			return new DateTimeImmutable('now', new DateTimeZone('UTC'));
		}

		return new DateTimeImmutable($now->format('Y-m-d\\TH:i:s.uP'));
	}//end momentOf()

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
		$rendered = self::renderedComponents(
			components: $components,
			clock: ['now' => self::momentOf(now: $now), 'hours' => $stalenessHours]
		);

		usort(
			$rendered,
			static fn (array $left, array $right): int => ($left['order'] <=> $right['order'])
		);

		return [
			'components' => $rendered,
			'stale' => count(array_filter(array_column($rendered, 'stale'))),

			// Stated in the response rather than only in a comment, so a caller
			// can see that these states were set and not measured.
			'probed' => false,
		];

	}//end render()

	/**
	 * Every stored component that is an array, as the page shows it.
	 *
	 * @param array<int, mixed> $components The stored statuses.
	 * @param array{now: DateTimeImmutable, hours: int} $clock The moment of rendering and the staleness window.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private static function renderedComponents(array $components, array $clock): array {
		$rendered = [];
		foreach ($components as $component) {
			if (is_array($component) === true) {
				$rendered[] = self::renderComponent(component: $component, clock: $clock);
			}
		}

		return $rendered;
	}//end renderedComponents()

	/**
	 * One component as the page shows it, with whether its state is stale.
	 *
	 * @param array $component The stored component.
	 * @param array{now: DateTimeImmutable, hours: int} $clock The moment of rendering and the staleness window.
	 *
	 * @return array{component: string, state: string, message: string, stateSetAt: string|null, stale: bool, order: int}
	 *
	 * @spec openspec/changes/the-public-and-community-surface/specs/public-and-community-surface/spec.md#requirement-a-public-status-page-says-what-is-running-req-pcs-101
	 */
	private static function renderComponent(array $component, array $clock): array {
		$setAt = trim((string)($component['stateSetAt'] ?? ''));
		$stateSetAt = null;
		if ($setAt !== '') {
			$stateSetAt = $setAt;
		}

		return [
			'component' => (string)($component['component'] ?? ''),
			'state' => (string)($component['state'] ?? ''),
			'message' => (string)($component['message'] ?? ''),
			'stateSetAt' => $stateSetAt,
			'stale' => self::isStale(setAt: $setAt, clock: $clock),
			'order' => (int)($component['order'] ?? 0),
		];
	}//end renderComponent()

	/**
	 * Whether a state set at this moment is older than the window; no date, or a date
	 * nobody can read, is not evidence of freshness.
	 *
	 * @param string $setAt The moment the state was set, or empty.
	 * @param array{now: DateTimeImmutable, hours: int} $clock The moment of rendering and the staleness window.
	 *
	 * @return bool
	 *
	 * @spec openspec/changes/the-public-and-community-surface/specs/public-and-community-surface/spec.md#requirement-a-public-status-page-says-what-is-running-req-pcs-101
	 */
	private static function isStale(string $setAt, array $clock): bool {
		if ($setAt === '') {
			return true;
		}

		try {
			$setAtMoment = new DateTimeImmutable($setAt);
		} catch (\Throwable $e) {
			return true;
		}

		return (($clock['now']->getTimestamp() - $setAtMoment->getTimestamp()) / 3600) > $clock['hours'];
	}//end isStale()

}//end class
