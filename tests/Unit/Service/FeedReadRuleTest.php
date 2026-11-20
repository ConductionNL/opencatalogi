<?php

/**
 * Unit tests for FeedReadRule.
 *
 * Covers the read entries for an empty and a filled instance-wide consumer
 * list, keeping read entries the class does not own, syncing the instance
 * group, and writing the rule onto the configured OOAPI schemas. The group
 * and user managers, the app config, the container and the schema mapper are
 * mocked.
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

use OCA\OpenCatalogi\Service\FeedConsumerAccounts;
use OCA\OpenCatalogi\Service\FeedReadRule;
use OCP\IAppConfig;
use OCP\IGroup;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\IUserManager;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Unit tests for FeedReadRule.
 */
#[CoversClass(FeedReadRule::class)]
class FeedReadRuleTest extends TestCase {

	private FeedConsumerAccounts|MockObject $accounts;
	private IGroupManager|MockObject $groupManager;
	private IUserManager|MockObject $userManager;
	private IAppConfig|MockObject $config;
	private ContainerInterface|MockObject $container;
	private LoggerInterface|MockObject $logger;

	/**
	 * The schemas the fake mapper holds, by id.
	 *
	 * @var array<int, object>
	 */
	private array $schemas = [];

	/**
	 * The schema ids the fake mapper was asked to update.
	 *
	 * @var list<int>
	 */
	private array $updated = [];

	protected function setUp(): void {
		$this->accounts = $this->createMock(FeedConsumerAccounts::class);
		$this->groupManager = $this->createMock(IGroupManager::class);
		$this->userManager = $this->createMock(IUserManager::class);
		$this->config = $this->createMock(IAppConfig::class);
		$this->container = $this->createMock(ContainerInterface::class);
		$this->logger = $this->createMock(LoggerInterface::class);

		$test = $this;
		$mapper = new class ($test) {
			public function __construct(private FeedReadRuleTest $test) {
			}

			public function find(int $id, array $extend = [], bool $_rbac = true, bool $_multitenancy = true): object {
				return $this->test->schemaFor(id: $id);
			}

			public function update(object $schema): object {
				$this->test->recordUpdate(id: $schema->id);
				return $schema;
			}
		};
		$this->container->method('get')->with('OCA\OpenRegister\Db\SchemaMapper')->willReturn($mapper);
	}

	/**
	 * The fake mapper's lookup.
	 *
	 * @param int $id The schema id.
	 *
	 * @return object The schema.
	 */
	public function schemaFor(int $id): object {
		if (isset($this->schemas[$id]) === false) {
			throw new RuntimeException('Schema '.$id.' not found');
		}

		return $this->schemas[$id];
	}

	/**
	 * The fake mapper's update.
	 *
	 * @param int $id The schema id.
	 *
	 * @return void
	 */
	public function recordUpdate(int $id): void {
		$this->updated[] = $id;
	}

	private function rule(): FeedReadRule {
		return new FeedReadRule($this->accounts, $this->groupManager, $this->userManager, $this->config, $this->container, $this->logger);
	}

	private function schema(int $id, array $authorization): object {
		$schema = new class ($id, $authorization) {
			public function __construct(public int $id, private array $authorization) {
			}

			public function getAuthorization(): ?array {
				return $this->authorization;
			}

			public function setAuthorization(array $authorization): void {
				$this->authorization = $authorization;
			}
		};
		$this->schemas[$id] = $schema;
		return $schema;
	}

	/**
	 * @param array<string, string> $values The config values by key.
	 */
	private function configure(array $values): void {
		$this->config->method('getValueString')->willReturnCallback(
			static fn (string $app, string $key, string $default = ''): string => ($values[$key] ?? $default)
		);
	}

	public function testAnEmptyInstanceListKeepsEverySignedInAccount(): void {
		$this->assertSame(['authenticated'], FeedReadRule::readEntries(everyAccount: true));
	}

	public function testAFilledInstanceListAdmitsTheCatalogueConsumersAndTheInstanceGroup(): void {
		$entries = FeedReadRule::readEntries(everyAccount: false);

		$this->assertSame(
			[
				[
					'group' => 'authenticated',
					'match' => [
						'catalog' => [
							'$memberOf' => [
								'schema' => 'feedConsumer',
								'register' => 'publication',
								'property' => 'catalog',
								'match' => 'account',
								'where' => ['enabled' => true],
							],
						],
					],
				],
				'opencatalogi-ooapi-consumers',
			],
			$entries
		);
	}

