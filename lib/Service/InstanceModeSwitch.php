<?php

/**
 * Changes the instance mode, on confirmation, with an audit entry.
 *
 * @category Service
 * @package  OCA\OpenCatalogi\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/instance-staging-mode/specs/admin-settings/spec.md#requirement-an-administrator-puts-the-instance-in-staging-req-stg-001
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenCatalogi\Service;

use InvalidArgumentException;
use OCA\OpenCatalogi\Contract\AppIdentity;
use OCA\OpenCatalogi\Contract\InstanceModeSwitchInterface;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\IAppConfig;
use OCP\Log\Audit\CriticalActionPerformedEvent;
use Throwable;

/**
 * Writes the app config `instance_mode` only when the administrator confirmed the
 * change, and records who changed it from what to what in Nextcloud's audit log
 * (a CriticalActionPerformedEvent, which the admin_audit app writes out).
 * Switching back to production replays nothing: dry runs stay as they are.
 *
 * @spec openspec/changes/instance-staging-mode/specs/admin-settings/spec.md#requirement-an-administrator-puts-the-instance-in-staging-req-stg-001
 */
class InstanceModeSwitch implements InstanceModeSwitchInterface {

	/**
	 * The modes an administrator can choose.
	 */
	public const MODES = [InstanceMode::PRODUCTION, InstanceMode::STAGING];

	/**
	 * Constructor.
	 *
	 * @param IAppConfig       $appConfig  The app config holding `instance_mode`.
	 * @param IEventDispatcher $dispatcher Dispatches the audit event.
	 * @param OutsideReach     $outside    Lists what can still leave outside OpenCatalogi.
	 *
	 * @spec openspec/changes/instance-staging-mode/specs/admin-settings/spec.md#requirement-an-administrator-puts-the-instance-in-staging-req-stg-001
	 */
	public function __construct(
		private readonly IAppConfig $appConfig,
		private readonly IEventDispatcher $dispatcher,
		private readonly OutsideReach $outside,
	) {
	}//end __construct()

	/**
	 * The stored mode, and what OpenCatalogi does not stop.
	 *
	 * @return array{mode: string, outside: array{webhooks: list<string>, synchronisations: list<string>}}
	 *
	 * @spec openspec/changes/instance-staging-mode/specs/admin-settings/spec.md#requirement-an-administrator-puts-the-instance-in-staging-req-stg-001
	 */
	public function current(): array {
		return ['mode' => $this->stored(), 'outside' => $this->outside->list()];
	}//end current()

	/**
	 * Change the mode once the administrator confirmed it, and audit the change.
	 *
	 * @param string $mode      The new mode: production or staging.
	 * @param bool   $confirmed Whether the administrator confirmed the change.
	 * @param string $userId    Who changes it.
	 *
	 * @return array{mode: string, previous: string, changed: bool}
	 *
	 * @throws InvalidArgumentException When the mode is unknown or the change was not confirmed.
	 *
	 * @spec openspec/changes/instance-staging-mode/specs/admin-settings/spec.md#requirement-an-administrator-puts-the-instance-in-staging-req-stg-001
	 */
	public function change(string $mode, bool $confirmed, string $userId): array {
		if (in_array($mode, self::MODES, true) === false) {
			throw new InvalidArgumentException('The instance mode must be production or staging.');
		}

		$previous = $this->stored();
		if ($previous === $mode) {
			return ['mode' => $mode, 'previous' => $previous, 'changed' => false];
		}

		if ($confirmed === false) {
			throw new InvalidArgumentException('Changing the instance mode needs a confirmation.');
		}

		$this->appConfig->setValueString(AppIdentity::APP_ID, InstanceMode::KEY, $mode);
		$this->dispatcher->dispatchTyped(
			new CriticalActionPerformedEvent(
				'OpenCatalogi instance mode changed from %s to %s by %s',
				[$previous, $mode, $userId]
			)
		);

		return ['mode' => $mode, 'previous' => $previous, 'changed' => true];
	}//end change()

	/**
	 * The stored mode as written; an unreadable value reads as unreadable, which the gate treats as staging.
	 *
	 * @return string
	 */
	private function stored(): string {
		try {
			return $this->appConfig->getValueString(AppIdentity::APP_ID, InstanceMode::KEY, InstanceMode::PRODUCTION);
		} catch (Throwable) {
			return 'unreadable';
		}
	}//end stored()
}//end class
