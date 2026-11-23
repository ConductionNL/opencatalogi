<?php

/**
 * OpenCatalogi Depublication Service.
 *
 * One action takes a publication down, records who did it and why, and sends a
 * withdrawal to every channel it reached, including the national ones. The
 * record of the publication stays.
 *
 * Publishing a name by mistake needs an undo, and an undo that leaves the
 * document in a harvester's copy is not one. So a withdrawal a channel has not
 * acknowledged is shown as outstanding, and a channel that could not be
 * reached is recorded as not reached, never as acknowledged.
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
 * @spec openspec/specs/publication-inspection-and-the-national-indexes/spec.md#requirement-something-published-in-error-is-depublished-with-one-action-req-pin-106
 */

declare(strict_types=1);

namespace OCA\OpenCatalogi\Service\Publication;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use DomainException;
use OCA\OpenCatalogi\Contract\DepublicationServiceInterface;
use Psr\Log\LoggerInterface;

/**
 * Takes a publication back from every channel it reached.
 *
 * @spec openspec/specs/publication-inspection-and-the-national-indexes/spec.md#requirement-something-published-in-error-is-depublished-with-one-action-req-pin-106
 */
class DepublicationService implements DepublicationServiceInterface {

	/**
	 * Constructor.
	 *
	 * @param NationalIndexService $indexService The gateway-backed channel deliverer.
	 * @param LoggerInterface $logger Logger.
	 * @param PublicationFreezer|null $freezer Freezes a withdrawn publication; without one nothing is frozen.
	 */
	public function __construct(
		private readonly NationalIndexService $indexService,
		private readonly LoggerInterface $logger,
		private readonly ?PublicationFreezer $freezer = null,
	) {

	}//end __construct()

	/**
	 * Depublish in one action and withdraw from every channel it reached.
	 *
	 * @param array<string, mixed> $publication The publication.
	 * @param string $reason Why it is coming down.
	 * @param string $depublishedBy Who is taking it down.
	 * @param array<int, string> $channels The channels the publication reached.
	 * @param DateTimeInterface|null $now The moment; defaults to now.
	 *
	 * @return array<string, mixed> The depublication to save, with `frozen` when a freezer is wired.
	 *
	 * @throws DomainException When no reason is given.
	 *
	 * @spec openspec/specs/publication-inspection-and-the-national-indexes/spec.md#requirement-something-published-in-error-is-depublished-with-one-action-req-pin-106
	 * @spec openspec/changes/publication-withdrawal-aftercare/tasks.md#task-3.2
	 */
	public function depublish(
		array $publication,
		string $reason,
		string $depublishedBy,
		array $channels,
		?DateTimeInterface $now = null,
	): array {
		$run = $this->run(publication: $publication, reason: $reason, depublishedBy: $depublishedBy, now: $now);

		$depublication = $this->depublication(run: $run, withdrawals: $this->withdrawals(run: $run, channels: $channels));
		if ($this->freezer !== null) {
			// After the withdrawals: a failed freeze never holds the withdrawal
			// back, it is reported beside it (REQ-PWA-003).
			$depublication += $this->freezer->freeze(publicationId: $run->publicationId, reason: $run->reason);
		}

		return $depublication;

	}//end depublish()

	/**
	 * Record one document of a publication coming down. Nothing is sent to a
	 * channel and the publication itself is not frozen: the rest of it stays
	 * public and editable.
	 *
	 * @param array<string, mixed> $publication The publication.
	 * @param string $reason Why the document is coming down.
	 * @param string $depublishedBy Who is taking it down.
	 *
	 * @return array<string, mixed> The depublication to save.
	 *
	 * @throws DomainException When no reason is given.
	 *
	 * @spec openspec/changes/publication-withdrawal-aftercare/tasks.md#task-3.2
	 */
	public function depublishDocument(array $publication, string $reason, string $depublishedBy): array {
		$run = $this->run(publication: $publication, reason: $reason, depublishedBy: $depublishedBy, now: null);

		return $this->depublication(run: $run, withdrawals: []);
	}//end depublishDocument()

	/**
	 * The depublication run, after refusing one without a reason.
	 *
	 * @param array<string, mixed> $publication The publication.
	 * @param string $reason Why it is coming down.
	 * @param string $depublishedBy Who is taking it down.
	 * @param DateTimeInterface|null $now The moment; defaults to now.
	 *
	 * @return DepublicationRun The run.
	 *
	 * @throws DomainException When no reason is given.
	 */
	private function run(array $publication, string $reason, string $depublishedBy, ?DateTimeInterface $now): DepublicationRun {
		$this->requireReason(reason: $reason);

		$run = new DepublicationRun();
		$run->publicationId = (string)($publication['id'] ?? '');
		$run->reason = $reason;
		$run->depublishedBy = $depublishedBy;
		$run->takenDownAt = $this->takenDownAt(now: $now);

		return $run;
	}//end run()

	/**
	 * Refuse a depublication without a reason.
	 *
	 * @param string $reason Why it is coming down.
	 *
	 * @return void
	 *
	 * @throws DomainException When no reason is given.
	 */
	private function requireReason(string $reason): void {
		if (trim($reason) === '') {
			throw new DomainException(
				message: 'A depublication records why. Taking something down without a reason leaves nobody able to answer for it.'
			);
		}
	}//end requireReason()

