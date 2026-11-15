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
use OCA\OpenCatalogi\Contract\InformationRequestIntakeInterface;
use Throwable;

/**
 * Receives a Woo request handed over by the portal and arms its term.
 *
 * @spec openspec/changes/portal-woo-request-intake/specs/woo-request-intake/spec.md#requirement-a-woo-request-delivered-by-the-portal-arms-its-term-req-wri-008
 */
class WooRequestIntake implements InformationRequestIntakeInterface {

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
		try {
			$record = $this->requests->receive(
				input: $this->intakeInput(answers: $answers),
				receivedBy: self::RECEIVED_BY,
				now: $this->moment(value: $receivedAt)
			);
		} catch (DomainException $e) {
			return $this->withMessage(outcome: $this->outcome(outcome: self::OUTCOME_REFUSED, saved: []), message: $e->getMessage());
		}

		try {
			$saved = $this->store->save(record: $record);
		} catch (Throwable $e) {
			return $this->withMessage(outcome: $this->outcome(outcome: self::OUTCOME_UNAVAILABLE, saved: []), message: $e->getMessage());
		}

		return $this->armTerm(saved: $saved);
	}//end receive()

	/**
	 * The request record's input: the web channel and every intake field, as text.
	 *
	 * @param array<string, mixed> $answers What the citizen filled in.
	 *
	 * @return array<string, string> The input.
	 */
	private function intakeInput(array $answers): array {
		$input = ['channel' => 'web'];
		foreach (self::FIELDS as $field) {
			$input[$field] = (string)($answers[$field] ?? '');
		}

		return $input;
	}//end intakeInput()

	/**
	 * Arm the term of a stored request and answer with its due date.
	 *
	 * @param array<string, mixed> $saved The stored request.
	 *
	 * @return array{outcome: string, requestId: string, reference: string, dueAt: string, message: string}
	 */
	private function armTerm(array $saved): array {
		try {
			$stored = $this->storeWithTerm(saved: $saved);
		} catch (Throwable $e) {
			// Stored, so it has arrived; what is refused is the claim that a
			// term runs. The caller must not quote a date nobody computed.
			return $this->withMessage(outcome: $this->outcome(outcome: self::OUTCOME_NOT_ARMED, saved: $saved), message: $e->getMessage());
		}

		$dueAt = (string)($this->requests->receipt(request: $stored)['dueAt'] ?? '');
		if ($dueAt === '') {
			return $this->withMessage(outcome: $this->outcome(outcome: self::OUTCOME_NOT_ARMED, saved: $saved), message: 'The term engine armed no due date.');
		}

		$armed = $this->outcome(outcome: self::OUTCOME_ARMED, saved: $saved);
		$armed['dueAt'] = $dueAt;

		return $armed;
	}//end armTerm()

	/**
	 * Arm the term of a stored request and store the request with it.
	 *
	 * @param array<string, mixed> $saved The stored request.
	 *
	 * @return array<string, mixed> The request as stored with its term.
	 */
	private function storeWithTerm(array $saved): array {
		$requestId = (string)($saved['id'] ?? '');
		$term = $this->terms->arm(
			requestUuid: $requestId,
			reference: (string)($saved['reference'] ?? ''),
			receivedAt: new DateTimeImmutable((string)($saved['receivedAt'] ?? 'now')),
			actor: null
		);

		return $this->store->save(record: $this->requests->withTerm(request: $saved, term: $term), uuid: $requestId);
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
	 * One outcome, every key present: the stored request's id and reference,
	 * or '' for a request that was not stored.
	 *
	 * @param string $outcome The outcome.
	 * @param array<string, mixed> $saved The stored request, or [].
	 *
	 * @return array{outcome: string, requestId: string, reference: string, dueAt: string, message: string}
	 *
	 * @spec exclude internal helper, shapes the answer.
	 */
	private function outcome(string $outcome, array $saved): array {
		return [
			'outcome' => $outcome,
			'requestId' => (string)($saved['id'] ?? ''),
			'reference' => (string)($saved['reference'] ?? ''),
			'dueAt' => '',
			'message' => '',
		];

	}//end outcome()

	/**
	 * An outcome with the reason it is not armed.
	 *
	 * @param array{outcome: string, requestId: string, reference: string, dueAt: string, message: string} $outcome The outcome.
	 * @param string $message Why.
	 *
	 * @return array{outcome: string, requestId: string, reference: string, dueAt: string, message: string}
	 *
	 * @spec exclude internal helper, shapes the answer.
	 */
	private function withMessage(array $outcome, string $message): array {
		$outcome['message'] = $message;
		return $outcome;

	}//end withMessage()
}//end class
