<?php

/**
 * Contract of FeedConsumerService, for callers in other components.
 *
 * @category Contract
 * @package  OCA\OpenCatalogi\Contract
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
 */

declare(strict_types=1);

namespace OCA\OpenCatalogi\Contract;

use OCP\IUser;

/**
 * Contract of FeedConsumerService, for callers in other components.
 *
 * Callers outside Service/ depend on this interface instead of on the class.
 */
interface FeedConsumerServiceInterface {

	/**
	 * Add a consumer to a catalogue, with an account of its own.
	 *
	 * @param array<string, mixed> $catalog The catalogue.
	 * @param array<string, mixed> $input   name, contact.
	 *
	 * @return array<string, mixed> The consumer, without key verifiers.
	 *
	 * @throws \InvalidArgumentException When the input is refused.
	 * @throws \RuntimeException When the account cannot be created.
	 */
	public function addConsumer(array $catalog, array $input): array;

	/**
	 * The consumers of a catalogue, without key verifiers.
	 *
	 * @param string $catalogId The catalogue id.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function consumersOf(string $catalogId): array;

	/**
	 * One consumer of a catalogue, without key verifiers.
	 *
	 * @param string $catalogId  The catalogue id.
	 * @param string $consumerId The consumer id.
	 *
	 * @return array<string, mixed>
	 *
	 * @throws \OutOfBoundsException When the catalogue has no such consumer.
	 */
	public function consumerOf(string $catalogId, string $consumerId): array;

	/**
	 * Generate a key for a consumer; the key is in this answer and nowhere else.
	 *
	 * @param string               $consumerId The consumer id.
	 * @param array<string, mixed> $input      label, expiresAt.
	 *
	 * @return array{key: string, consumer: array<string, mixed>}
	 *
	 * @throws \OutOfBoundsException When there is no such consumer.
	 * @throws \InvalidArgumentException When the consumer is disabled, holds two active keys or the input is refused.
	 */
	public function generateKey(string $consumerId, array $input): array;

	/**
	 * Revoke one key of a consumer.
	 *
	 * @param string $consumerId The consumer id.
	 * @param string $keyId      The key id.
	 *
	 * @return array<string, mixed> The consumer, without key verifiers.
	 *
	 * @throws \OutOfBoundsException When there is no such consumer or key.
	 */
	public function revokeKey(string $consumerId, string $keyId): array;

	/**
	 * Disable a consumer and its account.
	 *
	 * @param string $consumerId The consumer id.
	 *
	 * @return array<string, mixed> The consumer, without key verifiers.
	 *
	 * @throws \OutOfBoundsException When there is no such consumer.
	 */
	public function disableConsumer(string $consumerId): array;

	/**
	 * The consumer and account a presented key belongs to, or null when the key is refused.
	 *
	 * @param string $presented The key as sent.
	 *
	 * @return array{consumer: array<string, mixed>, user: IUser}|null
	 */
	public function authenticate(string $presented): ?array;
}//end interface
