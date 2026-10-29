<?php

/**
 * Contract of WooCategoryRegistry, for callers in other components.
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
 * Contract of WooCategoryRegistry, for callers in other components.
 *
 * Callers outside Service/ depend on this interface instead of on the class.
 */
interface WooCategoryRegistryInterface {
	/**
	 * Every category a catalogue may publish under, keyed by code.
	 *
	 * Each record carries a `tooiUri`, always. A record without one never enters
	 * this list, which is what makes an unmapped local category unable to reach a
	 * sitemap: there is no record for it, so there is no sitemap file for it and no
	 * query that lists its publications.
	 *
	 * @return array<string, array<string, mixed>> The categories, each with `code`,
	 *   `label`, `labelEn`, `tooiUri`, `tooiLabel`, `mapsTo`, `origin`,
	 *   `sitemapFile` and `schemas`.
	 *
	 * @spec openspec/specs/woo-compliance/spec.md#requirement-the-information-categories-are-data-not-code-req-wic-001
	 */
	public function all(): array;

	/**
	 * The local categories this registry refused, code → reason.
	 *
	 * Resolved by {@see all()}, so call that first. A refusal is a configuration
	 * fault an operator must see, which is why it is reported rather than silently
	 * dropped.
	 *
	 * @return array<string, string> The refusals.
	 *
	 * @spec openspec/specs/woo-compliance/spec.md#requirement-a-locally-added-category-publishes-under-a-waardelijst-member-req-wic-002
	 */
	public function rejected(): array;

	/**
	 * The sitemap file names and the title each index carries.
	 *
	 * The drop-in replacement for the former `SitemapService::INFO_CAT` constant.
	 *
	 * @return array<string, string> File name → category title.
	 *
	 * @spec openspec/specs/woo-compliance/spec.md#requirement-the-information-categories-are-data-not-code-req-wic-001
	 */
	public function sitemapFiles(): array;

	/**
	 * One category by its code.
	 *
	 * @param string $code The category code.
	 *
	 * @return array<string, mixed>|null The record, or null when the code is unknown.
	 *
	 * @spec openspec/specs/woo-compliance/spec.md#requirement-the-information-categories-are-data-not-code-req-wic-001
	 */
	public function find(string $code): ?array;

	/**
	 * One category by the sitemap file name it is served at.
	 *
	 * @param string $sitemapFile The file name.
	 *
	 * @return array<string, mixed>|null The record, or null when nothing is served there.
	 *
	 * @spec openspec/specs/woo-compliance/spec.md#requirement-the-information-categories-are-data-not-code-req-wic-001
	 */
	public function findBySitemapFile(string $sitemapFile): ?array;

	/**
	 * The schema slugs a category restricts its sitemap to, if it names any.
	 *
	 * An empty list means the category does not restrict, so the catalogue's whole
	 * schema scope is searched as before.
	 *
	 * @param string $code The category code.
	 *
	 * @return array<int, string> The schema slugs.
	 *
	 * @spec openspec/specs/woo-compliance/spec.md#requirement-a-category-names-the-schemas-its-sitemap-lists-req-wic-003
	 */
	public function schemasFor(string $code): array;

	/**
	 * The waardelijst member a stored category value publishes as.
	 *
	 * Tries the waardelijst resolver first, so a code, a label, a bare `c_…` id and
	 * a full kern URI all keep resolving exactly as they did. Then tries a locally
	 * added category's own code and title, and returns the member that category maps
	 * onto. Returns null when the value maps to no member, which is the fail-closed
	 * path the renderer already takes: the axis is omitted, never emitted as free text.
	 *
	 * @param string|null $value The publication's stored category value.
	 *
	 * @return array{uri: string, label: string}|null The waardelijst member, or null.
	 *
	 * @spec openspec/specs/woo-compliance/spec.md#requirement-a-locally-added-category-publishes-under-a-waardelijst-member-req-wic-002
	 */
	public function resolveForDocument(?string $value): ?array;
}//end interface
