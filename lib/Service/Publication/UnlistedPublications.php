<?php

/**
 * Unlisted publications
 *
 * An unlisted publication stays readable at its own public link, and is kept
 * off every list a machine or a search reads: search, the catalogue listing,
 * the DiWoo sitemaps, DCAT, the federation list and PLOOI delivery
 * (publication-lifecycle-on-or REQ-PLC-006). The check runs in PHP where each
 * surface gathers its rows, like the archived check of RET-006, because a SQL
 * `unlisted != true` clause would also drop every legacy row whose flag is NULL.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Service
 * @package  OCA\OpenCatalogi\Service\Publication
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://www.OpenCatalogi.nl
 *
 * @spec openspec/changes/publication-lifecycle-on-or/specs/publications/spec.md#requirement-a-public-publication-can-be-unlisted-and-put-back-req-plc-006
 */

declare(strict_types=1);

namespace OCA\OpenCatalogi\Service\Publication;

use JsonSerializable;

/**
 * Reads the `unlisted` flag and keeps unlisted rows off a list.
 *
 * @spec openspec/changes/publication-lifecycle-on-or/specs/publications/spec.md#requirement-a-public-publication-can-be-unlisted-and-put-back-req-plc-006
 */
final class UnlistedPublications {

	/**
	 * Whether a row is an unlisted publication; only a true flag counts.
	 *
	 * @param mixed $row An array or a serialisable entity.
	 *
	 * @return bool
	 *
	 * @spec openspec/changes/publication-lifecycle-on-or/specs/publications/spec.md#requirement-a-public-publication-can-be-unlisted-and-put-back-req-plc-006
	 */
	public static function isUnlisted(mixed $row): bool {
		if ($row instanceof JsonSerializable) {
			$row = $row->jsonSerialize();
		}

		if (is_array($row) === false) {
			return false;
		}

		$flag = ($row['unlisted'] ?? false);

		return $flag === true || $flag === 1 || $flag === '1' || $flag === 'true';
	}//end isUnlisted()

	/**
	 * A page without its unlisted rows, its `total` lowered by as many.
	 *
	 * @param array<string, mixed> $page A page with `results` and optionally `total`.
	 *
	 * @return array<string, mixed>
	 *
	 * @spec openspec/changes/publication-lifecycle-on-or/specs/publications/spec.md#requirement-a-public-publication-can-be-unlisted-and-put-back-req-plc-006
	 */
	public static function withoutUnlisted(array $page): array {
		if (is_array($page['results'] ?? null) === false) {
			return $page;
		}

		$kept = array_values(array_filter($page['results'], static fn (mixed $row): bool => self::isUnlisted(row: $row) === false));
		$dropped = (count($page['results']) - count($kept));
		$page['results'] = $kept;
		if ($dropped > 0 && is_numeric($page['total'] ?? null) === true) {
			$page['total'] = max(count($kept), ((int)$page['total'] - $dropped));
		}

		return $page;
	}//end withoutUnlisted()
}//end class
