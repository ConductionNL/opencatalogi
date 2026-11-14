<?php

/**
 * OpenCatalogi Feed Consumer Service.
 *
 * Gives an outside consumer its own keys for one catalogue's protected feeds
 * (integration-feed-consumer-credentials). A consumer acts as a Nextcloud
 * account the app creates, in one group per catalogue, so OpenRegister's schema
 * authorization keeps deciding what it reads. A key reads
 * `oc_<consumerId>.<keyId>.<secret>`: the consumer and key ids make the lookup
 * one read, and only a one-way hash of the secret is stored, so the secret
 * exists only in the answer that creates it.
 *
 * @category Service
 * @package  OCA\OpenCatalogi\Service
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
 * @spec openspec/changes/integration-feed-consumer-credentials/specs/ooapi-catalog-publication/spec.md#requirement-an-administrator-issues-a-key-to-a-consumer-for-one-catalogue-req-fcc-001
 */

declare(strict_types=1);

namespace OCA\OpenCatalogi\Service;

use InvalidArgumentException;
use OCA\OpenCatalogi\Contract\FeedConsumerServiceInterface;
use OCA\OpenCatalogi\Service\Portal\PortalObjectStore;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IUser;
use OCP\Security\IHasher;
use OCP\Security\ISecureRandom;
use OutOfBoundsException;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Throwable;

/**
 * Issues, verifies and revokes the keys of feed consumers.
 *
 * @spec openspec/changes/integration-feed-consumer-credentials/specs/ooapi-catalog-publication/spec.md#requirement-an-administrator-issues-a-key-to-a-consumer-for-one-catalogue-req-fcc-001
 */
class FeedConsumerService implements FeedConsumerServiceInterface {

	/**
	 * The consumer schema in the publication register.
	 */
	public const SCHEMA = 'feedConsumer';

	/**
	 * What every key starts with.
	 */
	public const KEY_PREFIX = 'oc_';

	/**
	 * The most keys a consumer holds unrevoked: an old and a new one while it switches.
	 */
	public const MAX_ACTIVE_KEYS = 2;

	/**
	 * Seconds between two writes of a key's last use.
	 */
	public const LAST_USED_INTERVAL = 3600;

	/**
	 * The prefix of the Nextcloud account a consumer acts as.
	 */
	public const ACCOUNT_PREFIX = FeedConsumerAccounts::ACCOUNT_PREFIX;

	/**
	 * The prefix of a catalogue's consumer group.
	 */
	public const GROUP_PREFIX = FeedConsumerAccounts::GROUP_PREFIX;

	/**
	 * The most consumers one catalogue lists.
	 */
	private const LIST_LIMIT = 1000;

	/**
	 * Constructor.
	 *
	 * @param PortalObjectStore    $store    Reads and writes publication-register objects as the system.
	 * @param FeedConsumerAccounts $accounts Creates and finds the consumer accounts.
	 * @param IHasher              $hasher   Hashes and verifies key secrets.
	 * @param ISecureRandom        $random   Key ids and secrets.
	 * @param ITimeFactory         $time     The clock.
	 * @param LoggerInterface      $logger   The logger.
	 */
	public function __construct(
		private readonly PortalObjectStore $store,
		private readonly FeedConsumerAccounts $accounts,
		private readonly IHasher $hasher,
		private readonly ISecureRandom $random,
		private readonly ITimeFactory $time,
		private readonly LoggerInterface $logger,
	) {

	}//end __construct()

