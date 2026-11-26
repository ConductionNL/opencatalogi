<?php

/**
 * Unit tests for the CreatePublishersGroup repair step.
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
 */

declare(strict_types=1);

namespace Unit\Repair;

use OCA\OpenCatalogi\Repair\CreatePublishersGroup;
use OCP\IGroup;
use OCP\IGroupManager;
use OCP\Migration\IOutput;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * @covers \OCA\OpenCatalogi\Repair\CreatePublishersGroup
 *
 * @spec openspec/changes/archive/2026-11-24-operations-publish-rights/specs/publications/spec.md#requirement-the-group-exists-and-the-administrator-can-see-it-req-opr-004
 */
final class CreatePublishersGroupTest extends TestCase {

	/**
	 * REQ-OPR-004 "A repair on an existing instance": run twice, each group is
	 * created once and nobody is added to it.
	 *
	 * @return void
	 */
	public function testRunningTwiceCreatesEachGroupOnceWithoutMembers(): void {
		$existing = [];
		$created = [];
		$group = $this->createMock(IGroup::class);
		$group->expects($this->never())->method('addUser');

		$groups = $this->createMock(IGroupManager::class);
		$groups->method('groupExists')->willReturnCallback(static function (string $gid) use (&$existing): bool {
			return in_array($gid, $existing, true);
		});
		$groups->method('createGroup')->willReturnCallback(static function (string $gid) use (&$existing, &$created, $group): IGroup {
			$created[] = $gid;
			$existing[] = $gid;
			return $group;
		});

		$step = new CreatePublishersGroup($groups, $this->createMock(LoggerInterface::class));
		$step->run($this->createMock(IOutput::class));
		$step->run($this->createMock(IOutput::class));

		$this->assertSame(expected: ['opencatalogi-publishers', 'opencatalogi-editors'], actual: $created);
	}

	/**
	 * Decision 25 Nov 2026 21:50 CET: an instance that has the publishers group gets the editors group.
	 *
	 * @return void
	 */
	public function testAnExistingPublishersGroupIsLeftAloneAndTheEditorsGroupAdded(): void {
		$groups = $this->createMock(IGroupManager::class);
		$groups->method('groupExists')->willReturnCallback(static fn (string $gid): bool => $gid === 'opencatalogi-publishers');
		$editors = $this->createMock(originalClassName: IGroup::class);
		$groups->expects($this->once())->method('createGroup')->with('opencatalogi-editors')->willReturn($editors);

		$info = [];
		$output = $this->createMock(IOutput::class);
		$output->method('info')->willReturnCallback(static function (string $message) use (&$info): void {
			$info[] = $message;
		});

		(new CreatePublishersGroup($groups, $this->createMock(LoggerInterface::class)))->run($output);

		$this->assertSame(
			expected: ['The group opencatalogi-publishers exists.', 'Created the group opencatalogi-editors.'],
			actual: $info
		);
	}

	/**
	 * A group backend that refuses is reported per group, not raised.
	 *
	 * @return void
	 */
	public function testAGroupBackendThatRefusesIsReportedNotRaised(): void {
		$groups = $this->createMock(IGroupManager::class);
		$groups->method('groupExists')->willReturn(false);
		$groups->method('createGroup')->willReturn(null);

		$warnings = [];
		$output = $this->createMock(IOutput::class);
		$output->method('warning')->willReturnCallback(static function (string $message) use (&$warnings): void {
			$warnings[] = $message;
		});
		$output->expects($this->never())->method('info');

		(new CreatePublishersGroup($groups, $this->createMock(LoggerInterface::class)))->run($output);

		$this->assertSame(
			expected: ['The group opencatalogi-publishers could not be created.', 'The group opencatalogi-editors could not be created.'],
			actual: $warnings
		);
	}

	/**
	 * An exception is logged and reported, and the next group is still tried.
	 *
	 * @return void
	 */
	public function testAnExceptionIsLoggedAndReportedNotRaised(): void {
		$groups = $this->createMock(IGroupManager::class);
		$groups->method('groupExists')->willThrowException(new RuntimeException('LDAP down'));

		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->exactly(count: 2))->method('warning')->with($this->stringContains(string: 'LDAP down'));
		$warnings = [];
		$output = $this->createMock(IOutput::class);
		$output->method('warning')->willReturnCallback(static function (string $message) use (&$warnings): void {
			$warnings[] = $message;
		});

		(new CreatePublishersGroup($groups, $logger))->run($output);

		$this->assertSame(
			expected: ['Could not create the group opencatalogi-publishers: LDAP down', 'Could not create the group opencatalogi-editors: LDAP down'],
			actual: $warnings
		);
	}
}
