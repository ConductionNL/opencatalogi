<?php

/**
 * Checks the range bounds of a public search before it reaches OpenRegister.
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
 * @spec openspec/changes/search-council-document-filters/specs/search/spec.md#requirement-the-public-search-filters-council-documents-by-meeting-date-range-req-scf-002
 */

namespace OCA\OpenCatalogi\Service;

/**
 * A range bound that is neither a date nor a number would reach OpenRegister and
 * come back as an empty result that reads as "nothing found".
 *
 * @spec openspec/changes/search-council-document-filters/specs/search/spec.md#requirement-the-public-search-filters-council-documents-by-meeting-date-range-req-scf-002
 */
final class SearchRangeGuard {

	/**
	 * The first range bound in the query that is neither a date nor a number.
	 *
	 * A property filter written as `name[gte]`, `name[lte]`, `name[gt]` or
	 * `name[lt]` is passed to OpenRegister's range operators. Each bound must be
	 * an ISO date (`2026-04-01`), an ISO date-time, or a number. Keys starting
	 * with `_` are OpenRegister options, not property filters, and are skipped.
	 *
	 * @param array<string, mixed> $queryParams The request query parameters.
	 *
	 * @return string|null The bad parameter as the caller wrote it, e.g. `meetingDate[gte]`, or null.
	 *
	 * @spec openspec/changes/search-council-document-filters/specs/search/spec.md#requirement-the-public-search-filters-council-documents-by-meeting-date-range-req-scf-002
	 */
	public static function malformedParameter(array $queryParams): ?string {
		foreach ($queryParams as $name => $value) {
			if (is_array($value) === false || str_starts_with((string) $name, '_') === true) {
				continue;
			}

			foreach (['gte', 'lte', 'gt', 'lt'] as $operator) {
				if (array_key_exists($operator, $value) === true && self::isRangeBound(value: $value[$operator]) === false) {
					return $name.'['.$operator.']';
				}
			}
		}

		return null;

	}//end malformedParameter()

	/**
	 * Whether a range bound is an ISO date, an ISO date-time or a number.
	 *
	 * @param mixed $value The bound.
	 *
	 * @return bool True when OpenRegister can compare on it.
	 *
	 * @spec openspec/changes/search-council-document-filters/specs/search/spec.md#requirement-the-public-search-filters-council-documents-by-meeting-date-range-req-scf-002
	 */
	private static function isRangeBound(mixed $value): bool {
		if (is_int($value) === true || is_float($value) === true) {
			return true;
		}

		if (is_string($value) === false) {
			return false;
		}

		if (is_numeric($value) === true) {
			return true;
		}

		$isoDate = '/^(\\d{4})-(\\d{2})-(\\d{2})([T ]\\d{2}:\\d{2}(:\\d{2}(\\.\\d+)?)?(Z|[+-]\\d{2}:?\\d{2})?)?$/';
		if (preg_match($isoDate, $value, $parts) !== 1) {
			return false;
		}

		return checkdate((int) $parts[2], (int) $parts[3], (int) $parts[1]);

	}//end isRangeBound()
}//end class