	/**
	 * Add a consumer to a catalogue, with an account of its own in the catalogue's consumer group.
	 *
	 * @param array<string, mixed> $catalog The catalogue.
	 * @param array<string, mixed> $input   name, contact.
	 *
	 * @return array<string, mixed> The consumer, without key verifiers.
	 *
	 * @throws InvalidArgumentException When the name, contact or catalogue is refused.
	 * @throws RuntimeException When the account cannot be created.
	 *
	 * @spec openspec/changes/integration-feed-consumer-credentials/specs/ooapi-catalog-publication/spec.md#requirement-an-administrator-issues-a-key-to-a-consumer-for-one-catalogue-req-fcc-001
	 */
	public function addConsumer(array $catalog, array $input): array {
		$name = $this->text(input: $input, field: 'name', max: 200, required: true);
		$contact = $this->text(input: $input, field: 'contact', max: 255, required: false);
		$catalogId = $this->catalogId(catalog: $catalog);
		$catalogSlug = (string)($catalog['slug'] ?? '');
		if ($catalogSlug === '') {
			throw new InvalidArgumentException('The catalogue has no slug');
		}

		$user = $this->accounts->create(name: $name, catalogSlug: $catalogSlug);

		$consumer = $this->store->save(
			schema: self::SCHEMA,
			data: [
				'name' => $name,
				'contact' => $contact,
				'catalog' => $catalogId,
				'account' => $user->getUID(),
				'enabled' => true,
				'keys' => [],
			]
		);

		return $this->publicView(consumer: $consumer);
	}//end addConsumer()

	/**
	 * The consumers of a catalogue, without key verifiers.
	 *
	 * @param string $catalogId The catalogue id.
	 *
	 * @return array<int, array<string, mixed>>
	 *
	 * @spec openspec/changes/integration-feed-consumer-credentials/specs/ooapi-catalog-publication/spec.md#requirement-keys-rotate-without-an-outage-req-fcc-003
	 */
	public function consumersOf(string $catalogId): array {
		if ($catalogId === '') {
			return [];
		}

		$rows = $this->store->findWhere(schema: self::SCHEMA, filters: ['catalog' => $catalogId], limit: self::LIST_LIMIT);
		return array_values(array_map(fn (array $consumer): array => $this->publicView(consumer: $consumer), $rows));
	}//end consumersOf()

	/**
	 * One consumer of a catalogue, without key verifiers.
	 *
	 * @param string $catalogId  The catalogue id.
	 * @param string $consumerId The consumer id.
	 *
	 * @return array<string, mixed>
	 *
	 * @throws OutOfBoundsException When the catalogue has no such consumer.
	 *
	 * @spec openspec/changes/integration-feed-consumer-credentials/specs/ooapi-catalog-publication/spec.md#requirement-a-key-reads-only-its-catalogues-protected-feeds-req-fcc-002
	 */
	public function consumerOf(string $catalogId, string $consumerId): array {
		$consumer = $this->consumer(consumerId: $consumerId);
		if ($catalogId === '' || (string)($consumer['catalog'] ?? '') !== $catalogId) {
			throw new OutOfBoundsException('Feed consumer not found');
		}

		return $this->publicView(consumer: $consumer);
	}//end consumerOf()

	/**
	 * Generate a key for a consumer. The key is in this answer and nowhere else;
	 * the consumer keeps only a hash of its secret.
	 *
	 * @param string               $consumerId The consumer id.
	 * @param array<string, mixed> $input      label, expiresAt (ISO 8601, in the future).
	 *
	 * @return array{key: string, consumer: array<string, mixed>}
	 *
	 * @throws OutOfBoundsException When there is no such consumer.
	 * @throws InvalidArgumentException When the consumer is disabled, holds two active keys or the input is refused.
	 *
	 * @spec openspec/changes/integration-feed-consumer-credentials/specs/ooapi-catalog-publication/spec.md#requirement-keys-rotate-without-an-outage-req-fcc-003
	 */
	public function generateKey(string $consumerId, array $input): array {
		$consumer = $this->consumer(consumerId: $consumerId);
		if (($consumer['enabled'] ?? true) === false) {
			throw new InvalidArgumentException('A disabled consumer gets no new keys');
		}

		$keys = $this->keysOf(consumer: $consumer);
		if (count(array_filter($keys, fn (array $key): bool => $this->isActive(key: $key))) >= self::MAX_ACTIVE_KEYS) {
			throw new InvalidArgumentException('A consumer holds at most two active keys; revoke one first');
		}

		$label = $this->text(input: $input, field: 'label', max: 100, required: false);
		$expiresAt = $this->expiry(input: $input);
		$keyId = $this->random->generate(16, ISecureRandom::CHAR_LOWER.ISecureRandom::CHAR_DIGITS);
		$secret = $this->random->generate(48, ISecureRandom::CHAR_ALPHANUMERIC);

		// A moment that has not happened (expiry, last use, revocation) is left
		// out rather than stored as null: the schema types each as one string.
		$key = [
			'id' => $keyId,
			'label' => $label,
			'hash' => $this->hasher->hash($secret),
			'createdAt' => $this->now(),
		];
		if ($expiresAt !== null) {
			$key['expiresAt'] = $expiresAt;
		}

		$keys[] = $key;
		$consumer['keys'] = $keys;
		$saved = $this->store->save(schema: self::SCHEMA, data: $consumer, id: $consumerId);

		return [
			'key' => self::KEY_PREFIX.$consumerId.'.'.$keyId.'.'.$secret,
			'consumer' => $this->publicView(consumer: $saved),
		];
	}//end generateKey()

