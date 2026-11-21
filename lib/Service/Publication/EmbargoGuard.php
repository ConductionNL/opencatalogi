<?php
/**
 * Embargo guard
 *
 * A publication is under embargo while its stored `publicationDate` lies after
 * now. A write that moves that date earlier releases the record early, so it
 * is refused for a caller without the right to lift an embargo. Moving the
 * date later and clearing it are allowed.
 *
 * @category Service
 * @package  OCA\OpenCatalogi\Service\Publication
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
 * @spec openspec/changes/publication-schedule-guards/specs/publications/spec.md#requirement-an-embargo-refuses-every-write-that-would-release-the-record-early-req-psg-001
 */

declare(strict_types=1);

namespace OCA\OpenCatalogi\Service\Publication;

use DateTimeImmutable;
use OCP\IAppConfig;
use OCP\IGroupManager;
use OCP\IUser;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Decides whether a write would release a publication under embargo early.
 *
 * @spec openspec/changes/publication-schedule-guards/specs/publications/spec.md#requirement-an-embargo-refuses-every-write-that-would-release-the-record-early-req-psg-001
 */
class EmbargoGuard {

	/**
	 * The refusal code.
	 */
	public const CODE = 'embargo-holds';

	/**
	 * The property the embargo is read from.
	 */
	public const PROPERTY = 'publicationDate';

	/**
	 * The app config key listing the groups that may lift an embargo.
	 */
	public const LIFT_GROUPS_KEY = 'publication_embargo_lift_groups';

	/**
	 * The groups that may lift an embargo when the setting is absent.
	 */
	public const DEFAULT_LIFT_GROUPS = ['admin'];

	/**
	 * Constructor.
	 *
	 * @param IAppConfig      $config The app config holding the lift groups.
	 * @param IGroupManager   $groups Resolves the caller's groups.
	 * @param LoggerInterface $logger Logs a lookup that fails.
	 */
	public function __construct(
		private readonly IAppConfig $config,
		private readonly IGroupManager $groups,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * The refusal for a write from $old to $new by $caller, or null when it is allowed.
	 *
	 * Fails closed: a stored or new date that does not read as a date, a caller
	 * that cannot be resolved, or groups that cannot be read all refuse a write
	 * that changes the date of a publication under embargo.
	 *
	 * @param array<string, mixed>   $old    The publication as stored.
	 * @param array<string, mixed>   $new    The publication as it would be stored.
	 * @param IUser|null             $caller The writing user, or null outside a session.
	 * @param DateTimeImmutable|null $now    The moment to judge at; now when omitted.
	 *
	 * @return array{code: string, property: string, embargoUntil: string|null}|null
	 *
	 * @spec openspec/changes/publication-schedule-guards/tasks.md#task-1.1
	 */
	public function check(array $old, array $new, ?IUser $caller, ?DateTimeImmutable $now = null): ?array {
		if (self::releasesEarly(old: $old, new: $new, now: ($now ?? new DateTimeImmutable())) === false
			|| $this->mayLift(caller: $caller) === true
		) {
			return null;
		}

		return [
			'code' => self::CODE,
			'property' => self::PROPERTY,
			'embargoUntil' => self::moment(value: ($old[self::PROPERTY] ?? null))?->format(DATE_ATOM),
		];
	}//end check()

	/**
	 * Whether the write changes the date of a publication under embargo to an earlier or unreadable one.
	 *
	 * @param array<string, mixed> $old The publication as stored.
	 * @param array<string, mixed> $new The publication as it would be stored.
	 * @param DateTimeImmutable    $now The moment to judge at.
	 *
	 * @return bool
	 */
	private static function releasesEarly(array $old, array $new, DateTimeImmutable $now): bool {
		$stored = ($old[self::PROPERTY] ?? null);
		$next = ($new[self::PROPERTY] ?? null);
		if (self::isEmpty(value: $stored) === true || self::isEmpty(value: $next) === true || $next === $stored) {
			return false;
		}

		$until = self::moment(value: $stored);
		if ($until === null) {
			return true;
		}

		$nextMoment = self::moment(value: $next);

		return $until > $now && ($nextMoment === null || $nextMoment < $until);
	}//end releasesEarly()

	/**
	 * Whether the caller is in one of the groups that may lift an embargo.
	 *
	 * @param IUser|null $caller The writing user.
	 *
	 * @return bool False when the caller or its groups cannot be resolved.
	 *
	 * @spec openspec/changes/publication-schedule-guards/tasks.md#task-1.1
	 */
	public function mayLift(?IUser $caller): bool {
		if ($caller === null) {
			return false;
		}

		try {
			$member = $this->groups->getUserGroupIds($caller);
		} catch (Throwable $e) {
			$this->logger->warning('[EmbargoGuard] The caller\'s groups could not be read; the embargo holds', ['exception' => $e]);
			return false;
		}

		return array_intersect($this->liftGroups(), array_map('strval', $member)) !== [];
	}//end mayLift()

	/**
	 * The groups that may lift an embargo, from the app config.
	 *
	 * @return list<string>
	 */
	private function liftGroups(): array {
		$raw = $this->config->getValueString('opencatalogi', self::LIFT_GROUPS_KEY, '');
		if (trim($raw) === '') {
			return self::DEFAULT_LIFT_GROUPS;
		}

		$groups = json_decode($raw, true);
		if (is_array($groups) === false) {
			return self::DEFAULT_LIFT_GROUPS;
		}

		return array_values(array_filter(array_map('strval', array_filter($groups, 'is_scalar')), static fn (string $group): bool => $group !== ''));
	}//end liftGroups()

	/**
	 * Whether a date value is absent.
	 *
	 * @param mixed $value The value.
	 *
	 * @return bool
	 */
	private static function isEmpty(mixed $value): bool {
		return $value === null || (is_string($value) === true && trim($value) === '');
	}//end isEmpty()

	/**
	 * A date-time, or null when the value does not read as one.
	 *
	 * @param mixed $value The value.
	 *
	 * @return DateTimeImmutable|null
	 */
	private static function moment(mixed $value): ?DateTimeImmutable {
		if (is_string($value) === false) {
			return null;
		}

		try {
			return new DateTimeImmutable($value);
		} catch (\Exception $e) {
			return null;
		}
	}//end moment()
}//end class
