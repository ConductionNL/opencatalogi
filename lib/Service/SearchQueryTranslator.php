<?php

/**
 * OpenCatalogi Search Query Translator.
 *
 * One vocabulary for the portal's Woo search (hydra `woo-citizen-journey`, C2
 * and C6). The portal search block and a saved search speak of
 * `informatiecategorie`, `organisation`, `periodFrom` and `periodTo`; the
 * publication stores `wooCategory`, `organization` and `publicationDate`. This
 * class renames the one into the other, for `GET /api/search` and for the
 * saved-search job alike, so both ask OpenRegister the same question.
 *
 * @category Service
 * @package  OCA\OpenCatalogi\Service
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * @version GIT: <git_id>
 * @link https://www.OpenCatalogi.nl
 * @spec openspec/changes/woo-dossier-publication/specs/publications/spec.md#requirement-public-search-takes-the-portals-filter-names-req-wdp-002
 */

declare(strict_types=1);

namespace OCA\OpenCatalogi\Service;

/**
 * Renames the portal's search vocabulary to the publication's properties.
 *
 * @spec openspec/changes/woo-dossier-publication/specs/publications/spec.md#requirement-public-search-takes-the-portals-filter-names-req-wdp-002
 */
final class SearchQueryTranslator {

	/**
	 * Portal filter name to publication property.
	 */
	private const RENAMES = [
		'informatiecategorie' => 'wooCategory',
		'organisation' => 'organization',
	];

	/**
	 * The saved query's text fields and the search parameter each becomes.
	 */
	private const SAVED_TEXT = [
		'text' => '_search',
		'catalog' => '_catalog',
	];

	/**
	 * Portal period bound to the range operator on `publicationDate`.
	 */
	private const PERIOD = [
		'periodFrom' => 'gte',
		'periodTo' => 'lte',
	];

	/**
	 * Translate request parameters of `GET /api/search`.
	 *
	 * A parameter the caller also sent under the publication's own name wins,
	 * so an existing client that sends `wooCategory` sees no change.
	 *
	 * @param array<string, mixed> $params The request parameters.
	 *
	 * @return array<string, mixed>
	 *
	 * @spec openspec/changes/woo-dossier-publication/specs/publications/spec.md#requirement-public-search-takes-the-portals-filter-names-req-wdp-002
	 */
	public static function translateSearchParams(array $params): array {
		return self::periodToRange(params: self::renameFilters(params: $params));

	}//end translateSearchParams()

	/**
	 * Rename the portal's filter names to the publication's properties.
	 *
	 * @param array<string, mixed> $params The parameters.
	 *
	 * @return array<string, mixed>
	 */
	private static function renameFilters(array $params): array {
		foreach (self::RENAMES as $portal => $property) {
			if (array_key_exists($portal, $params) === false) {
				continue;
			}

			if (array_key_exists($property, $params) === false && self::filled(value: $params[$portal]) === true) {
				$params[$property] = $params[$portal];
			}

			unset($params[$portal]);
		}

		return $params;

	}//end renameFilters()

	/**
	 * Turn `periodFrom` and `periodTo` into a `publicationDate` range.
	 *
	 * @param array<string, mixed> $params The parameters.
	 *
	 * @return array<string, mixed>
	 */
	private static function periodToRange(array $params): array {
		foreach (self::PERIOD as $portal => $operator) {
			if (array_key_exists($portal, $params) === true) {
				$params = self::withPeriodBound(params: $params, bound: [$portal, $operator]);
			}
		}

		return $params;

	}//end periodToRange()

	/**
	 * Move one period field into the publicationDate range, unless the range already has that bound.
	 *
	 * The period field is always removed; an empty or null value, or a range that is not
	 * a list of bounds, adds nothing.
	 *
	 * @param array<string, mixed>        $params The search parameters.
	 * @param array{0: string, 1: string} $bound  The period field and its range operator.
	 *
	 * @return array<string, mixed> The parameters.
	 *
	 * @spec openspec/changes/woo-dossier-publication/specs/publications/spec.md#requirement-public-search-takes-the-portals-filter-names-req-wdp-002
	 */
	private static function withPeriodBound(array $params, array $bound): array {
		[$portal, $operator] = $bound;
		$value = $params[$portal];
		unset($params[$portal]);
		$range = ($params['publicationDate'] ?? []);
		if ($value === '' || $value === null || is_array($range) === false) {
			return $params;
		}

		if (array_key_exists($operator, $range) === false) {
			$range[$operator] = $value;
		}

		$params['publicationDate'] = $range;
		return $params;

	}//end withPeriodBound()

	/**
	 * The search parameters for a saved query (contract C2 shape).
	 *
	 * @param array<string, mixed> $query The saved query.
	 *
	 * @return array<string, mixed>
	 *
	 * @spec openspec/changes/saved-searches-and-alerts/specs/saved-searches/spec.md#requirement-matching-finds-only-publications-the-resident-could-have-found-new-since-the-last-run-req-ssa-002
	 */
	public static function fromSavedQuery(array $query): array {
		$params = [];
		foreach (self::SAVED_TEXT as $field => $param) {
			$value = trim((string)($query[$field] ?? ''));
			if ($value !== '') {
				$params[$param] = $value;
			}
		}

		return self::translateSearchParams(params: array_merge($params, self::savedFilters(query: $query)));

	}//end fromSavedQuery()

	/**
	 * The portal filters a saved query carries, without empty ones.
	 *
	 * @param array<string, mixed> $query The saved query.
	 *
	 * @return array<string, mixed> The filters by portal name.
	 *
	 * @spec openspec/changes/woo-dossier-publication/specs/publications/spec.md#requirement-public-search-takes-the-portals-filter-names-req-wdp-002
	 */
	private static function savedFilters(array $query): array {
		$filters = ($query['filters'] ?? []);
		if (is_array($filters) === false) {
			return [];
		}

		$carried = [];
		foreach (['informatiecategorie', 'organisation', 'periodFrom', 'periodTo'] as $name) {
			if (isset($filters[$name]) === true && self::filled(value: $filters[$name]) === true) {
				$carried[$name] = $filters[$name];
			}
		}

		return $carried;

	}//end savedFilters()

	/**
	 * Whether a filter value is filled: not the empty text and not an empty list.
	 *
	 * @param mixed $value The value.
	 *
	 * @return bool True when filled.
	 *
	 * @spec openspec/changes/woo-dossier-publication/specs/publications/spec.md#requirement-public-search-takes-the-portals-filter-names-req-wdp-002
	 */
	private static function filled(mixed $value): bool {
		return $value !== '' && $value !== [];

	}//end filled()
}//end class