	/**
	 * Revoke one key of a consumer. Revoking a revoked key keeps its first revocation time.
	 *
	 * @param string $consumerId The consumer id.
	 * @param string $keyId      The key id.
	 *
	 * @return array<string, mixed> The consumer, without key verifiers.
	 *
	 * @throws OutOfBoundsException When there is no such consumer or key.
	 *
	 * @spec openspec/changes/integration-feed-consumer-credentials/specs/ooapi-catalog-publication/spec.md#requirement-keys-rotate-without-an-outage-req-fcc-003
	 */
	public function revokeKey(string $consumerId, string $keyId): array {
		$consumer = $this->consumer(consumerId: $consumerId);
		$keys = $this->keysOf(consumer: $consumer);
		$index = $this->keyIndex(keys: $keys, keyId: $keyId);
		if ($index === null) {
			throw new OutOfBoundsException('Key not found');
		}

		if (($keys[$index]['revokedAt'] ?? null) === null) {
			$keys[$index]['revokedAt'] = $this->now();
		}

		$consumer['keys'] = $keys;
		return $this->publicView(consumer: $this->store->save(schema: self::SCHEMA, data: $consumer, id: $consumerId));
	}//end revokeKey()

	/**
	 * Disable a consumer and the account it acts as.
	 *
	 * @param string $consumerId The consumer id.
	 *
	 * @return array<string, mixed> The consumer, without key verifiers.
	 *
	 * @throws OutOfBoundsException When there is no such consumer.
	 *
	 * @spec openspec/changes/integration-feed-consumer-credentials/specs/ooapi-catalog-publication/spec.md#requirement-an-administrator-issues-a-key-to-a-consumer-for-one-catalogue-req-fcc-001
	 */
	public function disableConsumer(string $consumerId): array {
		$consumer = $this->consumer(consumerId: $consumerId);
		$user = $this->accounts->find(uid: (string)($consumer['account'] ?? ''));
		if ($user !== null) {
			$user->setEnabled(false);
		}

		$consumer['enabled'] = false;
		return $this->publicView(consumer: $this->store->save(schema: self::SCHEMA, data: $consumer, id: $consumerId));
	}//end disableConsumer()