	/**
	 * Withdraw the publication from each channel it reached, once each.
	 *
	 * @param DepublicationRun $run The depublication.
	 * @param array<int, string> $channels The channels the publication reached.
	 *
	 * @return array<int, array<string, mixed>> The withdrawals.
	 */
	private function withdrawals(DepublicationRun $run, array $channels): array {
		$withdrawals = [];
		foreach (array_values(array_unique(array_map('strval', $channels))) as $channel) {
			$withdrawals[] = $this->withdrawal(run: $run, channel: $channel);
		}

		return $withdrawals;
	}//end withdrawals()

	/**
	 * The depublication to save.
	 *
	 * @param DepublicationRun $run The depublication.
	 * @param array<int, array<string, mixed>> $withdrawals Its withdrawals.
	 *
	 * @return array<string, mixed> The depublication.
	 */
	private function depublication(DepublicationRun $run, array $withdrawals): array {
		return [
			'publication' => $run->publicationId,
			'reason' => $run->reason,
			'depublishedBy' => $run->depublishedBy,
			'depublishedAt' => $run->takenDownAt,
			'withdrawals' => $withdrawals,
		];
	}//end depublication()

	/**
	 * The moment of the depublication, ISO 8601: the given one, or now in UTC.
	 *
	 * @param DateTimeInterface|null $now The moment.
	 *
	 * @return string The moment.
	 */
	private function takenDownAt(?DateTimeInterface $now): string {
		$moment = new DateTimeImmutable('now', new DateTimeZone('UTC'));
		if ($now !== null) {
			$moment = new DateTimeImmutable($now->format('Y-m-d\\TH:i:s.uP'));
		}

		return $moment->format(DateTimeInterface::ATOM);
	}//end takenDownAt()

	/**
	 * Withdraw the publication from one channel, and record what it answered.
	 *
	 * @param DepublicationRun $run The depublication.
	 * @param string $channel The channel.
	 *
	 * @return array{channel: string, sentAt: string, acknowledgedAt: mixed, answer: mixed} The withdrawal.
	 */
	private function withdrawal(DepublicationRun $run, string $channel): array {
		$withdrawal = [
			'channel' => $channel,
			'sentAt' => $run->takenDownAt,
			'acknowledgedAt' => null,
			'answer' => null,
		];

		try {
			$answer = $this->indexService->withdraw(
				channel: $channel,
				publicationId: $run->publicationId,
				reason: $run->reason
			);
			$withdrawal['acknowledgedAt'] = ($answer['acknowledgedAt'] ?? null);
			$withdrawal['answer'] = ($answer['answer'] ?? null);
		} catch (IndexUnreachableException $e) {
			// Not acknowledged, and said so. The one thing this must never
			// do is record an unreached channel as done, because the
			// operator would then believe the document is gone from a
			// harvester that still holds it.
			$this->logger->warning(
				'[DepublicationService] The withdrawal to "' . $channel . '" was not delivered: ' . $e->getMessage()
			);
			$withdrawal['answer'] = 'not delivered: ' . $e->getMessage();
		}

		return $withdrawal;
	}//end withdrawal()

	/**
	 * The channels whose withdrawal is still outstanding.
	 *
	 * @param array<string, mixed> $depublication The depublication.
	 *
	 * @return array<int, string> The channels that have not acknowledged.
	 *
	 * @spec openspec/specs/publication-inspection-and-the-national-indexes/spec.md#requirement-something-published-in-error-is-depublished-with-one-action-req-pin-106
	 */
	public function outstandingChannels(array $depublication): array {
		$outstanding = [];
		foreach (($depublication['withdrawals'] ?? []) as $withdrawal) {
			if (is_array($withdrawal) === false) {
				continue;
			}

			if (trim((string)($withdrawal['acknowledgedAt'] ?? '')) === '') {
				$outstanding[] = (string)($withdrawal['channel'] ?? '');
			}
		}

		return array_values(array_filter($outstanding, static fn (string $channel): bool => $channel !== ''));
	}//end outstandingChannels()

	/**
	 * Whether the depublication is done everywhere.
	 *
	 * @param array<string, mixed> $depublication The depublication.
	 *
	 * @return boolean True only when every channel acknowledged.
	 *
	 * @spec openspec/specs/publication-inspection-and-the-national-indexes/spec.md#requirement-something-published-in-error-is-depublished-with-one-action-req-pin-106
	 */
	public function isComplete(array $depublication): bool {
		return ($this->outstandingChannels(depublication: $depublication) === []);
	}//end isComplete()

	/**
	 * Record a channel's late acknowledgement.
	 *
	 * @param array<string, mixed> $depublication The depublication.
	 * @param string $channel The channel that answered.
	 * @param string $answer What it said.
	 * @param DateTimeInterface|null $now The moment; defaults to now.
	 *
	 * @return array<string, mixed> The depublication.
	 *
	 * @spec openspec/specs/publication-inspection-and-the-national-indexes/spec.md#requirement-something-published-in-error-is-depublished-with-one-action-req-pin-106
	 */
	public function recordAcknowledgement(
		array $depublication,
		string $channel,
		string $answer,
		?DateTimeInterface $now = null,
	): array {
		$moment = new DateTimeImmutable('now', new DateTimeZone('UTC'));
		if ($now !== null) {
			$moment = new DateTimeImmutable($now->format('Y-m-d\\TH:i:s.uP'));
		}

		foreach (($depublication['withdrawals'] ?? []) as $index => $withdrawal) {
			if ((string)($withdrawal['channel'] ?? '') !== $channel) {
				continue;
			}

			$depublication['withdrawals'][$index]['acknowledgedAt'] = $moment->format(DateTimeInterface::ATOM);
			$depublication['withdrawals'][$index]['answer'] = $answer;
			break;
		}

		return $depublication;
	}//end recordAcknowledgement()
}//end class
