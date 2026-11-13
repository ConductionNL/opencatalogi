<?php

/**
 * Unit tests for FeedConsumerService.
 *
 * Covers adding a consumer with its own account in the catalogue's consumer
 * group, generating a key that is stored only as a hash and returned once,
 * the two-active-key limit, revoking, disabling, and verifying a presented
 * key: valid, wrong, revoked, expired, malformed, disabled, and the hourly
 * last-use write. OpenRegister, the account and group managers, the hasher,
 * the random source and the clock are mocked.
 *
 * @category Test
 * @package  OCA\OpenCatalogi\Tests
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://www.OpenCatalogi.nl
 */

declare(strict_types=1);

namespace Unit\Service;

use InvalidArgumentException;
use OCA\OpenCatalogi\Service\FeedConsumerService;
use OCA\OpenCatalogi\Service\Portal\PortalObjectStore;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IGroup;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\IUserManager;
use OCP\Security\IHasher;
use OCP\Security\ISecureRandom;
use OutOfBoundsException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Unit tests for FeedConsumerService.
 */
#[CoversClass(FeedConsumerService::class)]
class FeedConsumerServiceTest extends TestCase {

	private const NOW = 1_800_000_000;

	private const CONSUMER_ID = '0d4c8f0e-1111-4c4c-9a9a-000000000001';

	private PortalObjectStore|MockObject $store;
	private IUserManager|MockObject $userManager;
	private IGroupManager|MockObject $groupManager;
	private ISecureRandom|MockObject $random;
	private FeedConsumerService $service;

	/**
	 * The objects the mocked store holds, by id.
	 *
	 * @var array<string, array<string, mixed>>
	 */
	private array $objects = [];

	/**
	 * Every object the mocked store was asked to save.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private array $saved = [];

	protected function setUp(): void {
		$this->store = $this->createMock(PortalObjectStore::class);
		$this->store->method('find')->willReturnCallback(
			fn (string $schema, string $id): ?array => $this->objects[$id] ?? null
		);
		$this->store->method('save')->willReturnCallback(
			function (string $schema, array $data, ?string $id = null): array {
				$this->saved[] = $data;
				$id = $id ?? self::CONSUMER_ID;
				$this->objects[$id] = array_merge($data, ['id' => $id]);
				return $this->objects[$id];
			}
		);

		$this->userManager = $this->createMock(IUserManager::class);
		$this->groupManager = $this->createMock(IGroupManager::class);

		$hasher = $this->createMock(IHasher::class);
		$hasher->method('hash')->willReturnCallback(static fn (string $secret): string => 'hash:'.hash('sha256', $secret));
		$hasher->method('verify')->willReturnCallback(
			static fn (string $secret, string $hash): bool => $hash === 'hash:'.hash('sha256', $secret)
		);

		$this->random = $this->createMock(ISecureRandom::class);
		$this->random->method('generate')->willReturnCallback(
			static fn (int $length): string => match ($length) {
				16 => 'key0000000000001',
				48 => str_repeat('s', 48),
				6 => 'abc123',
				default => str_repeat('p', $length),
			}
		);

		$time = $this->createMock(ITimeFactory::class);
		$time->method('getTime')->willReturn(self::NOW);

		$this->service = new FeedConsumerService(
			$this->store,
			$this->userManager,
			$this->groupManager,
			$hasher,
			$this->random,
			$time,
			$this->createMock(LoggerInterface::class)
		);
	}

	/**
	 * An ISO 8601 moment relative to the test clock.
	 *
	 * @param int $offset Seconds from now.
	 *
	 * @return string
	 */
	private function at(int $offset): string {
		return gmdate('c', self::NOW + $offset);
	}

	/**
	 * Put a consumer with these keys in the store.
	 *
	 * @param array<int, array<string, mixed>> $keys      The keys.
	 * @param array<string, mixed>             $overrides Other fields.
	 *
	 * @return void
	 */
	private function givenConsumer(array $keys = [], array $overrides = []): void {
		$this->objects[self::CONSUMER_ID] = array_merge(
			[
				'id' => self::CONSUMER_ID,
				'name' => 'Studiekeuzeportaal',
				'catalog' => 'cat-1',
				'account' => 'feed-studiekeuzeportaal-abc123',
				'enabled' => true,
				'keys' => $keys,
			],
			$overrides
		);
	}

