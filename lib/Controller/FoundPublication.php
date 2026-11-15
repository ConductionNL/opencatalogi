<?php

/**
 * A publication a detail read found, and the id it is counted under.
 *
 * @category Controller
 * @package  OCA\OpenCatalogi\Controller
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
 * @spec openspec/specs/publications/spec.md#requirement-retrieve-a-single-publication-by-catalog-slug-and-object-uuid-or-slug-pub-002
 */

declare(strict_types=1);

namespace OCA\OpenCatalogi\Controller;

/**
 * The publication object and the id the withheld list and the view counter key on.
 *
 * That id is the one from the URL, or the publication's UUID when a slug
 * resolved it.
 *
 * @spec openspec/specs/publications/spec.md#requirement-retrieve-a-single-publication-by-catalog-slug-and-object-uuid-or-slug-pub-002
 */
final class FoundPublication {

	/**
	 * Constructor.
	 *
	 * @param object $object The publication's ObjectEntity.
	 * @param string $publicationId The id the withheld list and the view counter key on.
	 */
	public function __construct(
		public readonly object $object,
		public readonly string $publicationId,
	) {

	}//end __construct()
}//end class
