<?php

/**
 * Contract of HandoverState, for callers in other components.
 *
 * @category Contract
 * @package  OCA\OpenCatalogi\Contract
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * @version GIT: <git_id>
 * @link https://www.OpenCatalogi.nl
 */

declare(strict_types=1);

namespace OCA\OpenCatalogi\Contract;

/**
 * Contract of HandoverState, for callers in other components.
 *
 * Callers outside Service/ depend on this interface instead of on the class.
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface HandoverStateInterface {

	/**
	 * Store the counts of a hand-over run, and make the records read-only when nothing is left.
	 *
	 * @param int    $unmigrated How many records are not moved.
	 * @param int    $failed     How many of those could not be moved.
	 * @param string $checkedAt  When (ISO 8601).
	 *
	 * @return void
	 *
	 * @spec exclude Structural contract, no functional requirement.
	 */
	public function record(int $unmigrated, int $failed, string $checkedAt): void;

	/**
	 * Whether the handed-over records are read-only here.
	 *
	 * @return bool
	 *
	 * @spec exclude Structural contract, no functional requirement.
	 */
	public function isReadOnly(): bool;

	/**
	 * Whether the app the records are handed to is enabled.
	 *
	 * @return bool
	 *
	 * @spec exclude Structural contract, no functional requirement.
	 */
	public function dossiqInstalled(): bool;

	/**
	 * The state as the readiness check and the settings show it.
	 *
	 * @return array{unmigrated: int|null, failed: int|null, checkedAt: string, readOnly: bool, dossiqInstalled: bool}
	 *
	 * @spec exclude Structural contract, no functional requirement.
	 */
	public function report(): array;
}//end interface