	/**
	 * A stored key.
	 *
	 * @param string               $id        The key id.
	 * @param array<string, mixed> $overrides Other fields.
	 *
	 * @return array<string, mixed>
	 */
	private function key(string $id, array $overrides = []): array {
		return array_merge(
			[
				'id' => $id,
				'label' => '',
				'hash' => 'hash:'.hash('sha256', 'secret-'.$id),
				'createdAt' => $this->at(-86400),
				'expiresAt' => null,
				'lastUsedAt' => null,
				'revokedAt' => null,
			],
			$overrides
		);
	}

	/**
	 * An enabled account the user manager returns for the consumer.
	 *
	 * @param bool $enabled Whether the account is enabled.
	 *
	 * @return IUser|MockObject
	 */
	private function givenAccount(bool $enabled = true): IUser|MockObject {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('feed-studiekeuzeportaal-abc123');
		$user->method('isEnabled')->willReturn($enabled);
		$this->userManager->method('get')->with('feed-studiekeuzeportaal-abc123')->willReturn($user);
		return $user;
	}

	public function testAddConsumerCreatesAnAccountInTheCatalogueGroupAndStoresTheConsumer(): void {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('feed-studiekeuzeportaal-abc123');
		$this->userManager->expects($this->once())->method('createUser')
			->with('feed-studiekeuzeportaal-abc123', str_repeat('p', 64))
			->willReturn($user);
		$group = $this->createMock(IGroup::class);
		$group->expects($this->once())->method('addUser')->with($user);
		$this->groupManager->method('get')->with('opencatalogi-feed-hva-onderwijs')->willReturn(null);
		$this->groupManager->expects($this->once())->method('createGroup')->with('opencatalogi-feed-hva-onderwijs')->willReturn($group);

		$consumer = $this->service->addConsumer(
			['id' => 'cat-1', 'slug' => 'hva-onderwijs'],
			['name' => '  Studiekeuzeportaal ', 'contact' => 'api@example.org']
		);

		$this->assertSame(
			[
				'name' => 'Studiekeuzeportaal',
				'contact' => 'api@example.org',
				'catalog' => 'cat-1',
				'account' => 'feed-studiekeuzeportaal-abc123',
				'enabled' => true,
				'keys' => [],
			],
			$this->saved[0]
		);
		$this->assertSame(self::CONSUMER_ID, $consumer['id']);
	}

	public function testAddConsumerReusesAnExistingCatalogueGroup(): void {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('feed-x-abc123');
		$this->userManager->method('createUser')->willReturn($user);
		$group = $this->createMock(IGroup::class);
		$group->expects($this->once())->method('addUser')->with($user);
		$this->groupManager->method('get')->willReturn($group);
		$this->groupManager->expects($this->never())->method('createGroup');

		$this->service->addConsumer(['@self' => ['id' => 'cat-1'], 'slug' => 'hva-onderwijs'], ['name' => 'X']);

		$this->assertSame('cat-1', $this->saved[0]['catalog']);
	}

	public function testAddConsumerRefusesAMissingName(): void {
		$this->userManager->expects($this->never())->method('createUser');
		$this->expectException(InvalidArgumentException::class);

		$this->service->addConsumer(['id' => 'cat-1', 'slug' => 'hva'], ['name' => '   ']);
	}

	public function testAddConsumerRefusesACatalogueWithoutSlug(): void {
		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessage('The catalogue has no slug');

		$this->service->addConsumer(['id' => 'cat-1'], ['name' => 'X']);
	}

	public function testAddConsumerFailsWhenTheAccountCannotBeCreated(): void {
		$this->userManager->method('createUser')->willReturn(false);
		$this->expectException(RuntimeException::class);

		$this->service->addConsumer(['id' => 'cat-1', 'slug' => 'hva'], ['name' => 'X']);
	}

