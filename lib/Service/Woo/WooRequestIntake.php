<?php

/**
 * OpenCatalogi Woo request intake from the portal.
 *
 * The portal (portaliq) delivers a citizen's Woo request from a background job, so there is
 * no signed-in user and the `POST /api/woo/requests` endpoint, which needs one,
 * is out of reach. This runs the same steps as that endpoint in process: mint
 * the request, store it, arm its statutory term, and store the term on it.
 *
 * It answers with an outcome instead of throwing, because the caller has to
 * tell a citizen the truth either way. A request that was stored but whose term
 * did not arm is `not-armed`, never `armed` with an empty date.
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
 * @spec openspec/changes/portal-woo-request-intake/specs/woo-request-intake/spec.md#requirement-a-woo-request-delivered-by-the-portal-arms-its-term-req-wri-008
 */

declare(strict_types=1);

namespace OCA\OpenCatalogi\Service\Woo;

use DateTimeImmutable;
use DomainException;
use Throwable;

/**
 * Receives a Woo request handed over by the portal and arms its term.
 *
 * @spec openspec/changes/portal-woo-request-intake/specs/woo-request-intake/spec.md#requirement-a-woo-request-delivered-by-the-portal-arms-its-term-req-wri-008
 */
class WooRequestIntake {

	/**
	 * The request is stored and its term runs; `dueAt` is set.
	 */
	public const OUTCOME_ARMED = 'armed';

	/**
	 * The request is stored, but no term runs.
	 */
	public const OUTCOME_NOT_ARMED = 'not-armed';

	/**
	 * The request was refused, so nothing is stored.
	 */
	public const OUTCOME_REFUSED = 'refused';

	/**
	 * The register could not store the request.
	 */
	public const OUTCOME_UNAVAILABLE = 'unavailable';

	/**
	 * Who the stored request says registered it.
	 */
	private const RECEIVED_BY = 'portaliq';

	/**
	 * The fields of an intake the request record reads.
	 */
	private const FIELDS = ['requestedInformation', 'requesterName', 'requesterEmail', 'requesterPhone', 'requesterAddress'];

	/**
	 * Constructor.
	 *
	 * @param WooRequestService $requests Builds the request record.
	 * @param WooRequestStore $store Stores it.
	 * @param StatutoryTerm $terms Arms its term.
	 */
	public function __construct(
		private readonly WooRequestService $requests,
		private readonly WooRequestStore $store,
		private readonly StatutoryTerm $terms,
	) {

	}//end __construct()

	/**
	 * Receive one request from the portal.
	 *
	 * @param array<string, mixed> $answers What the citizen filled in, keyed by the request's own field names.
	 * @param string $receivedAt When the citizen sent it (ISO 8601). The term counts from then, not from delivery.
	 *
	 * @return array{outcome: string, requestId: string, reference: string, dueAt: string, message: string}
	 *
	 * @spec openspec/changes/portal-woo-request-intake/specs/woo-request-intake/spec.md#requirement-a-woo-request-delivered-by-the-portal-arms-its-term-req-wri-008
	 */
	public function receive(array $answers, string $receivedAt = ''): array {
		$input = ['channel' => 'web'];
		foreach (self::FIELDS as $field) {
			$input[$field] = (string)($answers[$field] ?? '');
		}

		try {
			$record = $this->requests->receive(input: $input, receivedBy: self::RECEIVED_BY, now: $this->moment(value: $receivedAt));
		} catch (DomainException $e) {
			return $this->outcome(outcome: self::OUTCOME_REFUSED, message: $e->getMessage());
		}

		try {
			$saved = $this->store->save(record: $record);
		} catch (Throwable $e) {
			return $this->outcome(outcome: self::OUTCOME_UNAVAILABLE, message: $e->getMessage());
		}

		$requestId = (string)($saved['id'] ?? '');
		$reference = (string)($saved['reference'] ?? '');

		try {
			$term = $this->terms->arm(
				requestUuid: $requestId,
				reference: $reference,
				receivedAt: new DateTimeImmutable((string)($saved['receivedAt'] ?? 'now')),
				actor: null
			);
			$stored = $this->store->save(record: $this->requests->withTerm(request: $saved, term: $term), uuid: $requestId);
		} catch (Throwable $e) {
			// Stored, so it has arrived; what is refused is the claim that a
			// term runs. The caller must not quote a date nobody computed.
			return $this->outcome(outcome: self::OUTCOME_NOT_ARMED, requestId: $requestId, reference: $reference, message: $e->getMessage());
		}

		$dueAt = (string)($this->requests->receipt(request: $stored)['dueAt'] ?? '');
		if ($dueAt === '') {
			return $this->outcome(
				outcome: self::OUTCOME_NOT_ARMED,
				requestId: $requestId,
				reference: $reference,
				message: 'The term engine armed no due date.'
			);
		}

		return $this->outcome(outcome: self::OUTCOME_ARMED, requestId: $requestId, reference: $reference, dueAt: $dueAt);

	}//end receive()

	/**
	 * The moment a request was sent, or null for now.
	 *
	 * @param string $value An ISO 8601 moment, or ''.
	 *
	 * @return DateTimeImmutable|null The moment.
	 *
	 * @spec exclude internal helper, parses the portal's submission time.
	 */
	private function moment(string $value): ?DateTimeImmutable {
		if (trim($value) === '') {
			return null;
		}

		try {
			return new DateTimeImmutable($value);
		} catch (Throwable) {
			return null;
		}

	}//end moment()

	/**
	 * One outcome, every key present.
	 *
	 * @param string $outcome The outcome.
	 * @param string $requestId The stored request, or ''.
	 * @param string $reference The minted reference, or ''.
	 * @param string $dueAt The due date, or ''.
	 * @param string $message Why, when it is not armed.
	 *
	 * @return array{outcome: string, requestId: string, reference: string, dueAt: string, message: string}
	 *
	 * @spec exclude internal helper, shapes the answer.
	 */
	private function outcome(string $outcome, string $requestId='', string $reference='', string $dueAt='', string $message=''): array {
		return [
			'outcome' => $outcome,
			'requestId' => $requestId,
			'reference' => $reference,
			'dueAt' => $dueAt,
			'message' => $message,
		];

	}//end outcome()
}//end class
