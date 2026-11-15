<?php

/**
 * Rolls usage counter rows up to one CSV entry per publication.
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
 * @spec openspec/specs/publication-usage-analytics/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenCatalogi\Service;

use OCA\OpenCatalogi\Contract\UsageCounterServiceInterface;

/**
 * The per-publication views and downloads behind the usage CSV export.
 *
 * @spec openspec/specs/publication-usage-analytics/spec.md
 */
final class UsageCsvRollUp {

	/**
	 * The counter kind each CSV count column adds up.
	 *
	 * @var array<string, string>
	 */
	private const CSV_COUNT_KINDS = [
		'views' => UsageCounterServiceInterface::KIND_VIEW,
		'downloads' => UsageCounterServiceInterface::KIND_DOWNLOAD,
	];

	/**
	 * Counter rows rolled up to one entry per publication; rows without a publication are left out.
	 *
	 * @param array<int, array<string, mixed>> $rows Counter rows.
	 *
	 * @return array<string, array{publication: string, category: string, published: string, views: int, downloads: int}>
	 *
	 * @spec openspec/specs/publication-usage-analytics/spec.md
	 */
	public static function rollUp(array $rows): array {
		$byPub = [];
		foreach ($rows as $row) {
			$pub = (string)($row['publication'] ?? '');
			if ($pub !== '') {
				$byPub[$pub] = self::withCount(entry: ($byPub[$pub] ?? self::csvEntry(publication: $pub, row: $row)), row: $row);
			}
		}

		return $byPub;
	}//end rollUp()

	/**
	 * A publication's empty entry, with the category and published date of its first row.
	 *
	 * @param string $publication The publication.
	 * @param array<string, mixed> $row Its first counter row.
	 *
	 * @return array{publication: string, category: string, published: string, views: int, downloads: int}
	 *
	 * @spec openspec/specs/publication-usage-analytics/spec.md
	 */
	private static function csvEntry(string $publication, array $row): array {
		return [
			'publication' => $publication,
			'category' => (string)($row['category'] ?? ''),
			'published' => (string)($row['published'] ?? ''),
			'views' => 0,
			'downloads' => 0,
		];
	}//end csvEntry()

	/**
	 * The entry with a view or download row's count added (negative counts as 0); other kinds add nothing.
	 *
	 * @param array<string, mixed> $entry The publication's entry.
	 * @param array<string, mixed> $row The counter row.
	 *
	 * @return array<string, mixed>
	 *
	 * @spec openspec/specs/publication-usage-analytics/spec.md
	 */
	private static function withCount(array $entry, array $row): array {
		$column = array_search(($row['kind'] ?? ''), self::CSV_COUNT_KINDS, true);
		if ($column !== false) {
			$entry[$column] += max(0, (int)($row['count'] ?? 0));
		}

		return $entry;
	}//end withCount()
}//end class
