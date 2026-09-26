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
 * @spec openspec/changes/publication-inspection-and-the-national-indexes/specs/publication-inspection-and-the-national-indexes/spec.md#requirement-something-published-in-error-is-depublished-with-one-action-req-pin-106
 */

declare(strict_types=1);

namespace OCA\OpenCatalogi\Service\Publication;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use DomainException;
use Psr\Log\LoggerInterface;

/**
 * Takes a publication back from every channel it reached.
 *
 * @spec openspec/changes/publication-inspection-and-the-national-indexes/specs/publication-inspection-and-the-national-indexes/spec.md#requirement-something-published-in-error-is-depublished-with-one-action-req-pin-106
 */
class DepublicationService {

	/**
	 * Constructor.
	 *
	 * @param NationalIndexService $indexService The gateway-backed channel deliverer.
	 * @param LoggerInterface $logger Logger.
	 */
	public function __construct(
		private readonly NationalIndexService $indexService,
		private readonly LoggerInterface $logger,
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
	 * @return array<string, mixed> The depublication to save.
	 *
	 * @throws DomainException When no reason is given.
	 *
	 * @spec openspec/changes/publication-inspection-and-the-national-indexes/specs/publication-inspection-and-the-national-indexes/spec.md#requirement-something-published-in-error-is-depublished-with-one-action-req-pin-106
	 */
	public function depublish(
		array $publication,
		string $reason,
		string $depublishedBy,
		array $channels,
		?DateTimeInterface $now = null,
	): array {
		if (trim($reason) === '') {
			throw new DomainException(
				message: 'A depublication records why. Taking something down without a reason leaves nobody able to answer for it.'
			);
		}

		$moment = new DateTimeImmutable('now', new DateTimeZone('UTC'));
		if ($now !== null) {
			$moment = new DateTimeImmutable($now->format('Y-m-d\\TH:i:s.uP'));
		}
		$takenDownAt = $moment->format(DateTimeInterface::ATOM);

		$publicationId = (string)($publication['id'] ?? '');
		$withdrawals = [];

		foreach (array_values(array_unique(array_map('strval', $channels))) as $channel) {
			$withdrawal = [
				'channel' => $channel,
				'sentAt' => $takenDownAt,
				'acknowledgedAt' => null,
				'answer' => null,
			];

			try {
				$answer = $this->indexService->withdraw(
					channel: $channel,
					publicationId: $publicationId,
					reason: $reason
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

			$withdrawals[] = $withdrawal;
		}

		return [
			'publication' => $publicationId,
			'reason' => $reason,
			'depublishedBy' => $depublishedBy,
			'depublishedAt' => $takenDownAt,
			'withdrawals' => $withdrawals,
		];

	}//end depublish()

	/**
	 * The channels whose withdrawal is still outstanding.
	 *
	 * @param array<string, mixed> $depublication The depublication.
	 *
	 * @return array<int, string> The channels that have not acknowledged.
	 *
	 * @spec openspec/changes/publication-inspection-and-the-national-indexes/specs/publication-inspection-and-the-national-indexes/spec.md#requirement-something-published-in-error-is-depublished-with-one-action-req-pin-106
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
	 * @spec openspec/changes/publication-inspection-and-the-national-indexes/specs/publication-inspection-and-the-national-indexes/spec.md#requirement-something-published-in-error-is-depublished-with-one-action-req-pin-106
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
	 * @spec openspec/changes/publication-inspection-and-the-national-indexes/specs/publication-inspection-and-the-national-indexes/spec.md#requirement-something-published-in-error-is-depublished-with-one-action-req-pin-106
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
