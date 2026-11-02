<?php

/**
 * Contract of SavedSearchMatcher, for callers in other components.
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

use DateTimeImmutable;

/**
 * Contract of SavedSearchMatcher, for callers in other components.
 *
 * Callers outside Service/ depend on this interface instead of on the class.
 */
interface SavedSearchMatcherInterface {
	/**
	 * Handle every due saved search.
	 *
	 * @param DateTimeImmutable $now The moment of the run, in the instance's time zone.
	 *
	 * @return array{handled: int, notices: int, baselined: int, failed: int}
	 *
	 * @spec openspec/changes/saved-searches-and-alerts/specs/saved-searches/spec.md#requirement-the-frequency-decides-when-and-how-the-resident-hears-req-ssa-003
	 */
	public function run(DateTimeImmutable $now): array;

	/**
	 * Whether a saved search is due at this moment.
	 *
	 * @param string            $frequency immediate, daily or weekly.
	 * @param DateTimeImmutable $lastRunAt Its last run.
	 * @param DateTimeImmutable $now       The moment of the run.
	 *
	 * @return bool
	 *
	 * @spec openspec/changes/saved-searches-and-alerts/specs/saved-searches/spec.md#requirement-the-frequency-decides-when-and-how-the-resident-hears-req-ssa-003
	 */
	public function isDue(string $frequency, DateTimeImmutable $lastRunAt, DateTimeImmutable $now): bool;
}//end interface
