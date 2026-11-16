<?php

/**
 * Contract of PublicationCommentPeriod, for callers in other components.
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
 * Contract of PublicationCommentPeriod, for callers in other components.
 *
 * Callers outside Service/ depend on this interface instead of on the class.
 */
interface PublicationCommentPeriodInterface {
	/**
	 * The publication with its comment period, when it has one.
	 *
	 * @param array<string, mixed>   $publication The publication as answered to the reader.
	 * @param DateTimeInterface|null $now         The moment; defaults to now.
	 *
	 * @return array<string, mixed> The publication, with `commentPeriod` or `commentPeriodError` when it has a period.
	 *
	 * @spec openspec/changes/publication-detail-for-the-portal/specs/publications/spec.md#requirement-the-publication-carries-its-comment-period-s-state-req-pdp-003
	 */
	public function addTo(array $publication, ?DateTimeInterface $now = null): array;
}//end interface