	/**
	 * The consumer and account a presented key belongs to, or null when the key
	 * is malformed, unknown, revoked, expired or wrong, or its consumer or
	 * account is disabled. A key that passes has its last use recorded, at most hourly.
	 *
	 * @param string $presented The key as sent.
	 *
	 * @return array{consumer: array<string, mixed>, user: IUser}|null
	 *
	 * @spec openspec/changes/integration-feed-consumer-credentials/specs/ooapi-catalog-publication/spec.md#requirement-a-key-reads-only-its-catalogues-protected-feeds-req-fcc-002
	 */
	public function authenticate(string $presented): ?array {
		$parts = $this->parseKey(presented: $presented);
		if ($parts === null) {
			return null;
		}

		$consumer = $this->store->find(schema: self::SCHEMA, id: $parts['consumerId']);
		if ($consumer === null || ($consumer['enabled'] ?? true) === false) {
			return null;
		}

		$keys = $this->keysOf(consumer: $consumer);
		$index = $this->keyIndex(keys: $keys, keyId: $parts['keyId']);
		if ($index === null || $this->isActive(key: $keys[$index]) === false) {
			return null;
		}

		if ($this->hasher->verify($parts['secret'], (string)($keys[$index]['hash'] ?? '')) === false) {
			return null;
		}

		$user = $this->accounts->find(uid: (string)($consumer['account'] ?? ''));
		if ($user === null || $user->isEnabled() === false) {
			return null;
		}

		$this->recordUse(consumer: $consumer, consumerId: $parts['consumerId'], index: $index);
		return ['consumer' => $this->publicView(consumer: $consumer), 'user' => $user];
	}//end authenticate()

	/**
	 * The instance-wide account list the OOAPI feed also accepts.
	 *
	 * @return array{accounts: list<string>, everyAccount: bool, setting: string}
	 *
	 * @spec openspec/changes/integration-feed-consumer-credentials/specs/ooapi-catalog-publication/spec.md#requirement-account-based-access-keeps-working-and-is-visible-req-fcc-004
	 */
	public function instanceAccounts(): array {
		return $this->accounts->instanceAccounts();
	}//end instanceAccounts()

	/**
	 * Split a presented key into its consumer id, key id and secret.
	 *
	 * @param string $presented The key as sent.
	 *
	 * @return array{consumerId: string, keyId: string, secret: string}|null Null when it is not shaped like a key.
	 */
	private function parseKey(string $presented): ?array {
		if (str_starts_with($presented, self::KEY_PREFIX) === false) {
			return null;
		}

		$parts = explode('.', substr($presented, strlen(self::KEY_PREFIX)));
		if (count($parts) !== 3 || in_array('', $parts, true) === true) {
			return null;
		}

		return ['consumerId' => $parts[0], 'keyId' => $parts[1], 'secret' => $parts[2]];
	}//end parseKey()

	/**
	 * Write the key's last use when the previous write is an hour old or more.
	 * A failed write never refuses the request.
	 *
	 * @param array<string, mixed> $consumer   The consumer as stored.
	 * @param string               $consumerId The consumer id.
	 * @param int                  $index      The index of the key used.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/integration-feed-consumer-credentials/specs/ooapi-catalog-publication/spec.md#requirement-keys-rotate-without-an-outage-req-fcc-003
	 */
	private function recordUse(array $consumer, string $consumerId, int $index): void {
		$keys = $this->keysOf(consumer: $consumer);
		$last = $this->timestamp(value: ($keys[$index]['lastUsedAt'] ?? null));
		if ($last !== null && ($this->time->getTime() - $last) < self::LAST_USED_INTERVAL) {
			return;
		}

		$keys[$index]['lastUsedAt'] = $this->now();
		$consumer['keys'] = $keys;
		try {
			$this->store->save(schema: self::SCHEMA, data: $consumer, id: $consumerId);
		} catch (Throwable $e) {
			$this->logger->warning('OpenCatalogi: could not record a feed key use', ['reason' => $e->getMessage()]);
		}
	}//end recordUse()

	/**
	 * Whether a key is neither revoked nor expired.
	 *
	 * @param array<string, mixed> $key The key.
	 *
	 * @return bool
	 */
	private function isActive(array $key): bool {
		if (($key['revokedAt'] ?? null) !== null) {
			return false;
		}

		$expires = $this->timestamp(value: ($key['expiresAt'] ?? null));
		return $expires === null || $expires > $this->time->getTime();
	}//end isActive()

	/**
	 * A consumer by id.
	 *
	 * @param string $consumerId The consumer id.
	 *
	 * @return array<string, mixed>
	 *
	 * @throws OutOfBoundsException When there is none.
	 */
	private function consumer(string $consumerId): array {
		$consumer = $this->store->find(schema: self::SCHEMA, id: $consumerId);
		if ($consumer === null) {
			throw new OutOfBoundsException('Feed consumer not found');
		}

		return $consumer;
	}//end consumer()

