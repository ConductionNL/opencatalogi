<?php

/**
 * OpenCatalogi Obligation Overview Service.
 *
 * The publication obligation is on the organisation, not on one case system,
 * so the overview reads from every application that registers as a source and
 * from other case systems over the harvest intake. Publishing only what one
 * product holds is publishing part of it.
 *
 * A source that could not be read is named as unread, and never counted as a
 * source with no obligations. An overview that quietly drops a source it could
 * not reach reports an organisation as compliant that is not.
 *
 * @category Service
 * @package  OCA\OpenCatalogi\Service\Publication
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
 * @spec openspec/changes/publication-inspection-and-the-national-indexes/specs/publication-inspection-and-the-national-indexes/spec.md#requirement-one-overview-of-what-must-be-published-fed-from-every-source-req-pin-110
 */

declare(strict_types=1);

namespace OCA\OpenCatalogi\Service\Publication;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;

/**
 * What must be published, what is, and what is late, across every source.
 *
 * @spec openspec/changes/publication-inspection-and-the-national-indexes/specs/publication-inspection-and-the-national-indexes/spec.md#requirement-one-overview-of-what-must-be-published-fed-from-every-source-req-pin-110
 */
class ObligationOverviewService {

	/**
	 * Assemble the overview from what every source returned.
	 *
	 * `$obligationsBySource` maps a source's app id to either a list of
	 * obligations or an Exception, which is how a source that could not be read
	 * stays visible instead of disappearing into a zero.
	 *
	 * @param array<int, array<string, mixed>> $sources The registered sources.
	 * @param array<string, array<int, array<string, mixed>>|\Throwable> $obligationsBySource What each source gave back.
	 * @param DateTimeInterface|null $now The moment; defaults to now.
	 *
	 * @return array{
	 *     total: integer,
	 *     published: integer,
	 *     late: integer,
	 *     outstanding: integer,
	 *     unreadSources: array<int, array<string, string>>,
	 *     obligations: array<int, array<string, mixed>>,
	 *     sourcesRead: integer,
	 *     sourcesRegistered: integer
	 * }
	 *
	 * @spec openspec/changes/publication-inspection-and-the-national-indexes/specs/publication-inspection-and-the-national-indexes/spec.md#requirement-one-overview-of-what-must-be-published-fed-from-every-source-req-pin-110
	 */
	public function assemble(array $sources, array $obligationsBySource, ?DateTimeInterface $now = null): array {
		$moment = new DateTimeImmutable('now', new DateTimeZone('UTC'));
		if ($now !== null) {
			$moment = new DateTimeImmutable($now->format('Y-m-d\\TH:i:s.uP'));
		}

		$obligations = [];
		$unread = [];
		$read = 0;
		$registered = 0;

		foreach ($sources as $source) {
			if ((bool)($source['enabled'] ?? true) === false) {
				continue;
			}

			$registered++;
			$appId = (string)($source['appId'] ?? '');
			$answer = ($obligationsBySource[$appId] ?? null);

			if ($answer instanceof \Throwable) {
				$unread[] = ['appId' => $appId, 'reason' => $answer->getMessage()];
				continue;
			}

			if (is_array($answer) === false) {
				$unread[] = ['appId' => $appId, 'reason' => 'This source was never asked.'];
				continue;
			}

			$read++;
			foreach ($answer as $obligation) {
				if (is_array($obligation) === false) {
					continue;
				}

				$obligation['source'] = $appId;
				$obligation['state'] = $this->stateOf(obligation: $obligation, now: $moment);
				$obligations[] = $obligation;
			}
		}

		$published = count(array_filter($obligations, static fn (array $row): bool => $row['state'] === 'published'));
		$late = count(array_filter($obligations, static fn (array $row): bool => $row['state'] === 'late'));

		return [
			'total' => count($obligations),
			'published' => $published,
			'late' => $late,
			'outstanding' => (count($obligations) - $published),
			'unreadSources' => $unread,
			'obligations' => $obligations,
			'sourcesRead' => $read,
			'sourcesRegistered' => $registered,
		];

	}//end assemble()

	/**
	 * Where one obligation stands.
	 *
	 * An obligation whose due date cannot be read is `unknown`, not `published`
	 * and not `due`: a date nobody can read is not evidence of compliance.
	 *
	 * @param array<string, mixed> $obligation The obligation.
	 * @param DateTimeInterface $now The moment.
	 *
	 * @return string One of published, late, due, unknown.
	 *
	 * @spec openspec/changes/publication-inspection-and-the-national-indexes/specs/publication-inspection-and-the-national-indexes/spec.md#requirement-one-overview-of-what-must-be-published-fed-from-every-source-req-pin-110
	 */
	public function stateOf(array $obligation, DateTimeInterface $now): string {
		if (trim((string)($obligation['publishedAt'] ?? '')) !== '') {
			return 'published';
		}

		$due = trim((string)($obligation['dueDate'] ?? ''));
		if ($due === '') {
			return 'unknown';
		}

		try {
			$dueDate = new DateTimeImmutable($due);
		} catch (\Throwable $e) {
			return 'unknown';
		}

		if ($dueDate < new DateTimeImmutable($now->format('Y-m-d\\TH:i:s.uP'))) {
			return 'late';
		}

		return 'due';

	}//end stateOf()
}//end class
