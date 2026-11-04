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

		$tally = ['obligations' => [], 'unread' => [], 'read' => 0, 'registered' => 0, 'now' => $moment];
		foreach ($sources as $source) {
			if ((bool)($source['enabled'] ?? true) === true) {
				$appId = (string)($source['appId'] ?? '');
				$tally = $this->readSource(tally: $tally, answered: ['appId' => $appId, 'answer' => ($obligationsBySource[$appId] ?? null)]);
			}
		}

		$obligations = $tally['obligations'];
		$published = count(array_filter($obligations, static fn (array $row): bool => $row['state'] === 'published'));

		return [
			'total' => count($obligations),
			'published' => $published,
			'late' => count(array_filter($obligations, static fn (array $row): bool => $row['state'] === 'late')),
			'outstanding' => (count($obligations) - $published),
			'unreadSources' => $tally['unread'],
			'obligations' => $obligations,
			'sourcesRead' => $tally['read'],
			'sourcesRegistered' => $tally['registered'],
		];

	}//end assemble()

	/**
	 * The tally with one registered source read: its obligations with their state, or
	 * the source listed as unread when it failed or was never asked.
	 *
	 * @param array{obligations: array, unread: array, read: int, registered: int, now: DateTimeInterface} $tally The tally so far.
	 * @param array{appId: string, answer: mixed} $answered The source and what it answered.
	 *
	 * @return array{obligations: array, unread: array, read: int, registered: int, now: DateTimeInterface}
	 *
	 * @spec openspec/changes/publication-inspection-and-the-national-indexes/specs/publication-inspection-and-the-national-indexes/spec.md#requirement-one-overview-of-what-must-be-published-fed-from-every-source-req-pin-110
	 */
	private function readSource(array $tally, array $answered): array {
		$tally['registered']++;
		$answer = $answered['answer'];
		if ($answer instanceof \Throwable) {
			$tally['unread'][] = ['appId' => $answered['appId'], 'reason' => $answer->getMessage()];
			return $tally;
		}

		if (is_array($answer) === false) {
			$tally['unread'][] = ['appId' => $answered['appId'], 'reason' => 'This source was never asked.'];
			return $tally;
		}

		$tally['read']++;
		foreach (array_filter($answer, 'is_array') as $obligation) {
			$obligation['source'] = $answered['appId'];
			$obligation['state'] = $this->stateOf(obligation: $obligation, now: $tally['now']);
			$tally['obligations'][] = $obligation;
		}

		return $tally;
	}//end readSource()

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
