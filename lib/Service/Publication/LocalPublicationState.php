<?php

/**
 * What the public read adds to a publication this instance holds.
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
 * @spec openspec/changes/publication-detail-for-the-portal/specs/publications/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenCatalogi\Service\Publication;

use OCA\OpenCatalogi\Contract\WithheldFromPublicationInterface;

/**
 * Adds the withheld list (REQ-WDW-003) and the state of the comment period
 * (REQ-PDP-003) to a publication this instance answered. Either part is left
 * out when its service is not available.
 *
 * @spec openspec/changes/publication-detail-for-the-portal/specs/publications/spec.md#requirement-the-publication-carries-its-comment-period-s-state-req-pdp-003
 */
class LocalPublicationState {

	/**
	 * Constructor.
	 *
	 * @param WithheldFromPublicationInterface|null $withheld       The public withheld list.
	 * @param PublicationCommentPeriod|null         $commentPeriods The comment period state.
	 */
	public function __construct(
		private readonly ?WithheldFromPublicationInterface $withheld = null,
		private readonly ?PublicationCommentPeriod $commentPeriods = null,
	) {
	}//end __construct()

	/**
	 * The publication with its withheld list and comment period state added.
	 *
	 * @param array<string, mixed> $publication The publication this instance answered.
	 *
	 * @return array<string, mixed> The publication with what applies added.
	 *
	 * @spec openspec/changes/publication-detail-for-the-portal/specs/publications/spec.md#requirement-the-publication-carries-its-comment-period-s-state-req-pdp-003
	 */
	public function addTo(array $publication): array {
		if ($this->withheld !== null) {
			$publication = $this->withheld->addForPublication(response: $publication);
		}

		if ($this->commentPeriods !== null) {
			$publication = $this->commentPeriods->addTo(publication: $publication);
		}

		return $publication;
	}//end addTo()
}//end class
