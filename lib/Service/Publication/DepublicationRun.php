<?php

/**
 * OpenCatalogi Depublication Run.
 *
 * One depublication in progress: the publication taken down, why, and the
 * moment, which every withdrawal it sends carries.
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
 * @spec openspec/changes/publication-inspection-and-the-national-indexes/specs/publication-inspection-and-the-national-indexes/spec.md#requirement-something-published-in-error-is-depublished-with-one-action-req-pin-106
 */

declare(strict_types=1);

namespace OCA\OpenCatalogi\Service\Publication;

/**
 * One depublication. Built by DepublicationService::depublish() and never
 * handed outside it.
 *
 * @spec openspec/changes/publication-inspection-and-the-national-indexes/specs/publication-inspection-and-the-national-indexes/spec.md#requirement-something-published-in-error-is-depublished-with-one-action-req-pin-106
 */
final class DepublicationRun {

	/**
	 * The publication taken down.
	 *
	 * @var string
	 */
	public string $publicationId = '';

	/**
	 * Why it is coming down.
	 *
	 * @var string
	 */
	public string $reason = '';

	/**
	 * Who is taking it down.
	 *
	 * @var string
	 */
	public string $depublishedBy = '';

	/**
	 * When it was taken down (ISO 8601).
	 *
	 * @var string
	 */
	public string $takenDownAt = '';
}//end class
