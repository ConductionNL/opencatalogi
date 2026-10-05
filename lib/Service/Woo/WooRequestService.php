<?php

/**
 * OpenCatalogi Woo request service.
 *
 * The stack used to assume a Woo request already existed somewhere else: a batch
 * carried a case reference, and that reference was a foreign key to a number
 * nothing here minted. A statutory term had nothing to hang on, so six statutory
 * rows failed for want of a subject rather than for want of an engine.
 *
 * This class mints the request. It is deliberately pure: it builds and reads the
 * record, and the controller persists it, exactly as InspectionService does.
 * Nothing here computes a deadline; the term engine does that.
 *
 * @category Service
 * @package  OCA\OpenCatalogi\Service\Woo
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
 * @spec openspec/specs/woo-request-intake/spec.md#requirement-a-woo-request-is-a-record-of-its-own-req-wri-001
 */

declare(strict_types=1);

namespace OCA\OpenCatalogi\Service\Woo;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use DomainException;

/**
 * Builds and reads the Woo request record.
 *
 * @spec openspec/specs/woo-request-intake/spec.md#requirement-a-woo-request-is-a-record-of-its-own-req-wri-001
 */
class WooRequestService {

	/**
	 * The states a request moves through.
	 *
	 * @var array<int, string>
	 */
	public const STATUSES = ['received', 'in_progress', 'awaiting_clarification', 'decided', 'withdrawn'];

	/**
	 * The channels a request can arrive over.
	 *
	 * @var array<int, string>
	 */
	public const CHANNELS = ['web', 'email', 'post', 'counter', 'phone'];

	/**
	 * Build the record for a request that just arrived.
	 *
	 * The reference is minted here rather than taken from the caller: a caller
	 * that supplies its own reference can supply one that is already in use, and
	 * the reference is what the requester quotes.
	 *
	 * @param array<string, mixed> $input What arrived: the requested information, the requester, the channel.
	 * @param string $receivedBy Who registered it, or the channel that did.
	 * @param DateTimeInterface|null $now When it arrived; defaults to now.
	 *
	 * @return array<string, mixed> The record to save.
	 *
	 * @throws DomainException When nothing was asked for, or the channel is unknown.
	 *
	 * @spec openspec/specs/woo-request-intake/spec.md#requirement-a-woo-request-is-a-record-of-its-own-req-wri-001
	 */
	public function receive(array $input, string $receivedBy, ?DateTimeInterface $now = null): array {
		$asked = trim((string)($input['requestedInformation'] ?? ''));
		if ($asked === '') {
			throw new DomainException(
				message: 'A Woo request has to say what information is asked for. A request for nothing cannot be decided, '
					. 'and a term counted against it would be counted against nothing.'
			);
		}

		$channel = trim((string)($input['channel'] ?? 'web'));
		if (in_array($channel, self::CHANNELS, true) === false) {
			throw new DomainException(
				message: 'The channel "' . $channel . '" is not one of ' . implode(', ', self::CHANNELS)
					. '. An unknown channel is refused rather than read as the nearest one, because the channel is '
					. 'what decides how the decision goes back out.'
			);
		}

		$receivedAt = new DateTimeImmutable('now', new DateTimeZone('UTC'));
		if ($now !== null) {
			$receivedAt = new DateTimeImmutable($now->format('Y-m-d\\TH:i:s.uP'));
		}

		return [
			'reference' => $this->mintReference(now: $receivedAt),
			'receivedAt' => $receivedAt->format(DateTimeInterface::ATOM),
			'requestedInformation' => $asked,
			'requesterName' => trim((string)($input['requesterName'] ?? '')),
			'requesterEmail' => trim((string)($input['requesterEmail'] ?? '')),
			'requesterPhone' => trim((string)($input['requesterPhone'] ?? '')),
			'requesterAddress' => trim((string)($input['requesterAddress'] ?? '')),
			'channel' => $channel,
			'status' => 'received',
			// `dueAt` and `decidedAt` are deliberately ABSENT rather than null.
			// Both are `format: date-time`, and a null written against a
			// date-time is the shape that passes a unit test and is refused by
			// the register the first time it runs live.
			'termTimer' => '',
			'termBasis' => StatutoryTerm::BASIS_TERM,
			'extensionCount' => 0,
			'extensionReason' => '',
			'batch' => '',
			'receivedBy' => $receivedBy,
		];

	}//end receive()

