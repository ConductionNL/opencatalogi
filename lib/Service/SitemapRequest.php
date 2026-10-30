<?php

/**
 * One sitemap request and what it resolves to.
 *
 * @category Service
 * @package  OCA\OpenCatalogi\Service
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
 * @spec openspec/specs/woo-compliance/spec.md
 */

namespace OCA\OpenCatalogi\Service;

/**
 * The catalog slug and category file a sitemap is asked for, and the catalog,
 * register and schema {@see SitemapService} resolves them to.
 *
 * @spec openspec/specs/woo-compliance/spec.md
 */
final class SitemapRequest {

	/**
	 * The catalog the slug resolved to.
	 *
	 * @var mixed
	 */
	public mixed $catalog = null;

	/**
	 * The register of the title-matched schema, or null.
	 *
	 * @var mixed
	 */
	public mixed $registerId = null;

	/**
	 * The title-matched schema inside the catalog, or null.
	 *
	 * @var mixed
	 */
	public mixed $schemaId = null;

	/**
	 * The searches that feed the sitemap, without paging.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	public array $queries = [];

	/**
	 * Constructor.
	 *
	 * @param string $catalogSlug The catalog slug.
	 * @param string $categoryCode The sitemap category file, such as `sitemapindex-diwoo-infocat001.xml`.
	 *
	 * @spec openspec/specs/woo-compliance/spec.md
	 */
	public function __construct(
		public readonly string $catalogSlug,
		public readonly string $categoryCode,
	) {
	}//end __construct()

	/**
	 * The search for the catalog this request names: by slug, with a Woo sitemap.
	 *
	 * @param array<string, mixed> $configuration The catalog register and schema settings.
	 *
	 * @return array<string, mixed> The search query.
	 *
	 * @spec openspec/specs/woo-compliance/spec.md
	 */
	public function catalogQuery(array $configuration): array {
		return [
			'@self' => [
				'register' => $configuration['catalog_register'],
				'schema' => $configuration['catalog_schema'],
			],
			'slug' => $this->catalogSlug,
			'hasWooSitemap' => true,
		];
	}//end catalogQuery()
}//end class
