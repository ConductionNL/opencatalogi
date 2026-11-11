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
		$tally = ['obligations' => [], 'unread' => [], 'read' => 0, 'registered' => 0, 'now' => $this->momentOf(now: $now)];
		foreach ($this->enabledAnswers(sources: $sources, obligationsBySource: $obligationsBySource) as $answered) {
			$tally = $this->readSource(tally: $tally, answered: $answered);
		}

		return $this->summarise(tally: $tally);

	}//end assemble()

	/**
	 * The moment the overview is judged at: the given one, or now in UTC.
	 *
	 * @param DateTimeInterface|null $now The moment, or null for now.
	 *
	 * @return DateTimeImmutable The moment.
	 */
	private function momentOf(?DateTimeInterface $now): DateTimeImmutable {
		$moment = new DateTimeImmutable('now', new DateTimeZone('UTC'));
		if ($now !== null) {
			$moment = new DateTimeImmutable($now->format('Y-m-d\\TH:i:s.uP'));
		}

		return $moment;

	}//end momentOf()

	/**
	 * Each enabled source paired with what it answered, in source order.
	 *
	 * @param array<int, array<string, mixed>> $sources The registered sources.
	 * @param array<string, mixed> $obligationsBySource What each source answered, by app id.
	 *
	 * @return array<int, array{appId: string, answer: mixed}>
	 */
	private function enabledAnswers(array $sources, array $obligationsBySource): array {
		$answers = [];
		foreach ($sources as $source) {
			if ((bool)($source['enabled'] ?? true) === true) {
				$appId = (string)($source['appId'] ?? '');
				$answers[] = ['appId' => $appId, 'answer' => ($obligationsBySource[$appId] ?? null)];
			}
		}

		return $answers;

	}//end enabledAnswers()

	/**
	 * The overview's counts and lists from the finished tally.
	 *
	 * @param array{obligations: array, unread: array, read: int, registered: int, now: DateTimeInterface} $tally The tally.
	 *
	 * @return array<string, mixed> The overview, shaped as assemble() documents.
	 */
	private function summarise(array $tally): array {
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

	}//end summarise()

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
		$unreadReason = $this->unreadReason(answer: $answered['answer']);
		if ($unreadReason !== null) {
			$tally['unread'][] = ['appId' => $answered['appId'], 'reason' => $unreadReason];
			return $tally;
		}

		$tally['read']++;
		$tally['obligations'] = array_merge(
			$tally['obligations'],
			$this->statedObligations(answered: $answered, now: $tally['now'])
		);

		return $tally;
	}//end readSource()

	/**
	 * A read source's obligations, each stamped with its source and its state.
	 *
	 * @param array{appId: string, answer: array} $answered The source and the list it answered.
	 * @param DateTimeInterface $now The moment.
	 *
	 * @return array<int, array<string, mixed>> The obligations, in the order answered.
	 */
	private function statedObligations(array $answered, DateTimeInterface $now): array {
		$obligations = [];
		foreach (array_filter($answered['answer'], 'is_array') as $obligation) {
			$obligation['source'] = $answered['appId'];
			$obligation['state'] = $this->stateOf(obligation: $obligation, now: $now);
			$obligations[] = $obligation;
		}

		return $obligations;

	}//end statedObligations()

	/**
	 * Why a source's answer cannot be read: its failure, or that it was never asked.
	 *
	 * @param mixed $answer What the source answered.
	 *
	 * @return string|null The reason, or null when the answer is a list to read.
	 */
	private function unreadReason(mixed $answer): ?string {
		if ($answer instanceof \Throwable) {
			return $answer->getMessage();
		}

		if (is_array($answer) === false) {
			return 'This source was never asked.';
		}

		return null;

	}//end unreadReason()

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

		$dueDate = $this->dueDateOf(obligation: $obligation);
		if ($dueDate === null) {
			return 'unknown';
		}

		if ($dueDate < new DateTimeImmutable($now->format('Y-m-d\\TH:i:s.uP'))) {
			return 'late';
		}

		return 'due';
	}//end stateOf()

	/**
	 * An obligation's due date, or null when it has none or it cannot be read.
	 *
	 * @param array<string, mixed> $obligation The obligation.
	 *
	 * @return DateTimeImmutable|null The due date.
	 */
	private function dueDateOf(array $obligation): ?DateTimeImmutable {
		$due = trim((string)($obligation['dueDate'] ?? ''));
		if ($due === '') {
			return null;
		}

		try {
			return new DateTimeImmutable($due);
		} catch (\Throwable $e) {
			return null;
		}

	}//end dueDateOf()
}//end class