	public function testOnlyTheOwnedEntriesAreReplaced(): void {
		$staff = ['group' => 'catalog-staff', 'match' => ['catalog' => 'c-1']];
		$current = ['read' => ['authenticated', $staff, 'opencatalogi-ooapi-consumers'], 'create' => ['admin']];

		$next = FeedReadRule::authorizationFor(authorization: $current, everyAccount: false);

		$this->assertSame(['admin'], $next['create']);
		$this->assertSame([$staff, FeedReadRule::consumerRule(), 'opencatalogi-ooapi-consumers'], $next['read']);
		$this->assertSame($next, FeedReadRule::authorizationFor(authorization: $next, everyAccount: false));
	}

	public function testEmptyingTheListRestoresAuthenticated(): void {
		$current = ['read' => [FeedReadRule::consumerRule(), 'opencatalogi-ooapi-consumers']];

		$this->assertSame(['read' => ['authenticated']], FeedReadRule::authorizationFor(authorization: $current, everyAccount: true));
	}

	public function testSyncGroupAddsListedAccountsAndRemovesOthers(): void {
		$alice = $this->createMock(IUser::class);
		$alice->method('getUID')->willReturn('alice');
		$mallory = $this->createMock(IUser::class);
		$mallory->method('getUID')->willReturn('mallory');
		$bob = $this->createMock(IUser::class);
		$bob->method('getUID')->willReturn('bob');

		$group = $this->createMock(IGroup::class);
		$group->method('getUsers')->willReturn([$alice, $mallory]);
		$group->method('inGroup')->willReturnCallback(static fn (IUser $user): bool => $user === $alice);
		$group->expects($this->once())->method('removeUser')->with($mallory);
		$group->expects($this->once())->method('addUser')->with($bob);
		$this->groupManager->method('get')->with('opencatalogi-ooapi-consumers')->willReturn($group);
		$this->groupManager->expects($this->never())->method('createGroup');
		$this->userManager->method('get')->willReturnMap([['alice', $alice], ['bob', $bob], ['ghost', null]]);

		$this->rule()->syncGroup(uids: ['alice', 'bob', 'ghost']);
	}

	public function testSyncGroupCreatesTheGroupOnce(): void {
		$group = $this->createMock(IGroup::class);
		$group->method('getUsers')->willReturn([]);
		$this->groupManager->method('get')->willReturn(null);
		$this->groupManager->expects($this->once())->method('createGroup')->with('opencatalogi-ooapi-consumers')->willReturn($group);
		$this->userManager->method('get')->willReturn(null);

		$this->rule()->syncGroup(uids: ['nobody']);
	}

	public function testSyncGroupRefusesWhenTheGroupCannotBeCreated(): void {
		$this->groupManager->method('get')->willReturn(null);
		$this->groupManager->method('createGroup')->willReturn(null);

		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('The instance consumer group could not be created');
		$this->rule()->syncGroup(uids: ['alice']);
	}

	public function testApplyWritesTheRuleOntoEachConfiguredSchemaOnce(): void {
		$this->configure(['ooapi_courses_schema' => '11', 'ooapi_programs_schema' => '12', 'ooapi_offerings_schema' => '11']);
		$this->accounts->method('instanceAccounts')->willReturn(['accounts' => ['alice'], 'everyAccount' => false, 'setting' => 'ooapi_consumers']);
		$group = $this->createMock(IGroup::class);
		$group->method('getUsers')->willReturn([]);
		$this->groupManager->method('get')->willReturn($group);
		$this->userManager->method('get')->willReturn(null);
		$courses = $this->schema(11, ['read' => ['authenticated']]);
		$programs = $this->schema(12, ['read' => ['authenticated']]);

		$this->assertSame(2, $this->rule()->apply());
		$this->assertSame([11, 12], $this->updated);
		$this->assertSame(FeedReadRule::readEntries(everyAccount: false), $courses->getAuthorization()['read']);
		$this->assertSame(FeedReadRule::readEntries(everyAccount: false), $programs->getAuthorization()['read']);
	}

	public function testApplyWithAnEmptyListLeavesTheGroupAndAnUnchangedSchemaAlone(): void {
		$this->configure(['ooapi_courses_schema' => '11']);
		$this->accounts->method('instanceAccounts')->willReturn(['accounts' => [], 'everyAccount' => true, 'setting' => 'ooapi_consumers']);
		$this->groupManager->expects($this->never())->method('get');
		$this->schema(11, ['read' => ['authenticated']]);

		$this->assertSame(0, $this->rule()->apply());
		$this->assertSame([], $this->updated);
	}

	public function testApplySkipsASchemaThatCannotBeRead(): void {
		$this->configure(['ooapi_courses_schema' => '99', 'ooapi_programs_schema' => '', 'ooapi_offerings_schema' => 'x']);
		$this->accounts->method('instanceAccounts')->willReturn(['accounts' => [], 'everyAccount' => true, 'setting' => 'ooapi_consumers']);
		$this->logger->expects($this->once())->method('warning')->with($this->stringContains('schema 99 could not be read'));

		$this->assertSame(0, $this->rule()->apply());
		$this->assertSame([], $this->updated);
	}
}
