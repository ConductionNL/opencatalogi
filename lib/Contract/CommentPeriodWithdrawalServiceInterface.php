<?php

/**
 * Contract of CommentPeriodWithdrawalService, for callers in other components.
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

use DateTimeInterface;

/**
 * Contract of CommentPeriodWithdrawalService, for callers in other components.
 *
 * Callers outside Service/ depend on this interface instead of on the class.
 */
interface CommentPeriodWithdrawalServiceInterface {
	/**
	 * Resolve the consumed OpenRegister ObjectService.
	 *
	 * @return object|null The service, or null when OpenRegister is unavailable.
	 *
	 * @spec exclude pure framework plumbing — resolves the consumed OR ObjectService.
	 */
	public function getObjectService(): ?object;

	/**
	 * Withdraw every publication whose comment period closed and asked for it.
	 *
	 * @param DateTimeInterface|null $now The moment; defaults to now.
	 *
	 * @return array<string, int> How many periods were considered, withdrawn and failed.
	 *
	 * @spec openspec/specs/publication-comment-periods/spec.md#requirement-a-closed-period-can-withdraw-its-publication-req-pcp-005
	 */
	public function withdrawClosedPeriods(?DateTimeInterface $now = null): array;
}//end interface
