<?php

/**
 * OpenCatalogi Feed Consumer Accounts.
 *
 * The Nextcloud accounts feed consumers act as (integration-feed-consumer-credentials):
 * creating one in its catalogue's consumer group, finding one, and the
 * instance-wide account list the OOAPI feed also accepts.
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

use OCP\IAppConfig;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\IUserManager;
use OCP\Security\ISecureRandom;
use RuntimeException;

/**
 * Creates and finds the accounts feed consumers act as.
 *
 * @spec openspec/changes/integration-feed-consumer-credentials/specs/ooapi-catalog-publication/spec.md#requirement-an-administrator-issues-a-key-to-a-consumer-for-one-catalogue-req-fcc-001
 */
class FeedConsumerAccounts {

	/**
	 * The prefix of the Nextcloud account a consumer acts as.
	 */
	public const ACCOUNT_PREFIX = 'feed-';

	/**
	 * The prefix of a catalogue's consumer group.
	 */
	public const GROUP_PREFIX = 'opencatalogi-feed-';

	/**
	 * The app config key holding the instance-wide consumer accounts.
	 */
	public const INSTANCE_SETTING = 'ooapi_consumers';

	/**
	 * Constructor.
	 *
	 * @param IUserManager  $userManager  Creates and finds the consumer accounts.
	 * @param IGroupManager $groupManager The catalogue consumer groups.
	 * @param ISecureRandom $random       Account name suffixes and passwords.
	 * @param IAppConfig    $appConfig    Reads the instance-wide consumer list.
	 */
	public function __construct(
		private readonly IUserManager $userManager,
		private readonly IGroupManager $groupManager,
		private readonly ISecureRandom $random,
		private readonly IAppConfig $appConfig,
	) {

	}//end __construct()

	/**
	 * Create the consumer's account with a random password nobody sees, in the
	 * catalogue's consumer group.
	 *
	 * @param string $name        The consumer name.
	 * @param string $catalogSlug The catalogue slug.
	 *
	 * @return IUser The account.
	 *
	 * @throws RuntimeException When the account or the group cannot be created.
	 *
	 * @spec openspec/changes/integration-feed-consumer-credentials/specs/ooapi-catalog-publication/spec.md#requirement-an-administrator-issues-a-key-to-a-consumer-for-one-catalogue-req-fcc-001
	 */
	public function create(string $name, string $catalogSlug): IUser {
		$user = $this->createAccount(name: $name);
		$this->addToCatalogGroup(user: $user, catalogSlug: $catalogSlug);
		return $user;
	}//end create()

	/**
	 * The account with this uid, or null.
	 *
	 * @param string $uid The account uid.
	 *
	 * @return IUser|null
	 *
	 * @spec openspec/changes/integration-feed-consumer-credentials/specs/ooapi-catalog-publication/spec.md#requirement-a-key-reads-only-its-catalogues-protected-feeds-req-fcc-002
	 */
	public function find(string $uid): ?IUser {
		return $this->userManager->get($uid);
	}//end find()

	/**
	 * The instance-wide account list the OOAPI feed also accepts.
	 *
	 * @return array{accounts: list<string>, everyAccount: bool, setting: string}
	 *
	 * @spec openspec/changes/integration-feed-consumer-credentials/specs/ooapi-catalog-publication/spec.md#requirement-account-based-access-keeps-working-and-is-visible-req-fcc-004
	 */
	public function instanceAccounts(): array {
		$configured = trim($this->appConfig->getValueString('opencatalogi', self::INSTANCE_SETTING, ''));
		$accounts = [];
		if ($configured !== '') {
			$accounts = array_values(array_filter(array_map('trim', explode(',', $configured)), static fn (string $uid): bool => $uid !== ''));
		}

		return [
			'accounts' => $accounts,
			'everyAccount' => $configured === '',
			'setting' => self::INSTANCE_SETTING,
		];
	}//end instanceAccounts()

	/**
	 * Create the consumer's account with a random password nobody sees.
	 *
	 * @param string $name The consumer name.
	 *
	 * @return IUser The account.
	 *
	 * @throws RuntimeException When the account cannot be created.
	 */
	private function createAccount(string $name): IUser {
		$slug = trim((string)preg_replace('/[^a-z0-9]+/', '-', strtolower($name)), '-');
		$suffix = $this->random->generate(6, ISecureRandom::CHAR_LOWER.ISecureRandom::CHAR_DIGITS);
		$uid = self::ACCOUNT_PREFIX.substr($slug, 0, 40).'-'.$suffix;
		if ($slug === '') {
			$uid = self::ACCOUNT_PREFIX.$suffix;
		}

		$user = $this->userManager->createUser($uid, $this->random->generate(64, ISecureRandom::CHAR_ALPHANUMERIC.ISecureRandom::CHAR_SYMBOLS));
		if ($user instanceof IUser === false) {
			throw new RuntimeException('The consumer account could not be created');
		}

		return $user;
	}//end createAccount()

	/**
	 * Put the account in the catalogue's consumer group, creating the group when needed.
	 *
	 * @param IUser  $user        The account.
	 * @param string $catalogSlug The catalogue slug.
	 *
	 * @return void
	 *
	 * @throws RuntimeException When the group cannot be created.
	 */
	private function addToCatalogGroup(IUser $user, string $catalogSlug): void {
		$groupId = self::GROUP_PREFIX.$catalogSlug;
		$group = $this->groupManager->get($groupId) ?? $this->groupManager->createGroup($groupId);
		if ($group === null) {
			throw new RuntimeException('The catalogue consumer group could not be created');
		}

		$group->addUser($user);
	}//end addToCatalogGroup()
}//end class