	/**
	 * Mint a reference the requester can quote.
	 *
	 * @param DateTimeInterface $now The moment, which gives the year.
	 *
	 * @return string The reference.
	 *
	 * @spec openspec/specs/woo-request-intake/spec.md#requirement-a-woo-request-is-a-record-of-its-own-req-wri-001
	 */
	public function mintReference(DateTimeInterface $now): string {
		// Six hex characters, upper case, from a cryptographic source. Short
		// enough to read out over the phone and not a counter, because a counter
		// tells a requester how many other people asked.
		return 'WOO-' . $now->format('Y') . '-' . strtoupper(bin2hex(random_bytes(3)));

	}//end mintReference()

	/**
	 * Record the armed term on the request.
	 *
	 * @param array<string, mixed> $request The request.
	 * @param array<string, mixed> $term The term, as StatutoryTerm reports it.
	 *
	 * @return array<string, mixed> The request, with its term.
	 *
	 * @spec openspec/specs/woo-request-intake/spec.md#requirement-a-statutory-term-is-armed-when-a-request-is-received-req-wri-002
	 */
	public function withTerm(array $request, array $term): array {
		$request['termTimer'] = (string)($term['timer'] ?? '');
		// Set only when the engine gave one. A suspended term has no fire
		// moment at all, and writing null into a `date-time` property is how a
		// unit-green write gets refused live.
		unset($request['dueAt']);
		$dueAt = ($term['dueAt'] ?? null);
		if (is_string($dueAt) === true && trim($dueAt) !== '') {
			$request['dueAt'] = $dueAt;
		}

		$request['extensionCount'] = (int)($term['extensionCount'] ?? 0);
		$request['termBasis'] = (string)($term['basis'] ?? StatutoryTerm::BASIS_TERM);

		return $request;

	}//end withTerm()

	/**
	 * What the requester is told at intake: the reference and the clock.
	 *
	 * A receipt that leaves the date out is the cheapest way to fail the duty to
	 * tell a requester when their answer is owed, so the date is not optional
	 * here: a request whose term was not armed says so rather than leaving the
	 * field quietly empty.
	 *
	 * @param array<string, mixed> $request The request.
	 *
	 * @return array<string, mixed> The receipt.
	 *
	 * @spec openspec/specs/woo-request-intake/spec.md#requirement-the-requester-is-told-the-reference-and-the-due-date-at-intake-req-wri-005
	 */
	public function receipt(array $request): array {
		$dueAt = ($request['dueAt'] ?? null);
		if (is_string($dueAt) === true && trim($dueAt) === '') {
			$dueAt = null;
		}

		return [
			'reference' => (string)($request['reference'] ?? ''),
			'receivedAt' => ($request['receivedAt'] ?? null),
			'dueAt' => $dueAt,
			'termDays' => StatutoryTerm::TERM_DAYS,
			'termBasis' => (string)($request['termBasis'] ?? StatutoryTerm::BASIS_TERM),
			'extensionDays' => StatutoryTerm::EXTENSION_DAYS,
			'extensionBasis' => StatutoryTerm::BASIS_EXTENSION,
			'termArmed' => ($dueAt !== null),
		];

	}//end receipt()

	/**
	 * Attach the disclosure batch a request produced.
	 *
	 * The link is kept on the request, never on the batch: a batch has been in
	 * production at twelve organisations without one, and it has to keep working
	 * without one.
	 *
	 * @param array<string, mixed> $request The request.
	 * @param string $batchUuid The batch it produced.
	 *
	 * @return array<string, mixed> The request, with its batch.
	 *
	 * @throws DomainException When no batch was named.
	 *
	 * @spec openspec/specs/woo-request-intake/spec.md#requirement-a-request-may-produce-a-batch-and-a-batch-works-without-a-request-req-wri-007
	 */
	public function attachBatch(array $request, string $batchUuid): array {
		$batch = trim($batchUuid);
		if ($batch === '') {
			throw new DomainException(message: 'Attaching a batch to a request needs the batch.');
		}

		$request['batch'] = $batch;
		if ((string)($request['status'] ?? '') === 'received') {
			$request['status'] = 'in_progress';
		}

		return $request;

	}//end attachBatch()

