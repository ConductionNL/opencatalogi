<?php

/**
 * The pure retention rules over publication data.
 *
 * @category Service
 * @package  OCA\OpenCatalogi\Service
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
 * @spec openspec/specs/publication-retention-lifecycle/spec.md
 */

namespace OCA\OpenCatalogi\Service;

use DateInterval;
use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use RuntimeException;

/**
 * Validates stored defaults, computes expiries and applies decisions to a
 * publication's data, all against one moment.
 *
 * @spec openspec/specs/publication-retention-lifecycle/spec.md
 */
final class RetentionRules {

	/**
	 * What each withdrawing decision sets besides the depublication date.
	 *
	 * @var array<string, array<string, string>>
	 */
	private const WITHDRAWALS = [
		'depublish' => [],
		'archive' => ['status' => 'archived'],
		'dispose' => ['status' => 'archived'],
	];

	/**
	 * The moment the rules apply at, in UTC.
	 *
	 * @var DateTimeImmutable
	 */
	private readonly DateTimeImmutable $now;

	/**
	 * Constructor.
	 *
	 * @param DateTimeImmutable|null $now The moment the rules apply at; null for now.
	 */
	public function __construct(?DateTimeImmutable $now = null) {
		$this->now = ($now ?? new DateTimeImmutable('now', new DateTimeZone('UTC')));
	}//end __construct()

	/**
	 * Reject a defaults map whose catalog entry is not a map, or whose rule names an unknown action.
	 *
	 * @param array<string, mixed> $defaults The defaults map, catalog => category => rule.
	 *
	 * @return void
	 *
	 * @throws RuntimeException On the first invalid entry.
	 *
	 * @spec openspec/specs/publication-retention-lifecycle/spec.md#requirement-per-catalog-retention-defaults-per-woo-information-category-ret-004
	 */
	public function assertValidDefaults(array $defaults): void {
		foreach ($defaults as $catalog => $categories) {
			if (is_array($categories) === false) {
				throw new RuntimeException('Invalid retention defaults shape for catalog ' . (string)$catalog);
			}

			foreach ($categories as $category => $rule) {
				$this->assertKnownAction(rule: $rule, where: (string)$catalog . '/' . (string)$category);
			}
		}

	}//end assertValidDefaults()

	/**
	 * Reject a rule whose action is set but is not one of the retention actions.
	 *
	 * @param mixed  $rule  The rule; anything but an array is ignored.
	 * @param string $where The catalog/category the rule belongs to.
	 *
	 * @return void
	 *
	 * @throws RuntimeException When the action is unknown.
	 */
	private function assertKnownAction(mixed $rule, string $where): void {
		if (is_array($rule) === true
			&& isset($rule['action']) === true
			&& in_array($rule['action'], RetentionService::ACTIONS, true) === false
		) {
			throw new RuntimeException('Invalid retention action "' . (string)$rule['action'] . '" for ' . $where);
		}

	}//end assertKnownAction()

	/**
	 * The expiry a term of months gives from a publication date, or null when not computable.
	 *
	 * @param string|null  $publicationDate The ISO-8601 publication date.
	 * @param integer|null $termMonths      The retention term in months.
	 *
	 * @return string|null The expiry as ISO-8601.
	 *
	 * @spec openspec/specs/publication-retention-lifecycle/spec.md#requirement-retention-metadata-on-the-publication-schema-ret-003
	 */
	public function expiry(?string $publicationDate, ?int $termMonths): string|null {
		$date = $this->parseDate(value: $publicationDate);
		if ($date === null || (int)$termMonths <= 0) {
			return null;
		}

		return $date->add(new DateInterval('P' . $termMonths . 'M'))->format(DateTimeInterface::ATOM);
	}//end expiry()

	/**
	 * A date value as a moment, or null when it is empty or unreadable.
	 *
	 * @param mixed $value The stored value.
	 *
	 * @return DateTimeImmutable|null
	 *
	 * @spec openspec/specs/publication-retention-lifecycle/spec.md#requirement-daily-retention-evaluation-job-ret-005
	 */
	public function parseDate(mixed $value): ?DateTimeImmutable {
		$date = null;
		if ($value !== null && $value !== '') {
			try {
				$date = new DateTimeImmutable($value);
			} catch (\Throwable $e) {
				$date = null;
			}
		}

		return $date;
	}//end parseDate()

