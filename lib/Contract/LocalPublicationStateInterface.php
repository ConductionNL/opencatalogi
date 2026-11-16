<?php

/**
 * Contract of LocalPublicationState, for callers in other components.
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

/**
 * Contract of LocalPublicationState, for callers in other components.
 *
 * Callers outside Service/ depend on this interface instead of on the class.
 */
interface LocalPublicationStateInterface {
	/**
	 * The publication with its withheld list and comment period state added.
	 *
	 * @param array<string, mixed> $publication The publication this instance answered.
	 *
	 * @return array<string, mixed> The publication with what applies added.
	 *
	 * @spec openspec/changes/publication-detail-for-the-portal/specs/publications/spec.md#requirement-the-publication-carries-its-comment-period-s-state-req-pdp-003
	 */
	public function addTo(array $publication): array;
}//end interface