	public function testGenerateKeyStoresOnlyTheHashAndReturnsTheKeyOnce(): void {
		$this->givenConsumer();

		$issued = $this->service->generateKey(self::CONSUMER_ID, ['label' => 'production', 'expiresAt' => $this->at(3600)]);

		$secret = str_repeat('s', 48);
		$this->assertSame('oc_'.self::CONSUMER_ID.'.key0000000000001.'.$secret, $issued['key']);
		$stored = $this->saved[0]['keys'][0];
		$this->assertSame(
			[
				'id' => 'key0000000000001',
				'label' => 'production',
				'hash' => 'hash:'.hash('sha256', $secret),
				'createdAt' => $this->at(0),
				'expiresAt' => $this->at(3600),
			],
			$stored
		);
		$this->assertStringNotContainsString($secret, json_encode($this->saved[0]));
		$this->assertArrayNotHasKey('hash', $issued['consumer']['keys'][0]);
	}

	public function testGenerateKeyAllowsASecondActiveKeyButNotAThird(): void {
		$this->givenConsumer([$this->key('k1')]);
		$this->service->generateKey(self::CONSUMER_ID, []);
		$this->assertCount(2, $this->objects[self::CONSUMER_ID]['keys']);

		$this->expectException(InvalidArgumentException::class);
		$this->service->generateKey(self::CONSUMER_ID, []);
	}

	public function testRevokedAndExpiredKeysDoNotCountTowardsTheLimit(): void {
		$this->givenConsumer([
			$this->key('k1', ['revokedAt' => $this->at(-60)]),
			$this->key('k2', ['expiresAt' => $this->at(-60)]),
			$this->key('k3'),
		]);

		$issued = $this->service->generateKey(self::CONSUMER_ID, []);

		$this->assertCount(4, $issued['consumer']['keys']);
	}

	public function testGenerateKeyWithoutExpiryStoresNoExpiry(): void {
		$this->givenConsumer();

		$this->service->generateKey(self::CONSUMER_ID, ['expiresAt' => '']);

		$this->assertSame(['id', 'label', 'hash', 'createdAt'], array_keys($this->saved[0]['keys'][0]));
	}

	public function testGenerateKeyRefusesADisabledConsumer(): void {
		$this->givenConsumer([], ['enabled' => false]);
		$this->expectException(InvalidArgumentException::class);

		$this->service->generateKey(self::CONSUMER_ID, []);
	}

	public function testGenerateKeyRefusesAnExpiryInThePast(): void {
		$this->givenConsumer();
		$this->expectException(InvalidArgumentException::class);

		$this->service->generateKey(self::CONSUMER_ID, ['expiresAt' => $this->at(-1)]);
	}

	public function testGenerateKeyRefusesAnUnknownConsumer(): void {
		$this->expectException(OutOfBoundsException::class);

		$this->service->generateKey('missing', []);
	}

	public function testRevokeKeyStampsOnlyThatKeyAndKeepsAnEarlierRevocation(): void {
		$this->givenConsumer([$this->key('k1'), $this->key('k2', ['revokedAt' => $this->at(-500)])]);

		$consumer = $this->service->revokeKey(self::CONSUMER_ID, 'k1');
		$this->service->revokeKey(self::CONSUMER_ID, 'k2');

		$this->assertSame($this->at(0), $consumer['keys'][0]['revokedAt']);
		$this->assertSame($this->at(-500), $this->objects[self::CONSUMER_ID]['keys'][1]['revokedAt']);
		$this->assertArrayNotHasKey('hash', $consumer['keys'][0]);
	}

	public function testRevokeKeyRefusesAnUnknownKey(): void {
		$this->givenConsumer([$this->key('k1')]);
		$this->expectException(OutOfBoundsException::class);

		$this->service->revokeKey(self::CONSUMER_ID, 'nope');
	}

	public function testDisableConsumerDisablesItsAccount(): void {
		$this->givenConsumer();
		$user = $this->givenAccount();
		$user->expects($this->once())->method('setEnabled')->with(false);

		$consumer = $this->service->disableConsumer(self::CONSUMER_ID);

		$this->assertFalse($consumer['enabled']);
	}

	public function testConsumerOfRefusesAConsumerOfAnotherCatalogue(): void {
		$this->givenConsumer();
		$this->assertSame(self::CONSUMER_ID, $this->service->consumerOf('cat-1', self::CONSUMER_ID)['id']);

		$this->expectException(OutOfBoundsException::class);
		$this->service->consumerOf('cat-2', self::CONSUMER_ID);
	}

