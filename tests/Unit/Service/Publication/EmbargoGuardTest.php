<?php
/**
 * EmbargoGuardTest
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Test
 * @package  OCA\OpenCatalogi\Tests\Unit\Service\Publication
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenCatalogi.nl
 *
 * @spec openspec/changes/publication-schedule-guards/tasks.md#task-1.1
 */

declare(strict_types=1);

namespace OCA\OpenCatalogi\Tests\Unit\Service\Publication;

use DateTimeImmutable;
use OCA\OpenCatalogi\Service\Publication\EmbargoGuard;
use OCP\IAppConfig;
use OCP\IGroupManager;
use OCP\IUser;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use RuntimeException;

/**
 * An embargo refuses a write that would release the record early (REQ-PSG-001).
 *
 * @covers \OCA\OpenCatalogi\Service\Publication\EmbargoGuard
 */
final class EmbargoGuardTest extends TestCase {

	private const NOW = '2026-11-21T10:00:00+00:00';
	private const TOMORROW = '2026-11-22T09:00:00+00:00';

	/**
	 * The guard over a config holding $setting and a caller in $groups, or a lookup that throws.
	 *
	 * @param list<string>|null $groups  The caller's groups; null makes the lookup throw.
	 * @param string            $setting The stored lift groups.
	 *
	 * @return EmbargoGuard
	 */
	private function guard(?array $groups = ['editors'], string $setting = ''): EmbargoGuard {
		$config = $this->createMock(originalClassName: IAppConfig::class);
		$config->method('getValueString')->willReturnCallback(
			static fn (string $app, string $key, string $default = ''): string => match ($key) {
				EmbargoGuard::LIFT_GROUPS_KEY => $setting,
				default => $default,
			}
		);
		$manager = $this->createMock(originalClassName: IGroupManager::class);
		if ($groups === null) {
			$manager->method('getUserGroupIds')->willThrowException(new RuntimeException('LDAP is down'));
		} else {
			$manager->method('getUserGroupIds')->willReturn($groups);
		}

		return new EmbargoGuard(config: $config, groups: $manager, logger: new NullLogger());
	}//end guard()

	/**
	 * Run the guard at NOW for an editor.
	 *
	 * @param EmbargoGuard $guard The guard.
	 * @param mixed        $old   The stored publication date.
	 * @param mixed        $new   The new publication date.
	 *
	 * @return array|null
	 */
	private function check(EmbargoGuard $guard, mixed $old, mixed $new): ?array {
		return $guard->check(
			old: ['title' => 'Besluit', 'publicationDate' => $old],
			new: ['title' => 'Besluit', 'publicationDate' => $new],
			caller: $this->createMock(originalClassName: IUser::class),
			now: new DateTimeImmutable(self::NOW)
		);
	}//end check()

	/**
	 * An earlier date under embargo is refused with the code and the embargo moment.
	 *
	 * @return void
	 */
	public function testAnEarlierDateUnderEmbargoIsRefused(): void {
		self::assertSame(
			expected: ['code' => 'embargo-holds', 'property' => 'publicationDate', 'embargoUntil' => self::TOMORROW],
			actual: $this->check(guard: $this->guard(), old: self::TOMORROW, new: '2026-11-21T08:00:00+00:00')
		);
	}//end testAnEarlierDateUnderEmbargoIsRefused()

	/**
	 * A guard that cannot read the caller's groups refuses (fail closed).
	 *
	 * @return void
	 */
	public function testAnUnresolvableCallerIsRefused(): void {
		self::assertSame(
			expected: 'embargo-holds',
			actual: $this->check(guard: $this->guard(groups: null, setting: '["editors"]'), old: self::TOMORROW, new: self::NOW)['code']
		);

		$refusal = $this->guard()->check(
			old: ['publicationDate' => self::TOMORROW],
			new: ['publicationDate' => self::NOW],
			caller: null,
			now: new DateTimeImmutable(self::NOW)
		);
		self::assertSame(expected: 'embargo-holds', actual: $refusal['code']);
	}//end testAnUnresolvableCallerIsRefused()

	/**
	 * Clearing the date is allowed, and so is a save that leaves it alone.
	 *
	 * @return void
	 */
	public function testClearingTheDateIsAllowed(): void {
		self::assertNull(actual: $this->check(guard: $this->guard(), old: self::TOMORROW, new: null));
		self::assertNull(actual: $this->check(guard: $this->guard(), old: self::TOMORROW, new: ''));
		self::assertNull(actual: $this->check(guard: $this->guard(), old: self::TOMORROW, new: self::TOMORROW));
	}//end testClearingTheDateIsAllowed()

	/**
	 * A later date, or the same moment written another way, is allowed.
	 *
	 * @return void
	 */
	public function testALaterDateIsAllowed(): void {
		self::assertNull(actual: $this->check(guard: $this->guard(), old: self::TOMORROW, new: '2026-11-28T09:00:00+00:00'));
		self::assertNull(actual: $this->check(guard: $this->guard(), old: self::TOMORROW, new: '2026-11-22T10:00:00+01:00'));
	}//end testALaterDateIsAllowed()

	/**
	 * Without an embargo (no date, or a date already passed) any date may be set.
	 *
	 * @return void
	 */
	public function testWithoutAnEmbargoAnyDateIsAllowed(): void {
		self::assertNull(actual: $this->check(guard: $this->guard(), old: null, new: self::NOW));
		self::assertNull(actual: $this->check(guard: $this->guard(), old: '2026-11-20T09:00:00+00:00', new: '2026-11-01T09:00:00+00:00'));
	}//end testWithoutAnEmbargoAnyDateIsAllowed()

	/**
	 * A stored date that does not read as a date fails closed; so does an unreadable new date under embargo.
	 *
	 * @return void
	 */
	public function testAnUnreadableDateFailsClosed(): void {
		$refusal = $this->check(guard: $this->guard(), old: 'morgen', new: self::NOW);
		self::assertSame(expected: 'embargo-holds', actual: $refusal['code']);
		self::assertNull(actual: $refusal['embargoUntil']);

		self::assertSame(expected: 'embargo-holds', actual: $this->check(guard: $this->guard(), old: self::TOMORROW, new: 'vandaag')['code']);
	}//end testAnUnreadableDateFailsClosed()

	/**
	 * A caller in a lift group may move the date earlier; the default group is admin.
	 *
	 * @return void
	 */
	public function testALiftGroupMayMoveTheDateEarlier(): void {
		self::assertNull(actual: $this->check(guard: $this->guard(groups: ['admin']), old: self::TOMORROW, new: self::NOW));
		self::assertNull(actual: $this->check(guard: $this->guard(groups: ['woo-lead'], setting: '["woo-lead"]'), old: self::TOMORROW, new: self::NOW));
		self::assertNotNull(actual: $this->check(guard: $this->guard(groups: ['admin'], setting: '["woo-lead"]'), old: self::TOMORROW, new: self::NOW));
		self::assertNull(actual: $this->check(guard: $this->guard(groups: ['admin'], setting: 'not json'), old: self::TOMORROW, new: self::NOW));
	}//end testALiftGroupMayMoveTheDateEarlier()
}//end class
