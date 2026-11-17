<?php

/**
 * Unit tests for FeedConsumerAccounts.
 *
 * Covers creating a consumer account in its catalogue's consumer group (new
 * and existing group, refused account or group), finding an account, and
 * reading the instance-wide account list. The account and group managers,
 * the random source and the app config are mocked.
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
use OCP\IAppConfig;
use OCP\IGroup;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\IUserManager;
use OCP\Security\ISecureRandom;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Unit tests for FeedConsumerAccounts.
 */
#[CoversClass(FeedConsumerAccounts::class)]
class FeedConsumerAccountsTest extends TestCase {

	private IUserManager|MockObject $userManager;
	private IGroupManager|MockObject $groupManager;
	private IAppConfig|MockObject $appConfig;
	private FeedConsumerAccounts $accounts;

	protected function setUp(): void {
		$this->userManager = $this->createMock(IUserManager::class);
		$this->groupManager = $this->createMock(IGroupManager::class);
		$this->appConfig = $this->createMock(IAppConfig::class);

		$random = $this->createMock(ISecureRandom::class);
		$random->method('generate')->willReturnCallback(
			static fn (int $length): string => $length === 6 ? 'abc123' : str_repeat('p', $length)
		);

		$this->accounts = new FeedConsumerAccounts($this->userManager, $this->groupManager, $random, $this->appConfig);
	}

	public function testCreateMakesASluggedAccountAndCreatesTheCatalogueGroup(): void {
		$user = $this->createMock(IUser::class);
		$this->userManager->expects($this->once())->method('createUser')
			->with('feed-studiekeuze-portaal-abc123', str_repeat('p', 64))
			->willReturn($user);
		$group = $this->createMock(IGroup::class);
		$group->expects($this->once())->method('addUser')->with($user);
		$this->groupManager->method('get')->with('opencatalogi-feed-hva-onderwijs')->willReturn(null);
		$this->groupManager->expects($this->once())->method('createGroup')->with('opencatalogi-feed-hva-onderwijs')->willReturn($group);

		$this->assertSame($user, $this->accounts->create('Studiekeuze Portaal!', 'hva-onderwijs'));
	}

	public function testCreateReusesAnExistingGroup(): void {
		$user = $this->createMock(IUser::class);
		$this->userManager->method('createUser')->willReturn($user);
		$group = $this->createMock(IGroup::class);
		$group->expects($this->once())->method('addUser')->with($user);
		$this->groupManager->method('get')->willReturn($group);
		$this->groupManager->expects($this->never())->method('createGroup');

		$this->accounts->create('X', 'hva');
	}

	public function testANameWithoutLettersOrDigitsGetsOnlyTheSuffix(): void {
		$user = $this->createMock(IUser::class);
		$this->userManager->expects($this->once())->method('createUser')
			->with('feed-abc123', $this->anything())
			->willReturn($user);
		$this->groupManager->method('get')->willReturn($this->createMock(IGroup::class));

		$this->accounts->create('***', 'hva');
	}

	public function testCreateFailsWhenTheAccountIsRefused(): void {
		$this->userManager->method('createUser')->willReturn(false);
		$this->groupManager->expects($this->never())->method('get');
		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('The consumer account could not be created');

		$this->accounts->create('X', 'hva');
	}

	public function testCreateFailsWhenTheGroupCannotBeCreated(): void {
		$this->userManager->method('createUser')->willReturn($this->createMock(IUser::class));
		$this->groupManager->method('get')->willReturn(null);
		$this->groupManager->method('createGroup')->willReturn(null);
		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('The catalogue consumer group could not be created');

		$this->accounts->create('X', 'hva');
	}

	public function testFindReturnsTheAccountOrNull(): void {
		$user = $this->createMock(IUser::class);
		$this->userManager->method('get')->willReturnMap([['feed-x', $user], ['nobody', null]]);

		$this->assertSame($user, $this->accounts->find('feed-x'));
		$this->assertNull($this->accounts->find('nobody'));
	}

	public function testInstanceAccountsListsTheTrimmedConfiguredAccounts(): void {
		$this->appConfig->method('getValueString')->with('opencatalogi', 'ooapi_consumers', '')->willReturn(' surf , ,edu ');

		$this->assertSame(
			['accounts' => ['surf', 'edu'], 'everyAccount' => false, 'setting' => 'ooapi_consumers'],
			$this->accounts->instanceAccounts()
		);
	}

	public function testInstanceAccountsSaysEveryAccountReadsWhenTheListIsEmpty(): void {
		$this->appConfig->method('getValueString')->willReturn('  ');

		$this->assertSame(
			['accounts' => [], 'everyAccount' => true, 'setting' => 'ooapi_consumers'],
			$this->accounts->instanceAccounts()
		);
	}
}
