<?php

/**
 * OpenCatalogi Vote Service.
 *
 * A reader who is not staff votes on a published record. Participatie and
 * inspraak are the use, and both are acts where the count is the point.
 *
 * The distribution is readable and an individual vote is not, because a vote on
 * an inspraak item is an opinion attached to a person. The reader is held as a
 * salted hash of their token, so a vote can be counted once without the reader
 * being identifiable from what is stored.
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
 * @spec openspec/changes/the-public-and-community-surface/specs/public-and-community-surface/spec.md#requirement-a-reader-who-is-not-staff-votes-on-a-published-record-req-pcs-106
 */

declare(strict_types=1);

namespace OCA\OpenCatalogi\Service\Community;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use DomainException;

/**
 * Counts votes without making the voters readable.
 *
 * @spec openspec/changes/the-public-and-community-surface/specs/public-and-community-surface/spec.md#requirement-a-reader-who-is-not-staff-votes-on-a-published-record-req-pcs-106
 */
class VoteService {

	/**
	 * Constructor.
	 *
	 * @param string $salt The salt the reader token is hashed with.
	 */
	public function __construct(
		private readonly string $salt = '',
	) {

	}//end __construct()

	/**
	 * The stored form of a reader token.
	 *
	 * @param string $readerToken The reader's token.
	 *
	 * @return string The salted hash.
	 */
	public function readerHash(string $readerToken): string {
		return hash('sha256', $this->salt . '|' . $readerToken);

	}//end readerHash()

	/**
	 * Cast a vote on a record.
	 *
	 * The second vote from the same reader changes nothing, including when it
	 * disagrees with the first.
	 *
	 * @param string $recordId The record.
	 * @param array<int, array<string, mixed>> $existingVotes The votes already cast on it.
	 * @param string $readerToken The reader's token.
	 * @param string $value What they voted.
	 * @param array<int, string> $allowedValues The values this record accepts.
	 * @param DateTimeInterface|null $now The moment; defaults to now.
	 *
	 * @return array{counted: boolean, vote: array<string, mixed>|null}
	 *
	 * @throws DomainException When the value is not one the record accepts.
	 *
	 * @spec openspec/changes/the-public-and-community-surface/specs/public-and-community-surface/spec.md#requirement-a-reader-who-is-not-staff-votes-on-a-published-record-req-pcs-106
	 */
	public function cast(
		string $recordId,
		array $existingVotes,
		string $readerToken,
		string $value,
		array $allowedValues,
		?DateTimeInterface $now = null,
	): array {
		if ($allowedValues !== [] && in_array($value, $allowedValues, true) === false) {
			throw new DomainException(
				message: 'This record does not accept the value "' . $value . '".'
			);
		}

		$hash = $this->readerHash(readerToken: $readerToken);

		foreach ($existingVotes as $vote) {
			if (is_array($vote) === false) {
				continue;
			}

			if ((string)($vote['readerHash'] ?? '') === $hash && (string)($vote['record'] ?? '') === $recordId) {
				return ['counted' => false, 'vote' => null];
			}
		}

		$moment = ($now === null)
			? new DateTimeImmutable('now', new DateTimeZone('UTC'))
			: DateTimeImmutable::createFromInterface($now);

		return [
			'counted' => true,
			'vote' => [
				'record' => $recordId,
				'readerHash' => $hash,
				'value' => $value,
				'castAt' => $moment->format(DateTimeInterface::ATOM),
			],
		];

	}//end cast()

	/**
	 * The distribution an anonymous reader may see.
	 *
	 * What leaves this method is a value and a number, per value, and a total.
	 * No reader hash, no moment, nothing per voter: a reader hash is stable
	 * across records, so publishing the hashes would let anyone correlate one
	 * person's votes across every item they voted on.
	 *
	 * @param array<int, array<string, mixed>> $votes The votes on one record.
	 *
	 * @return array{total: integer, distribution: array<string, integer>}
	 *
	 * @spec openspec/changes/the-public-and-community-surface/specs/public-and-community-surface/spec.md#requirement-a-reader-who-is-not-staff-votes-on-a-published-record-req-pcs-106
	 */
	public function distribution(array $votes): array {
		$counts = [];
		$total = 0;

		foreach ($votes as $vote) {
			if (is_array($vote) === false) {
				continue;
			}

			$value = (string)($vote['value'] ?? '');
			if ($value === '') {
				continue;
			}

			$counts[$value] = ((int)($counts[$value] ?? 0) + 1);
			$total++;
		}

		ksort($counts);

		return [
			'total' => $total,
			'distribution' => $counts,
		];

	}//end distribution()

	/**
	 * Whether a record accepts votes at all.
	 *
	 * A draft accepts none. Voting on something unpublished would let a reader
	 * confirm that an unpublished record exists.
	 *
	 * @param array<string, mixed> $record The record.
	 *
	 * @return boolean True when voting is on and the record is published.
	 *
	 * @spec openspec/changes/the-public-and-community-surface/specs/public-and-community-surface/spec.md#requirement-a-reader-who-is-not-staff-votes-on-a-published-record-req-pcs-106
	 */
	public function acceptsVotes(array $record): bool {
		if ((bool)($record['votingEnabled'] ?? false) === false) {
			return false;
		}

		return ((bool)($record['draft'] ?? false) === false);

	}//end acceptsVotes()
}//end class
