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
 * @spec openspec/changes/operations-publish-rights/specs/publications/spec.md#requirement-the-group-exists-and-the-administrator-can-see-it-req-opr-004
 */
final class CreatePublishersGroupTest extends TestCase {

	/**
	 * REQ-OPR-004 "A repair on an existing instance": run twice, the group is
	 * created once and nobody is added to it.
	 *
	 * @return void
	 */
	public function testRunningTwiceCreatesTheGroupOnceWithoutMembers(): void {
		$exists = false;
		$created = [];
		$group = $this->createMock(IGroup::class);
		$group->expects($this->never())->method('addUser');

		$groups = $this->createMock(IGroupManager::class);
		$groups->method('groupExists')->willReturnCallback(static function (string $gid) use (&$exists): bool {
			return $exists && $gid === 'opencatalogi-publishers';
		});
		$groups->method('createGroup')->willReturnCallback(static function (string $gid) use (&$exists, &$created, $group): IGroup {
			$created[] = $gid;
			$exists = true;
			return $group;
		});

		$step = new CreatePublishersGroup($groups, $this->createMock(LoggerInterface::class));
		$step->run($this->createMock(IOutput::class));
		$step->run($this->createMock(IOutput::class));

		$this->assertSame(['opencatalogi-publishers'], $created);
	}

	public function testAnExistingGroupIsLeftAlone(): void {
		$groups = $this->createMock(IGroupManager::class);
		$groups->method('groupExists')->with('opencatalogi-publishers')->willReturn(true);
		$groups->expects($this->never())->method('createGroup');

		$output = $this->createMock(IOutput::class);
		$output->expects($this->once())->method('info')->with('The group opencatalogi-publishers exists.');

		(new CreatePublishersGroup($groups, $this->createMock(LoggerInterface::class)))->run($output);
	}

	public function testAGroupBackendThatRefusesIsReportedNotRaised(): void {
		$groups = $this->createMock(IGroupManager::class);
		$groups->method('groupExists')->willReturn(false);
		$groups->method('createGroup')->willReturn(null);

		$output = $this->createMock(IOutput::class);
		$output->expects($this->once())->method('warning')->with('The group opencatalogi-publishers could not be created.');
		$output->expects($this->never())->method('info');

		(new CreatePublishersGroup($groups, $this->createMock(LoggerInterface::class)))->run($output);
	}

	public function testAnExceptionIsLoggedAndReportedNotRaised(): void {
		$groups = $this->createMock(IGroupManager::class);
		$groups->method('groupExists')->willThrowException(new RuntimeException('LDAP down'));

		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('warning')->with($this->stringContains('LDAP down'));
		$output = $this->createMock(IOutput::class);
		$output->expects($this->once())->method('warning')->with('Could not create the group opencatalogi-publishers: LDAP down');

		(new CreatePublishersGroup($groups, $logger))->run($output);
	}

	public function testItNamesItselfForOccUpgrade(): void {
		$step = new CreatePublishersGroup($this->createMock(IGroupManager::class), $this->createMock(LoggerInterface::class));
		$this->assertSame('Create the OpenCatalogi publishers group', $step->getName());
	}
}
