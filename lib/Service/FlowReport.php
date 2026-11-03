<?php

/**
 * Reads the harvest flow's schedule and its runs for the admin section.
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
 * @spec openspec/changes/publiccode-github-harvest/specs/publiccode-github-harvest/spec.md
 */

namespace OCA\OpenCatalogi\Service;

use DateTimeInterface;

/**
 * The schedule trigger of a flow and the summary of one of its runs, as plain arrays.
 *
 * @spec openspec/changes/publiccode-github-harvest/specs/publiccode-github-harvest/spec.md
 */
final class FlowReport {

	/**
	 * The fields of a run the section shows.
	 *
	 * @param object $run A FlowRun entity.
	 *
	 * @return array<string, mixed> The summary.
	 *
	 * @spec openspec/changes/publiccode-github-harvest/specs/publiccode-github-harvest/spec.md#requirement-req-pgh-004-a-file-that-cannot-be-read-is-skipped-not-written
	 */
	public function runSummary(object $run): array {
		return [
			'uuid' => $run->getUuid(),
			'status' => $run->getStatus(),
			'trigger' => $run->getTrigger(),
			'started' => self::atom(value: $run->getCreated()),
			'updated' => self::atom(value: $run->getUpdated()),
			'resumeAt' => self::atom(value: $run->getResumeAt()),
			'error' => $run->getError(),
			// Steps carried past by `onError: continue`: a shard whose write the
			// schema refused ends on a failed step while the run still reads as
			// finished, so without this count 24 clean shards and 24 shards of
			// which three lost a page look the same (REQ-PGH-004).
			'failedSteps' => (array_count_values(array_column((array)$run->getLog(), 'status'))['failed'] ?? 0),
		];
	}//end runSummary()

	/**
	 * A date as ISO 8601, or null.
	 *
	 * @param mixed $value A date, or anything else.
	 *
	 * @return string|null The formatted date.
	 */
	private static function atom(mixed $value): ?string {
		if ($value instanceof DateTimeInterface) {
			return $value->format(DATE_ATOM);
		}

		return null;
	}//end atom()

	/**
	 * Who the flow runs as and when, from its last schedule trigger node.
	 *
	 * @param array $nodes The flow's nodes.
	 *
	 * @return array{runAs: mixed, cron: mixed}
	 *
	 * @spec openspec/changes/publiccode-github-harvest/specs/publiccode-github-harvest/spec.md
	 */
	public function schedule(array $nodes): array {
		$schedule = ['runAs' => null, 'cron' => null];
		foreach ($nodes as $node) {
			if (($node['type'] ?? '') === 'openregister.trigger-schedule') {
				$schedule = ['runAs' => ($node['config']['runAs'] ?? null), 'cron' => ($node['config']['cron'] ?? null)];
			}
		}

		return $schedule;
	}//end schedule()
}//end class
