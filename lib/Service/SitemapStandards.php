<?php

/**
 * The sitemap files, their output validation and the published standard version.
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
 * @spec openspec/changes/woo-index-harvester-readiness/specs/woo-compliance/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenCatalogi\Service;

/**
 * What a harvester readiness check needs to know about the sitemaps this
 * instance serves: their file names, whether the output of one validates, and
 * how the standard version the output declares compares with the published one.
 *
 * @spec openspec/changes/woo-index-harvester-readiness/specs/woo-compliance/spec.md
 */
class SitemapStandards {

	/**
	 * Constructor.
	 *
	 * @param SitemapService          $sitemapService    The sitemap service.
	 * @param StandardsVersionService $standardsVersions Compares the emitted standard version with the published one.
	 *
	 * @spec exclude pure framework plumbing: constructor dependency injection.
	 */
	public function __construct(
		private readonly SitemapService $sitemapService,
		private readonly StandardsVersionService $standardsVersions,
	) {

	}//end __construct()

	/**
	 * The sitemap file names this instance serves, and the title each one carries.
	 *
	 * @return array<string, string> File name => title.
	 *
	 * @spec openspec/changes/woo-index-harvester-readiness/specs/woo-compliance/spec.md
	 */
	public function sitemapFiles(): array {
		return $this->sitemapService->sitemapFiles();

	}//end sitemapFiles()

	/**
	 * Validate the first page of one catalog's sitemap output.
	 *
	 * @param string $catalogSlug  The catalog slug.
	 * @param string $categoryCode The sitemap file name.
	 *
	 * @return array The validation report.
	 *
	 * @spec openspec/changes/woo-index-harvester-readiness/specs/woo-compliance/spec.md
	 */
	public function validateFirstPage(string $catalogSlug, string $categoryCode): array {
		return $this->sitemapService->validateDiwooOutput(
			catalogSlug: $catalogSlug,
			categoryCode: $categoryCode,
			page: 1
		);

	}//end validateFirstPage()

	/**
	 * The emitted standard version compared with the published one.
	 *
	 * @return array{declared: string, published: string|null, status: string} The comparison.
	 *
	 * @spec openspec/changes/woo-index-harvester-readiness/specs/woo-compliance/spec.md
	 */
	public function versionComparison(): array {
		return $this->standardsVersions->diwooVersion();

	}//end versionComparison()
}//end class
