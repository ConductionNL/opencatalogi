<?php

/**
 * Contract of SitemapService, for callers in other components.
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

use OCP\AppFramework\Http\Response;

/**
 * Contract of SitemapService, for callers in other components.
 *
 * Callers outside Service/ depend on this interface instead of on the class.
 */
interface SitemapServiceInterface {
	/**
	 * The sitemap file names this instance serves, and the title each one carries.
	 *
	 * The categories are data (REQ-WIC-001), so this is a method over
	 * {@see WooCategoryRegistry} rather than the former `INFO_CAT` constant. A
	 * category an operator added appears here without a code change, and one the
	 * registry refused does not appear at all.
	 *
	 * @return array<string, string> File name → category title.
	 *
	 * @spec openspec/specs/woo-compliance/spec.md#requirement-the-information-categories-are-data-not-code-req-wic-001
	 */
	public function sitemapFiles(): array;

	/**
	 * Build sitemap index based on woo category.
	 *
	 * @param string $catalogSlug The catalog slug identifier.
	 * @param string $categoryCode The DIWOO category code.
	 *
	 * @return Response The sitemap index as an XML response.
	 *
	 * @spec openspec/specs/woo-compliance/spec.md
	 */
	public function buildSitemapIndex(string $catalogSlug, string $categoryCode): Response;

	/**
	 * Build sitemap based on woo publications.
	 *
	 * @param string $catalogSlug The catalog slug identifier.
	 * @param string $categoryCode The DIWOO category code.
	 * @param integer $page The page number to retrieve.
	 *
	 * @return Response The publications sitemap as an XML response.
	 *
	 *                is a pure static builder over constants, like CallerScope::writeScope().
	 *
	 * @spec openspec/specs/woo-compliance/spec.md
	 */
	public function buildSitemap(string $catalogSlug, string $categoryCode, int $page): Response;

	/**
	 * Dry-run the DIWOO mapping for a catalog and collect per-document violations.
	 *
	 * Runs the same {@see mapDiwooDocument()} path used to serve the sitemap, but
	 * discards the XML and returns only the unresolved-axis report. Advisory: it
	 * never prevents the sitemap from being served (WOO-TOOI-004).
	 *
	 * @param array<int, array<string, mixed>> $publications The catalog's publications (with `@self.files`).
	 *
	 * @return array<int, array<string, string>> The `{documentLoc, axis, reason}` violations.
	 *
	 * @spec openspec/specs/woo-compliance/spec.md
	 */
	public function collectDiwooViolations(array $publications): array;

	/**
	 * Validate a catalog's DIWOO output in a dry run (WOO-TOOI-004).
	 *
	 * Fetches the catalog's publicly visible publications and their files exactly
	 * as {@see buildSitemap()} does, then reports every axis that could not resolve
	 * to an official TOOI/DiWoo value-list URI. Advisory only — it does not serve
	 * or block the sitemap.
	 *
	 * @param string $catalogSlug The catalog slug.
	 * @param string $categoryCode The DIWOO category code (e.g. `infocat014`).
	 * @param integer $page The 1-based page number.
	 *
	 * @return array{catalogSlug: string, categoryCode: string, valid: bool, violations: array<int, array<string, string>>, error?: string}
	 *
	 * @spec openspec/specs/woo-compliance/spec.md
	 */
	public function validateDiwooOutput(string $catalogSlug, string $categoryCode, int $page = 1): array;
}//end interface