	/**
	 * Terms met and missed, over the requests given.
	 *
	 * A term is MET when the decision went out on or before the due date, MISSED
	 * when it went out after it or when it is already overdue and undecided, and
	 * otherwise it is still running or suspended. An undecided request whose due
	 * date has not passed is not counted as met: counting it would report a
	 * compliance figure that can only get worse.
	 *
	 * @param array<int, array<string, mixed>> $requests The requests, each with `dueAt` and `decidedAt`.
	 * @param DateTimeInterface|null $now The moment; defaults to now.
	 *
	 * @return array<string, mixed> The report.
	 *
	 * @spec openspec/specs/woo-request-intake/spec.md#requirement-terms-met-and-missed-are-reported-req-wri-006
	 */
	public function termsReport(array $requests, ?DateTimeInterface $now = null): array {
		$moment = new DateTimeImmutable('now', new DateTimeZone('UTC'));
		if ($now !== null) {
			$moment = new DateTimeImmutable($now->format('Y-m-d\\TH:i:s.uP'));
		}

		$counts = ['met' => 0, 'missed' => 0, 'running' => 0, 'suspended' => 0, 'unknown' => 0];
		$rows = [];
		foreach ($requests as $request) {
			$outcome = $this->outcome(request: $request, now: $moment);
			$counts[$outcome]++;
			$rows[] = [
				'reference' => (string)($request['reference'] ?? ''),
				'status' => (string)($request['status'] ?? ''),
				'dueAt' => ($request['dueAt'] ?? null),
				'decidedAt' => ($request['decidedAt'] ?? null),
				'extensionCount' => (int)($request['extensionCount'] ?? 0),
				'outcome' => $outcome,
			];
		}

		$decided = ($counts['met'] + $counts['missed']);
		$metShare = null;
		if ($decided > 0) {
			$metShare = round(($counts['met'] / $decided), 4);
		}

		return [
			'total' => count($requests),
			'counts' => $counts,
			// Null rather than 100% when nothing is decided yet. A share of
			// nothing read as perfect compliance is the kind of number somebody
			// puts in a council answer.
			'metShare' => $metShare,
			'requests' => $rows,
		];

	}//end termsReport()

	/**
	 * The outcome of one request's term.
	 *
	 * @param array<string, mixed> $request The request.
	 * @param DateTimeImmutable $now The moment.
	 *
	 * @return string One of met, missed, running, suspended, unknown.
	 *
	 * @spec openspec/specs/woo-request-intake/spec.md#requirement-terms-met-and-missed-are-reported-req-wri-006
	 */
	private function outcome(array $request, DateTimeImmutable $now): string {
		$due = $this->moment(value: ($request['dueAt'] ?? null));
		$decided = $this->moment(value: ($request['decidedAt'] ?? null));

		if ((string)($request['status'] ?? '') === 'awaiting_clarification') {
			return 'suspended';
		}

		if ($decided !== null) {
			if ($due === null) {
				// Decided against a term nobody can read is not compliance
				// evidence, and it is not a breach either. It is a gap, named.
				return 'unknown';
			}

			if ($decided <= $due) {
				return 'met';
			}

			return 'missed';
		}

		if ($due === null) {
			return 'unknown';
		}

		if ($due < $now) {
			return 'missed';
		}

		return 'running';

	}//end outcome()

	/**
	 * Read a stored moment, or null when it is absent or unreadable.
	 *
	 * @param mixed $value The stored value.
	 *
	 * @return DateTimeImmutable|null The moment.
	 *
	 * @spec exclude internal helper — reads a stored timestamp.
	 */
	private function moment(mixed $value): ?DateTimeImmutable {
		if (is_string($value) === false || trim($value) === '') {
			return null;
		}

		try {
			return new DateTimeImmutable($value);
		} catch (\Throwable $e) {
			return null;
		}

	}//end moment()
}//end class
