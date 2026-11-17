<?php

/**
 * Contract of InstanceModeSwitch, for callers in other components.
 *
 * @category Contract
 * @package  OCA\OpenCatalogi\Contract
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
 */

declare(strict_types=1);

namespace OCA\OpenCatalogi\Contract;

use InvalidArgumentException;

/**
 * Contract of InstanceModeSwitch, for callers in other components.
 *
 * Callers outside Service/ depend on this interface instead of on the class.
 */
interface InstanceModeSwitchInterface {

	/**
	 * The stored mode, and what OpenCatalogi does not stop.
	 *
	 * @return array{mode: string, outside: array{webhooks: list<string>, synchronisations: list<string>}}
	 *
	 * @spec openspec/changes/instance-staging-mode/specs/admin-settings/spec.md#requirement-an-administrator-puts-the-instance-in-staging-req-stg-001
	 */
	public function current(): array;

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
	public function change(string $mode, bool $confirmed, string $userId): array;
}//end interface
