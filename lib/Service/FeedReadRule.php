<?php

/**
 * OpenCatalogi FeedReadRule.
 *
 * Writes who may read the OOAPI course, program and offering objects onto
 * their schemas (integration-feed-consumer-credentials, task 3.1): the feed
 * consumers of the catalogue an object belongs to, plus the accounts on the
 * instance-wide consumer list.
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
 * @link https://www.OpenCatalogi.nl
 *
 * @spec openspec/changes/integration-feed-consumer-credentials/tasks.md#3-read-rule-and-screen
 */

declare(strict_types=1);

namespace OCA\OpenCatalogi\Service;

use OCP\IAppConfig;
use OCP\IGroup;
use OCP\IGroupManager;
use OCP\IUserManager;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Keeps the read rule of the OOAPI schemas in step with the feed consumers.
 *
 * The rule has two alternatives:
 *
 * - a `$memberOf` rule over the `feedConsumer` objects: a signed-in caller
 *   reads an object when an enabled consumer acting as that caller belongs to
 *   the object's `catalog`, so a consumer reads its own catalogue only;
 * - the group {@see self::INSTANCE_GROUP}, whose members are kept equal to the
 *   instance-wide consumer list.
 *
 * An empty instance-wide list means every signed-in account (REQ-FCC-004, as
 * `OoapiService::isConsumerAllowed()` reads it), so the rule is then
 * `authenticated`, as before this change.
 *
 * Only the entries this class writes are replaced; any other read entry on the
 * schema (such as a catalogue's staff rule) is kept.
 *
 * @spec openspec/changes/integration-feed-consumer-credentials/specs/ooapi-catalog-publication/spec.md#requirement-a-key-reads-only-its-catalogues-protected-feeds-req-fcc-002
 * @spec openspec/changes/integration-feed-consumer-credentials/specs/ooapi-catalog-publication/spec.md#requirement-account-based-access-keeps-working-and-is-visible-req-fcc-004
 */
class FeedReadRule {

	/**
	 * The Nextcloud group that holds the accounts on the instance-wide consumer list.
	 */
	public const INSTANCE_GROUP = 'opencatalogi-ooapi-consumers';

	/**
	 * The settings contexts of the OOAPI schemas, read as `<context>_schema`.
	 */
	public const CONTEXTS = ['ooapi_courses', 'ooapi_programs', 'ooapi_offerings'];

	/**
	 * The register that holds the `feedConsumer` schema.
	 */
	public const CONSUMER_REGISTER = 'publication';

	/**
	 * The OpenRegister schema mapper, resolved lazily: OpenRegister is optional.
	 */
	private const SCHEMA_MAPPER = 'OCA\OpenRegister\Db\SchemaMapper';

	/**
	 * Constructor.
	 *
	 * @param FeedConsumerAccounts $accounts     Reads the instance-wide consumer list.
	 * @param IGroupManager        $groupManager Holds the instance group.
	 * @param IUserManager         $userManager  Resolves the listed accounts.
	 * @param IAppConfig           $config       Names the OOAPI schemas.
	 * @param ContainerInterface   $container    Resolves the OpenRegister schema mapper.
	 * @param LoggerInterface      $logger       PSR logger.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly FeedConsumerAccounts $accounts,
		private readonly IGroupManager $groupManager,
		private readonly IUserManager $userManager,
		private readonly IAppConfig $config,
		private readonly ContainerInterface $container,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * The read entries this class writes, for an instance-wide list.
	 *
	 * @param bool $everyAccount True when the instance-wide list is empty, which admits every signed-in account.
	 *
	 * @return array<int, mixed> The read entries.
	 *
	 * @spec openspec/changes/integration-feed-consumer-credentials/specs/ooapi-catalog-publication/spec.md#requirement-a-key-reads-only-its-catalogues-protected-feeds-req-fcc-002
	 */
	public static function readEntries(bool $everyAccount): array {
		if ($everyAccount === true) {
			return ['authenticated'];
		}

		return [self::consumerRule(), self::INSTANCE_GROUP];
	}//end readEntries()

	/**
	 * The rule that admits an enabled consumer account to the objects of its own catalogue.
	 *
	 * @return array<string, mixed> The conditional read rule.
	 *
	 * @spec openspec/changes/integration-feed-consumer-credentials/specs/ooapi-catalog-publication/spec.md#requirement-a-key-reads-only-its-catalogues-protected-feeds-req-fcc-002
	 */
	public static function consumerRule(): array {
		return [
			'group' => 'authenticated',
			'match' => [
				'catalog' => [
					'$memberOf' => [
						'schema' => FeedConsumerService::SCHEMA,
						'register' => self::CONSUMER_REGISTER,
						'property' => 'catalog',
						'match' => 'account',
						'where' => ['enabled' => true],
					],
				],
			],
		];
	}//end consumerRule()

	/**
	 * Replace the entries this class owns in a schema's authorization, keeping the rest.
	 *
	 * @param array<string, mixed> $authorization The schema's current authorization.
	 * @param bool                 $everyAccount  True when the instance-wide list is empty.
	 *
	 * @return array<string, mixed> The new authorization.
	 *
	 * @spec openspec/changes/integration-feed-consumer-credentials/specs/ooapi-catalog-publication/spec.md#requirement-a-key-reads-only-its-catalogues-protected-feeds-req-fcc-002
	 */
	public static function authorizationFor(array $authorization, bool $everyAccount): array {
		$kept = array_values(
			array_filter(
				(array)($authorization['read'] ?? []),
				static fn (mixed $entry): bool => self::isOwned(entry: $entry) === false
			)
		);

		$authorization['read'] = array_merge($kept, self::readEntries(everyAccount: $everyAccount));
		return $authorization;
	}//end authorizationFor()

	/**
	 * Write the rule onto every configured OOAPI schema and the instance list into its group.
	 *
	 * @return int The number of schemas whose rule changed.
	 *
	 * @spec openspec/changes/integration-feed-consumer-credentials/specs/ooapi-catalog-publication/spec.md#requirement-account-based-access-keeps-working-and-is-visible-req-fcc-004
	 */
	public function apply(): int {
		$instance = $this->accounts->instanceAccounts();
		$everyAccount = ($instance['everyAccount'] === true);
		if ($everyAccount === false) {
			$this->syncGroup(uids: $instance['accounts']);
		}

		$changed = 0;
		foreach ($this->schemaIds() as $schemaId) {
			$changed += (int)$this->applyTo(schemaId: $schemaId, everyAccount: $everyAccount);
		}

		return $changed;
	}//end apply()

	/**
	 * Make the instance group hold exactly the listed accounts that exist.
	 *
	 * @param array<int, string> $uids The instance-wide consumer list.
	 *
	 * @return void
	 *
	 * @throws RuntimeException When the group cannot be created.
	 *
	 * @spec openspec/changes/integration-feed-consumer-credentials/specs/ooapi-catalog-publication/spec.md#requirement-account-based-access-keeps-working-and-is-visible-req-fcc-004
	 */
	public function syncGroup(array $uids): void {
		$group = ($this->groupManager->get(self::INSTANCE_GROUP) ?? $this->groupManager->createGroup(self::INSTANCE_GROUP));
		if ($group instanceof IGroup === false) {
			throw new RuntimeException('The instance consumer group could not be created');
		}

		$listed = array_flip($uids);
		foreach ($group->getUsers() as $member) {
			if (isset($listed[$member->getUID()]) === false) {
				$group->removeUser($member);
			}
		}

		foreach ($uids as $uid) {
			$user = $this->userManager->get($uid);
			if ($user !== null && $group->inGroup($user) === false) {
				$group->addUser($user);
			}
		}
	}//end syncGroup()

	/**
	 * Write the rule onto one schema.
	 *
	 * @param int  $schemaId     The schema id.
	 * @param bool $everyAccount True when the instance-wide list is empty.
	 *
	 * @return bool True when the schema changed.
	 */
	private function applyTo(int $schemaId, bool $everyAccount): bool {
		try {
			$mapper = $this->container->get(self::SCHEMA_MAPPER);
			$schema = $mapper->find($schemaId, [], _rbac: false, _multitenancy: false);
		} catch (\Throwable $e) {
			$this->logger->warning('[FeedReadRule] schema '.$schemaId.' could not be read: '.$e->getMessage());
			return false;
		}

		$current = ($schema->getAuthorization() ?? []);
		$next = self::authorizationFor(authorization: $current, everyAccount: $everyAccount);
		if ($next === $current) {
			return false;
		}

		$schema->setAuthorization($next);
		$mapper->update($schema);
		return true;
	}//end applyTo()

	/**
	 * The configured OOAPI schema ids.
	 *
	 * @return array<int, int> The ids, without duplicates.
	 */
	private function schemaIds(): array {
		$ids = [];
		foreach (self::CONTEXTS as $context) {
			$id = (int)$this->config->getValueString('opencatalogi', $context.'_schema', '');
			if ($id > 0) {
				$ids[] = $id;
			}
		}

		return array_values(array_unique($ids));
	}//end schemaIds()

	/**
	 * Whether a read entry is one this class writes.
	 *
	 * @param mixed $entry A read entry.
	 *
	 * @return bool True for `authenticated`, the instance group and the consumer rule.
	 */
	private static function isOwned(mixed $entry): bool {
		if ($entry === 'authenticated' || $entry === self::INSTANCE_GROUP) {
			return true;
		}

		return is_array($entry) === true
			&& (($entry['match']['catalog']['$memberOf']['schema'] ?? null) === FeedConsumerService::SCHEMA);
	}//end isOwned()
}//end class
