<?php

/**
 * Contract of UnpublishedOverviewService, for callers in other components.
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
 *
 * @spec openspec/changes/publications-unpublished-overview/specs/dashboard/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenCatalogi\Contract;

/**
 * Contract of UnpublishedOverviewService, for callers in other components.
 *
 * Callers outside Service/ depend on this interface instead of on the class.
 *
 * @spec openspec/changes/publications-unpublished-overview/specs/dashboard/spec.md
 */
interface UnpublishedOverviewServiceInterface {
	/**
	 * The publications the user can read that are not yet public and not withdrawn.
	 *
	 * @param int|null $limit The list length, clamped to 1..50.
	 *
	 * @return array{items: list<array<string, mixed>>, total: int, scope: string|null} The list.
	 *
	 * @spec openspec/changes/publications-unpublished-overview/specs/dashboard/spec.md#requirement-unpublished-content-dashboard-widgets-dsh-011
	 */
	public function publications(?int $limit): array;

	/**
	 * The files of readable publications whose published time is empty or in the future.
	 *
	 * @param int|null $limit The list length, clamped to 1..50.
	 *
	 * @return array{items: list<array<string, mixed>>, total: int, scope: string|null} The list.
	 *
	 * @spec openspec/changes/publications-unpublished-overview/specs/dashboard/spec.md#requirement-unpublished-content-dashboard-widgets-dsh-011
	 */
	public function documents(?int $limit): array;
}//end interface
