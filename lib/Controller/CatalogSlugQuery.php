<?php

/**
 * A slug looked up inside one catalogue's registers and schemas.
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
 * The identifier from the URL and the catalogue scope it is looked up in.
 *
 * @spec openspec/specs/publications/spec.md#requirement-retrieve-a-single-publication-by-catalog-slug-and-object-uuid-or-slug-pub-002
 */
final class CatalogSlugQuery {

	/**
	 * Constructor.
	 *
	 * @param string $slug The identifier from the URL, as given.
	 * @param string $catalogSlug The catalogue, for the log line.
	 * @param array<int, mixed> $registers The catalogue's register ids.
	 * @param array<int, mixed> $schemas The catalogue's schema ids.
	 */
	public function __construct(
		public readonly string $slug,
		public readonly string $catalogSlug,
		public readonly array $registers,
		public readonly array $schemas,
	) {

	}//end __construct()
}//end class
