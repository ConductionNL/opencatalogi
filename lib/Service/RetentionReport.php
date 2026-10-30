<?php

/**
 * The filter and row shape of one retention report.
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
 * @spec openspec/specs/publication-retention-lifecycle/spec.md#requirement-retention-report-export-ret-009
 */

namespace OCA\OpenCatalogi\Service;

/**
 * Which publications one {@see RetentionService::buildReport()} report lists,
 * and the row it lists each one as. The filter holds the catalog slug and the
 * publication-date bounds; null or '' is no filter.
 *
 * @spec openspec/specs/publication-retention-lifecycle/spec.md#requirement-retention-report-export-ret-009
 */
final class RetentionReport {

	/**
	 * Constructor.
	 *
	 * @param array{catalog: string|null, from: string|null, to: string|null} $filter The report filter.
	 *
	 * @spec openspec/specs/publication-retention-lifecycle/spec.md#requirement-retention-report-export-ret-009
	 */
	public function __construct(
		private readonly array $filter,
	) {
	}//end __construct()

	/**
	 * Whether a publication passes the catalog and publication-date filter.
	 *
	 * A publication without a publication date passes either date bound.
	 *
	 * @param array<string, mixed> $data The publication.
	 *
	 * @return bool
	 *
	 * @spec openspec/specs/publication-retention-lifecycle/spec.md#requirement-retention-report-export-ret-009
	 */
	public function includes(array $data): bool {
		$catalog = $this->filter['catalog'];
		if ($this->isSet(value: $catalog) === true && (string)($data['catalog'] ?? '') !== $catalog) {
			return false;
		}

		$pubDate = (string)($data['publicationDate'] ?? '');
		if ($pubDate === '') {
			return true;
		}

		$day = substr($pubDate, 0, 10);
		return ($this->isSet(value: $this->filter['from']) === false || ($day < $this->filter['from']) === false)
			&& ($this->isSet(value: $this->filter['to']) === false || ($day > $this->filter['to']) === false);
	}//end includes()

	/**
	 * One report row: the publication's retention fields and its last decision.
	 *
	 * A publication without a decision log entry reports `pending`.
	 *
	 * @param array<string, mixed> $data The publication.
	 *
	 * @return array<string, mixed>
	 *
	 * @spec openspec/specs/publication-retention-lifecycle/spec.md#requirement-retention-report-export-ret-009
	 */
	public function row(array $data): array {
		$log = ($data['retentionDecisionLog'] ?? []);
		$lastDecision = null;
		if (is_array($log) === true && count($log) > 0) {
			$lastDecision = end($log);
		}

		$actionTaken = 'pending';
		if ($lastDecision !== null) {
			$actionTaken = (string)($lastDecision['decision'] ?? '');
		}

		return [
			'id' => (string)($data['id'] ?? $data['uuid'] ?? ''),
			'title' => (string)($data['title'] ?? ''),
			'publicationDate' => (string)($data['publicationDate'] ?? ''),
			'retentionCategory' => (string)($data['retentionCategory'] ?? ''),
			'retentionTermMonths' => (string)($data['retentionTermMonths'] ?? ''),
			'retentionExpiresAt' => (string)($data['retentionExpiresAt'] ?? ''),
			'status' => (string)($data['status'] ?? ''),
			'actionTaken' => $actionTaken,
			'decisionBy' => (string)($lastDecision['by'] ?? ''),
			'decisionAt' => (string)($lastDecision['at'] ?? ''),
			'decisionNote' => (string)($lastDecision['note'] ?? ''),
		];
	}//end row()

	/**
	 * Whether a filter value is set.
	 *
	 * @param string|null $value The filter value.
	 *
	 * @return bool
	 *
	 * @spec exclude Type-narrowing plumbing.
	 */
	private function isSet(?string $value): bool {
		return $value !== null && $value !== '';
	}//end isSet()
}//end class
