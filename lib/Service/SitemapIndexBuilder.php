<?php

/**
 * The paged searches behind a sitemap and the sitemap index over them.
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

declare(strict_types=1);

namespace OCA\OpenCatalogi\Service;

use OCA\OpenCatalogi\Http\XMLResponse;

/**
 * Searches a sitemap's sources page by page and builds the sitemap index.
 *
 * Visibility is governed by RBAC in every search (_rbac: true): sitemaps
 * expose only the published objects the public group may read.
 *
 * @spec openspec/specs/woo-compliance/spec.md
 */
final class SitemapIndexBuilder {

	/**
	 * Constructor.
	 *
	 * @param object $objectService The OpenRegister ObjectService.
	 * @param int $perPage The publications per sitemap page.
	 *
	 * @spec openspec/specs/woo-compliance/spec.md
	 */
	public function __construct(
		private readonly object $objectService,
		private readonly int $perPage,
	) {
	}//end __construct()

	/**
	 * Page N of every sitemap source, merged and listed once per publication.
	 *
	 * The answer holds the rows, the newest update among them, and the sources that have a next page.
	 *
	 * @param array<int, array<string, mixed>> $queries The sources.
	 * @param int $page The page.
	 *
	 * @return array{results: array<int, object>, lastMod: string|null, continuing: array<int, array<string, mixed>>}
	 *
	 * @spec openspec/specs/woo-compliance/spec.md#requirement-each-category-sitemap-lists-the-publications-filed-under-it-req-wpc-002
	 */
	public function page(array $queries, int $page): array {
		$results = [];
		$seen = [];
		$lastMod = null;
		$continuing = [];
		foreach ($queries as $query) {
			$query['_order']['updated'] = 'desc';
			$query['_limit'] = $this->perPage;
			$query['_page'] = $page;
			$found = $this->objectService->searchObjectsPaginated(
				query: $query,
				_rbac: true,
				_multitenancy: false,
				deleted: false
			);

			$rows = ($found['results'] ?? []);
			if ($rows !== [] && ($found['next'] ?? null) !== null) {
				$continuing[] = $query;
			}

			foreach ($rows as $row) {
				$data = $row->jsonSerialize();
				$key = (string)($data['id'] ?? spl_object_id($row));
				if (isset($seen[$key]) === true) {
					continue;
				}

				$seen[$key] = true;
				$results[] = $row;
				$updated = ($data['@self']['updated'] ?? null);
				if (is_string($updated) === true && ($lastMod === null || strcmp($updated, $lastMod) > 0)) {
					$lastMod = $updated;
				}
			}
		}//end foreach

		return ['results' => $results, 'lastMod' => $lastMod, 'continuing' => $continuing];
	}//end page()

	/**
	 * One index entry per sitemap page, with the newest update on that page.
	 *
	 * Page N holds page N of every source, so the index runs until no source
	 * has a further page.
	 *
	 * @param array<int, array<string, mixed>> $queries The sources.
	 * @param string $baseUri The sitemap pages' URI, without the page.
	 *
	 * @return array<int, array{loc: string, lastmod: string|null}>
	 *
	 * @spec openspec/specs/woo-compliance/spec.md
	 */
	public function entries(array $queries, string $baseUri): array {
		$sitemaps = [];
		$page = 1;
		while ($queries !== []) {
			$batch = $this->page(queries: $queries, page: $page);
			if ($batch['results'] === []) {
				break;
			}

			$sitemaps[] = [
				'loc' => "$baseUri?page=$page",
				'lastmod' => $batch['lastMod'],
			];
			$queries = $batch['continuing'];
			$page++;
		}

		return $sitemaps;
	}//end entries()

	/**
	 * A sitemap index document over the given entries.
	 *
	 * @param array<int, array{loc: string, lastmod: string|null}> $sitemaps The entries.
	 *
	 * @return XMLResponse
	 *
	 * @spec openspec/specs/woo-compliance/spec.md
	 */
	public function response(array $sitemaps): XMLResponse {
		return new XMLResponse(
			data: [
				'@root' => 'sitemapindex',
				'@attributes' => ['xmlns' => 'http://www.sitemaps.org/schemas/sitemap/0.9'],
				'sitemap' => $sitemaps,
			]
		);
	}//end response()
}//end class