	public function testConsumersOfListsTheCatalogueWithoutVerifiers(): void {
		$this->store->expects($this->once())->method('findWhere')
			->with('feedConsumer', ['catalog' => 'cat-1'], 1000)
			->willReturn([['id' => 'c1', 'keys' => [$this->key('k1')]]]);

		$this->assertSame([['id' => 'c1', 'keys' => [array_diff_key($this->key('k1'), ['hash' => true])]]], $this->service->consumersOf('cat-1'));
		$this->assertSame([], $this->service->consumersOf(''));
	}

	public function testAuthenticateAcceptsAValidKeyAndRecordsItsUse(): void {
		$this->givenConsumer([$this->key('k1')]);
		$user = $this->givenAccount();

		$match = $this->service->authenticate('oc_'.self::CONSUMER_ID.'.k1.secret-k1');

		$this->assertNotNull($match);
		$this->assertSame($user, $match['user']);
		$this->assertSame('cat-1', $match['consumer']['catalog']);
		$this->assertArrayNotHasKey('hash', $match['consumer']['keys'][0]);
		$this->assertSame($this->at(0), $this->objects[self::CONSUMER_ID]['keys'][0]['lastUsedAt']);
	}

	public function testAuthenticateWritesTheLastUseAtMostHourly(): void {
		$this->givenConsumer([$this->key('k1', ['lastUsedAt' => $this->at(-600)])]);
		$this->givenAccount();

		$this->assertNotNull($this->service->authenticate('oc_'.self::CONSUMER_ID.'.k1.secret-k1'));
		$this->assertSame([], $this->saved);

		$this->givenConsumer([$this->key('k1', ['lastUsedAt' => $this->at(-3600)])]);
		$this->service->authenticate('oc_'.self::CONSUMER_ID.'.k1.secret-k1');
		$this->assertSame($this->at(0), $this->saved[0]['keys'][0]['lastUsedAt']);
	}

	public function testAuthenticateAcceptsEitherOfTwoActiveKeys(): void {
		$this->givenConsumer([$this->key('old'), $this->key('new')]);
		$this->givenAccount();

		$this->assertNotNull($this->service->authenticate('oc_'.self::CONSUMER_ID.'.old.secret-old'));
		$this->assertNotNull($this->service->authenticate('oc_'.self::CONSUMER_ID.'.new.secret-new'));
	}

	/**
	 * @return array<string, array{0: array<string, mixed>, 1: string}>
	 */
	public static function refusedKeys(): array {
		return [
			'wrong secret' => [[], 'oc_'.self::CONSUMER_ID.'.k1.guess'],
			'unknown key id' => [[], 'oc_'.self::CONSUMER_ID.'.k9.secret-k1'],
			'unknown consumer' => [[], 'oc_other.k1.secret-k1'],
			'no prefix' => [[], self::CONSUMER_ID.'.k1.secret-k1'],
			'too few parts' => [[], 'oc_'.self::CONSUMER_ID.'.secret-k1'],
			'empty part' => [[], 'oc_'.self::CONSUMER_ID.'..secret-k1'],
			'revoked' => [['revokedAt' => '2020-01-01T00:00:00+00:00'], 'oc_'.self::CONSUMER_ID.'.k1.secret-k1'],
			'expired' => [['expiresAt' => '2020-01-01T00:00:00+00:00'], 'oc_'.self::CONSUMER_ID.'.k1.secret-k1'],
		];
	}

	#[\PHPUnit\Framework\Attributes\DataProvider('refusedKeys')]
	public function testAuthenticateRefuses(array $keyOverrides, string $presented): void {
		$this->givenConsumer([$this->key('k1', $keyOverrides)]);
		$this->givenAccount();

		$this->assertNull($this->service->authenticate($presented));
		$this->assertSame([], $this->saved);
	}

	public function testAuthenticateRefusesADisabledConsumer(): void {
		$this->givenConsumer([$this->key('k1')], ['enabled' => false]);
		$this->givenAccount();

		$this->assertNull($this->service->authenticate('oc_'.self::CONSUMER_ID.'.k1.secret-k1'));
	}

	public function testAuthenticateRefusesADisabledAccount(): void {
		$this->givenConsumer([$this->key('k1')]);
		$this->givenAccount(false);

		$this->assertNull($this->service->authenticate('oc_'.self::CONSUMER_ID.'.k1.secret-k1'));
	}
}