	/**
	 * The publication after a human decision: extended by a number of months, or withdrawn.
	 *
	 * @param array<string, mixed> $data     The publication.
	 * @param array<string, mixed> $decision The decision, its rationale note and the months to extend by.
	 *
	 * @return array<string, mixed>
	 *
	 * @throws RuntimeException On an unknown decision or an extension without positive months.
	 *
	 * @spec openspec/specs/publication-retention-lifecycle/spec.md#requirement-retention-review-queue-and-dashboard-widget-ret-007
	 */
	public function afterDecision(array $data, array $decision): array {
		if ($decision['decision'] !== 'extend') {
			return $this->withdrawn(data: $data, decision: $decision['decision']);
		}

		$data['retentionExpiresAt'] = $this->extendedExpiry(data: $data, months: $decision['months']);
		$data['retentionNote'] = $decision['note'];
		$data['retentionLastEvaluatedAt'] = '';

		return $data;
	}//end afterDecision()

	/**
	 * The publication after an expired term's depublish or archive action, marked evaluated.
	 *
	 * @param array<string, mixed> $data   The publication.
	 * @param string               $action The depublish or archive action.
	 *
	 * @return array<string, mixed>
	 *
	 * @spec openspec/specs/publication-retention-lifecycle/spec.md#requirement-daily-retention-evaluation-job-ret-005
	 */
	public function expiredWithdrawal(array $data, string $action): array {
		$data = $this->withdrawn(data: $data, decision: $action);
		$data['retentionLastEvaluatedAt'] = $this->now->format(DateTimeInterface::ATOM);

		return $data;
	}//end expiredWithdrawal()

	/**
	 * The publication with a decision appended to its decision log.
	 *
	 * @param array<string, mixed>  $data  The publication.
	 * @param array<string, string> $entry The decision, who took it and the note.
	 *
	 * @return array<string, mixed>
	 *
	 * @spec openspec/specs/publication-retention-lifecycle/spec.md#requirement-daily-retention-evaluation-job-ret-005
	 */
	public function withDecision(array $data, array $entry): array {
		$log = ($data['retentionDecisionLog'] ?? []);
		if (is_array($log) === false) {
			$log = [];
		}

		$log[] = [
			'decision' => $entry['decision'],
			'by' => $entry['by'],
			'at' => $this->now->format(DateTimeInterface::ATOM),
			'note' => $entry['note'],
			'basis' => (string)($data['retentionCategory'] ?? ''),
		];
		$data['retentionDecisionLog'] = $log;

		return $data;
	}//end withDecision()

	/**
	 * Carry the object id from the @self envelope onto the payload, so the save
	 * updates the existing object instead of creating a duplicate.
	 *
	 * @param array<string, mixed> $self The event object's @self envelope.
	 * @param array<string, mixed> $data The stamped publication payload.
	 *
	 * @return array<string, mixed> The payload with its id ensured.
	 *
	 * @spec exclude internal helper for the RET-004 publication-time hook.
	 */
	public function withObjectId(array $self, array $data): array {
		$uuid = (string)($self['uuid'] ?? ($self['id'] ?? ($data['id'] ?? '')));
		if ($uuid !== '' && empty($data['id']) === true) {
			$data['id'] = $uuid;
		}

		return $data;
	}//end withObjectId()

	/**
	 * The publication depublished now, archived too for archive and dispose.
	 *
	 * @param array<string, mixed> $data     The publication.
	 * @param string               $decision The withdrawing decision.
	 *
	 * @return array<string, mixed>
	 *
	 * @throws RuntimeException On a decision that does not withdraw.
	 */
	private function withdrawn(array $data, string $decision): array {
		if (isset(self::WITHDRAWALS[$decision]) === false) {
			throw new RuntimeException('Unknown retention decision: ' . $decision);
		}

		return array_replace($data, self::WITHDRAWALS[$decision], ['depublicationDate' => $this->now->format(DateTimeInterface::ATOM)]);
	}//end withdrawn()

	/**
	 * The stored expiry (or now, when unreadable or missing) extended by a number of months.
	 *
	 * @param array<string, mixed> $data   The publication.
	 * @param int                  $months The months to extend by.
	 *
	 * @return string The new expiry as ISO-8601.
	 *
	 * @throws RuntimeException When the months are not positive.
	 */
	private function extendedExpiry(array $data, int $months): string {
		if ($months <= 0) {
			throw new RuntimeException('Extend requires a positive number of months');
		}

		$base = ($this->parseDate(value: (string)($data['retentionExpiresAt'] ?? '')) ?? $this->now);
		return $base->add(new DateInterval('P' . $months . 'M'))->format(DateTimeInterface::ATOM);
	}//end extendedExpiry()
}//end class
