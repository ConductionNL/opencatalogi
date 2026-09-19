<?php

/**
 * OpenCatalogi Subscription Service.
 *
 * A subscription to a status page or a notice board is a notification
 * subscription under ADR-031. This app builds no second mailing mechanism and
 * sends nothing itself: it decides who is eligible to be told, and the
 * declared `x-openregister-notifications` block on the schema does the telling.
 *
 * An anonymous reader's address becomes a recipient only after a one-time
 * confirmation. Subscribing somebody else to an alert stream is a way to send
 * mail on their behalf, so an unconfirmed address is never in the recipient
 * list, not even once.
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
 * @spec openspec/changes/the-public-and-community-surface/specs/public-and-community-surface/spec.md#requirement-a-reader-subscribes-to-changes-on-the-status-page-req-pcs-102
 */

declare(strict_types=1);

namespace OCA\OpenCatalogi\Service\Community;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use DomainException;

/**
 * Holds subscriptions and decides which of them are recipients.
 *
 * @spec openspec/changes/the-public-and-community-surface/specs/public-and-community-surface/spec.md#requirement-a-reader-subscribes-to-changes-on-the-status-page-req-pcs-102
 */
class SubscriptionService {

	/**
	 * Constructor.
	 *
	 * @param string $salt The salt the confirmation token is hashed with.
	 */
	public function __construct(
		private readonly string $salt = '',
	) {

	}//end __construct()

	/**
	 * The stored form of a confirmation token.
	 *
	 * @param string $token The token.
	 *
	 * @return string The salted hash.
	 *
	 * @spec openspec/changes/the-public-and-community-surface/specs/public-and-community-surface/spec.md#requirement-a-reader-subscribes-to-changes-on-the-status-page-req-pcs-102
	 */
	public function tokenHash(string $token): string {
		return hash('sha256', $this->salt . '|' . $token);

	}//end tokenHash()

	/**
	 * Record a request to subscribe, unconfirmed.
	 *
	 * The returned pair is the subscription to save and the one-time token to
	 * send to the address. The token itself is never stored: a stored token is
	 * a stored way to confirm somebody else's subscription.
	 *
	 * @param string $address Where the reader asked to be told.
	 * @param string $scope The status page or board.
	 * @param DateTimeInterface|null $now The moment; defaults to now.
	 *
	 * @return array{subscription: array<string, mixed>, token: string}
	 *
	 * @throws DomainException When the address is not usable.
	 *
	 * @spec openspec/changes/the-public-and-community-surface/specs/public-and-community-surface/spec.md#requirement-a-reader-subscribes-to-changes-on-the-status-page-req-pcs-102
	 */
	public function request(string $address, string $scope, ?DateTimeInterface $now = null): array {
		$address = trim($address);
		if ($address === '' || filter_var($address, FILTER_VALIDATE_EMAIL) === false) {
			throw new DomainException(message: 'A subscription needs an address that can be confirmed.');
		}

		$moment = new DateTimeImmutable('now', new DateTimeZone('UTC'));
		if ($now !== null) {
			$moment = new DateTimeImmutable($now->format('Y-m-d\\TH:i:s.uP'));
		}
		$token = bin2hex(random_bytes(16));

		return [
			'subscription' => [
				'address' => $address,
				'scope' => $scope,
				'confirmedAt' => null,
				'confirmationTokenHash' => $this->tokenHash(token: $token),
				'requestedAt' => $moment->format(DateTimeInterface::ATOM),
			],
			'token' => $token,
		];

	}//end request()

	/**
	 * Confirm a subscription with the token that was sent to its address.
	 *
	 * @param array<string, mixed> $subscription The subscription.
	 * @param string $token The token from the confirmation link.
	 * @param DateTimeInterface|null $now The moment; defaults to now.
	 *
	 * @return array<string, mixed> The confirmed subscription.
	 *
	 * @throws DomainException When the token does not match.
	 *
	 * @spec openspec/changes/the-public-and-community-surface/specs/public-and-community-surface/spec.md#requirement-a-reader-subscribes-to-changes-on-the-status-page-req-pcs-102
	 */
	public function confirm(array $subscription, string $token, ?DateTimeInterface $now = null): array {
		$stored = (string)($subscription['confirmationTokenHash'] ?? '');
		if ($stored === '' || hash_equals($stored, $this->tokenHash(token: $token)) === false) {
			throw new DomainException(message: 'This confirmation link does not match this subscription.');
		}

		$moment = new DateTimeImmutable('now', new DateTimeZone('UTC'));
		if ($now !== null) {
			$moment = new DateTimeImmutable($now->format('Y-m-d\\TH:i:s.uP'));
		}

		$subscription['confirmedAt'] = $moment->format(DateTimeInterface::ATOM);

		return $subscription;

	}//end confirm()

	/**
	 * Whether one subscription may be told anything.
	 *
	 * @param array<string, mixed> $subscription The subscription.
	 *
	 * @return boolean True only when it was confirmed.
	 *
	 * @spec openspec/changes/the-public-and-community-surface/specs/public-and-community-surface/spec.md#requirement-a-reader-subscribes-to-changes-on-the-status-page-req-pcs-102
	 */
	public function isRecipient(array $subscription): bool {
		return (trim((string)($subscription['confirmedAt'] ?? '')) !== '');

	}//end isRecipient()

	/**
	 * The addresses that may be told about a change on one scope.
	 *
	 * The list is deduplicated, so a reader who subscribed twice is told once.
	 *
	 * @param array<int, array<string, mixed>> $subscriptions Every subscription.
	 * @param string $scope The scope that changed.
	 *
	 * @return array<int, string> The confirmed addresses, and no others.
	 *
	 * @spec openspec/changes/the-public-and-community-surface/specs/public-and-community-surface/spec.md#requirement-a-reader-subscribes-to-changes-on-the-status-page-req-pcs-102
	 */
	public function recipients(array $subscriptions, string $scope): array {
		$addresses = [];

		foreach ($subscriptions as $subscription) {
			if (is_array($subscription) === false) {
				continue;
			}

			if ((string)($subscription['scope'] ?? '') !== $scope) {
				continue;
			}

			if ($this->isRecipient(subscription: $subscription) === false) {
				continue;
			}

			$address = trim((string)($subscription['address'] ?? ''));
			if ($address !== '') {
				$addresses[$address] = true;
			}
		}

		return array_keys($addresses);

	}//end recipients()
}//end class