	/**
	 * The consumer's keys as a list.
	 *
	 * @param array<string, mixed> $consumer The consumer.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function keysOf(array $consumer): array {
		$keys = $consumer['keys'] ?? [];
		if (is_array($keys) === false) {
			return [];
		}

		return array_values(array_filter($keys, 'is_array'));
	}//end keysOf()

	/**
	 * The index of a key by id.
	 *
	 * @param array<int, array<string, mixed>> $keys  The keys.
	 * @param string                           $keyId The key id.
	 *
	 * @return int|null
	 */
	private function keyIndex(array $keys, string $keyId): ?int {
		foreach ($keys as $index => $key) {
			if ($keyId !== '' && (string)($key['id'] ?? '') === $keyId) {
				return $index;
			}
		}

		return null;
	}//end keyIndex()

	/**
	 * The consumer as an administrator sees it: every key without its verifier.
	 *
	 * @param array<string, mixed> $consumer The consumer as stored.
	 *
	 * @return array<string, mixed>
	 */
	private function publicView(array $consumer): array {
		$consumer['keys'] = array_map(
			static function (array $key): array {
				unset($key['hash']);
				return $key;
			},
			$this->keysOf(consumer: $consumer)
		);

		return $consumer;
	}//end publicView()

	/**
	 * The catalogue's id.
	 *
	 * @param array<string, mixed> $catalog The catalogue.
	 *
	 * @return string
	 *
	 * @throws InvalidArgumentException When it has none.
	 */
	private function catalogId(array $catalog): string {
		$id = (string)($catalog['id'] ?? ($catalog['@self']['id'] ?? ''));
		if ($id === '') {
			throw new InvalidArgumentException('The catalogue has no id');
		}

		return $id;
	}//end catalogId()

	/**
	 * A trimmed text field.
	 *
	 * @param array<string, mixed> $input    The input.
	 * @param string               $field    The field.
	 * @param int                  $max      The most characters.
	 * @param bool                 $required Whether it must be present.
	 *
	 * @return string
	 *
	 * @throws InvalidArgumentException When it is not text, too long, or missing while required.
	 */
	private function text(array $input, string $field, int $max, bool $required): string {
		$value = $input[$field] ?? '';
		if (is_string($value) === false) {
			throw new InvalidArgumentException($field.' must be text');
		}

		$value = trim($value);
		if (($required === true && $value === '') || mb_strlen($value) > $max) {
			throw new InvalidArgumentException($field.' needs between '.(int)$required.' and '.$max.' characters');
		}

		return $value;
	}//end text()

	/**
	 * The key's expiry: none, or a moment in the future.
	 *
	 * @param array<string, mixed> $input The input.
	 *
	 * @return string|null The expiry in ISO 8601, or null.
	 *
	 * @throws InvalidArgumentException When it is not a date or not in the future.
	 */
	private function expiry(array $input): ?string {
		$value = $input['expiresAt'] ?? null;
		if ($value === null || $value === '') {
			return null;
		}

		$stamp = $this->timestamp(value: $value);
		if ($stamp === null || $stamp <= $this->time->getTime()) {
			throw new InvalidArgumentException('expiresAt must be a date in the future');
		}

		return gmdate('c', $stamp);
	}//end expiry()

	/**
	 * A date as a Unix timestamp, or null when it is empty or not a date.
	 *
	 * @param mixed $value The date.
	 *
	 * @return int|null
	 */
	private function timestamp(mixed $value): ?int {
		if (is_string($value) === false || $value === '') {
			return null;
		}

		$stamp = strtotime($value);
		if ($stamp === false) {
			return null;
		}

		return $stamp;
	}//end timestamp()

	/**
	 * Now, in ISO 8601.
	 *
	 * @return string
	 */
	private function now(): string {
		return gmdate('c', $this->time->getTime());
	}//end now()
}//end class
