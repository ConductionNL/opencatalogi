<?php

/**
 * Unit tests for PublishRight.
 *
 * @category Test
 * @package  OCA\OpenCatalogi\Tests
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://www.OpenCatalogi.nl
 */

declare(strict_types=1);

namespace Unit\Service\Publication;

use OCA\OpenCatalogi\Service\Publication\PublishRight;
use OCP\IGroup;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\OpenCatalogi\Service\Publication\PublishRight
 *
 * @spec openspec/changes/operations-publish-rights/specs/publications/spec.md
 */
class PublishRightTest extends TestCase {

	private IUserSession&MockObject $session;

	private IGroupManager&MockObject $groups;

	protected function setUp(): void {
		parent::setUp();
		$this->session = $this->createMock(IUserSession::class);
		$this->groups = $this->createMock(IGroupManager::class);
	}

	/**
	 * Sign in a user.
	 *
	 * @param string $uid The user id.
	 *
	 * @return void
	 */
	private function signIn(string $uid): void {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($uid);
		$this->session->method('getUser')->willReturn($user);
	}

	private function right(): PublishRight {
		return new PublishRight($this->session, $this->groups);
	}

	public function testTheGroupIsTheOneTheSchemaNames(): void {
		$this->assertSame('opencatalogi-publishers', PublishRight::GROUP);
	}

	public function testNoSessionMayNotPublish(): void {
		$this->session->method('getUser')->willReturn(null);
		$this->groups->expects($this->never())->method('isInGroup');
		$this->assertFalse($this->right()->mayPublish());
	}

	public function testAnAdministratorMayPublishOutsideTheGroup(): void {
		$this->signIn('admin');
		$this->groups->method('isAdmin')->with('admin')->willReturn(true);
		$this->groups->expects($this->never())->method('isInGroup');
		$this->assertTrue($this->right()->mayPublish());
	}

	public function testAPublisherMayPublish(): void {
		$this->signIn('uitgever');
		$this->groups->method('isAdmin')->willReturn(false);
		$this->groups->expects($this->once())->method('isInGroup')->with('uitgever', 'opencatalogi-publishers')->willReturn(true);
		$this->assertTrue($this->right()->mayPublish());
	}

	public function testAnEditorOutsideTheGroupMayNotPublish(): void {
		$this->signIn('redacteur');
		$this->groups->method('isAdmin')->willReturn(false);
		$this->groups->method('isInGroup')->with('redacteur', 'opencatalogi-publishers')->willReturn(false);
		$this->assertFalse($this->right()->mayPublish());
	}

	public function testAMissingGroupReadsAsAbsentWithNoMembers(): void {
		$this->groups->method('get')->with('opencatalogi-publishers')->willReturn(null);
		$this->assertSame(['group' => 'opencatalogi-publishers', 'exists' => false, 'members' => 0], $this->right()->groupStatus());
	}

	public function testAnExistingGroupReportsItsMemberCount(): void {
		$group = $this->createMock(IGroup::class);
		$group->method('count')->willReturn(3);
		$this->groups->method('get')->with('opencatalogi-publishers')->willReturn($group);
		$this->assertSame(['group' => 'opencatalogi-publishers', 'exists' => true, 'members' => 3], $this->right()->groupStatus());
	}

	public function testAGroupBackendThatCannotCountReadsAsNoMembers(): void {
		$group = $this->createMock(IGroup::class);
		$group->method('count')->willReturn(false);
		$this->groups->method('get')->willReturn($group);
		$this->assertSame(0, $this->right()->groupStatus()['members']);
	}
}
