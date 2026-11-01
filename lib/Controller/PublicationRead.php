<?php

/**
 * One public detail read of a publication through a catalogue.
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
 * The catalogue, the lookup, the object service and the extend list of one detail read.
 *
 * @spec openspec/specs/publications/spec.md#requirement-retrieve-a-single-publication-by-catalog-slug-and-object-uuid-or-slug-pub-002
 */
final class PublicationRead {

	/**
	 * Constructor.
	 *
	 * @param array<string, mixed> $catalog The guarded catalogue.
	 * @param CatalogSlugQuery $query The id from the URL and the catalogue scope.
	 * @param object $objectService OpenRegister's ObjectService.
	 * @param array<int, string> $extend The sanitised public extend list.
	 */
	public function __construct(
		public readonly array $catalog,
		public readonly CatalogSlugQuery $query,
		public readonly object $objectService,
		public readonly array $extend,
	) {

	}//end __construct()
}//end class
