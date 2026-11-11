<?php

/**
 * OpenCatalogi Saved Search Match Run.
 *
 * The moment of one matcher run and what it counted: the saved searches
 * handled, the notices handed over, the ones baselined and the failures.
 *
 * @category Service
 * @package  OCA\OpenCatalogi\Service\Portal
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * @version GIT: <git_id>
 * @link https://www.OpenCatalogi.nl
 * @spec openspec/changes/saved-searches-and-alerts/specs/saved-searches/spec.md#requirement-the-frequency-decides-when-and-how-the-resident-hears-req-ssa-003
 */

declare(strict_types=1);

namespace OCA\OpenCatalogi\Service\Portal;

use DateTimeImmutable;

/**
 * One run of the saved-search matcher. Built by SavedSearchMatcher::run()
 * and never handed outside it.
 *
 * @spec openspec/changes/saved-searches-and-alerts/specs/saved-searches/spec.md#requirement-the-frequency-decides-when-and-how-the-resident-hears-req-ssa-003
 */
final class SavedSearchMatchRun {

	/**
	 * The moment of the run, in the instance's time zone.
	 *
	 * @var DateTimeImmutable
	 */
	public DateTimeImmutable $now;

	/**
	 * The same moment in UTC, as stored on a saved search.
	 *
	 * @var string
	 */
	public string $nowUtc = '';

	/**
	 * Due saved searches handled.
	 *
	 * @var integer
	 */
	public int $handled = 0;

	/**
	 * Notices handed over.
	 *
	 * @var integer
	 */
	public int $notices = 0;

	/**
	 * New saved searches given their first last run.
	 *
	 * @var integer
	 */
	public int $baselined = 0;

	/**
	 * Saved searches that failed and are tried again next run.
	 *
	 * @var integer
	 */
	public int $failed = 0;

	/**
	 * What the run counted.
	 *
	 * @return array{handled: int, notices: int, baselined: int, failed: int}
	 *
	 * @spec openspec/changes/saved-searches-and-alerts/specs/saved-searches/spec.md#requirement-the-frequency-decides-when-and-how-the-resident-hears-req-ssa-003
	 */
	public function stats(): array {
		return [
			'handled' => $this->handled,
			'notices' => $this->notices,
			'baselined' => $this->baselined,
			'failed' => $this->failed,
		];
	}//end stats()
}//end class
