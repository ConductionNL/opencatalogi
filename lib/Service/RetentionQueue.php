<?php

/**
 * Sorts publications into the retention review-queue counts.
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
 * @spec openspec/specs/publication-retention-lifecycle/spec.md#requirement-retention-review-queue-and-dashboard-widget-ret-007
 */

namespace OCA\OpenCatalogi\Service;

use DateInterval;
use DateTimeImmutable;
use DateTimeInterface;

/**
 * The review queue at one moment: the search that finds its publications and
 * the count each publication adds to.
 *
 * Pure: it reads nothing but its arguments and the moment it was built for.
 *
 * @spec openspec/specs/publication-retention-lifecycle/spec.md#requirement-retention-review-queue-and-dashboard-widget-ret-007
 */
class RetentionQueue {

	/**
	 * Constructor.
	 *
	 * @param DateTimeImmutable $now The moment of the summary.
	 */
	public function __construct(
		private readonly DateTimeImmutable $now,
	) {

	}//end __construct()

	/**
	 * The search for publications whose term ends within the warning window.
	 *
	 * @param array $scope      The publication register and schema.
	 * @param int   $windowDays The warning window in days.
	 *
	 * @return array<string, mixed> The query.
	 *
	 * @spec openspec/specs/publication-retention-lifecycle/spec.md#requirement-retention-review-queue-and-dashboard-widget-ret-007
	 */
	public function query(array $scope, int $windowDays): array {
		$windowEnd = $this->now->add(new DateInterval('P' . $windowDays . 'D'));

		return [
			'@self' => $scope,
			'retentionExpiresAt' => ['$lte' => $windowEnd->format(DateTimeInterface::ATOM)],
			'_limit' => 1000,
		];
	}//end query()

	/**
	 * The summary count a publication adds to, or null for none.
	 *
	 * An archived publication counts as archived; an expired one with the
	 * review action needs review; one that has not expired yet is expiring soon.
	 *
	 * @param array $data The publication.
	 *
	 * @return string|null The summary key.
	 *
	 * @spec openspec/specs/publication-retention-lifecycle/spec.md#requirement-retention-review-queue-and-dashboard-widget-ret-007
	 */
	public function bucket(array $data): ?string {
		if ((string)($data['status'] ?? '') === 'archived') {
			return 'archived';
		}

		$expiry = $this->retentionExpiry(data: $data);
		if ($expiry === null) {
			return null;
		}

		if ($expiry > $this->now) {
			return 'expiringSoon';
		}

		return $this->reviewBucket(data: $data);
	}//end bucket()

	/**
	 * The review-required key for an expired publication whose action is review, the default.
	 *
	 * @param array $data The publication.
	 *
	 * @return string|null The summary key, or null for another action.
	 */
	private function reviewBucket(array $data): ?string {
		if ((string)($data['retentionAction'] ?? 'review') === 'review') {
			return 'reviewRequired';
		}

		return null;
	}//end reviewBucket()

	/**
	 * A publication's retention expiry, or null when it is unset or unreadable.
	 *
	 * @param array $data The publication.
	 *
	 * @return DateTimeImmutable|null The expiry.
	 */
	private function retentionExpiry(array $data): ?DateTimeImmutable {
		$expiresAt = ($data['retentionExpiresAt'] ?? null);
		if ($expiresAt === null || $expiresAt === '') {
			return null;
		}

		try {
			return new DateTimeImmutable((string)$expiresAt);
		} catch (\Throwable $e) {
			return null;
		}
	}//end retentionExpiry()
}//end class
