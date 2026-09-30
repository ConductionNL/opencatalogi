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
		foreach (self::RENAMES as $portal => $property) {
			if (array_key_exists($portal, $params) === false) {
				continue;
			}

			if (array_key_exists($property, $params) === false && $params[$portal] !== '' && $params[$portal] !== []) {
				$params[$property] = $params[$portal];
			}

			unset($params[$portal]);
		}

		foreach (self::PERIOD as $portal => $operator) {
			if (array_key_exists($portal, $params) === false) {
				continue;
			}

			$value = $params[$portal];
			unset($params[$portal]);
			if ($value === '' || $value === null) {
				continue;
			}

			$range = ($params['publicationDate'] ?? []);
			if (is_array($range) === false) {
				continue;
			}

			if (array_key_exists($operator, $range) === false) {
				$range[$operator] = $value;
			}

			$params['publicationDate'] = $range;
		}//end foreach

		return $params;

	}//end translateSearchParams()

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
		$filters = ($query['filters'] ?? []);
		if (is_array($filters) === false) {
			$filters = [];
		}

		$params = [];
		$text = trim((string)($query['text'] ?? ''));
		if ($text !== '') {
			$params['_search'] = $text;
		}

		$catalog = trim((string)($query['catalog'] ?? ''));
		if ($catalog !== '') {
			$params['_catalog'] = $catalog;
		}

		foreach (['informatiecategorie', 'organisation', 'periodFrom', 'periodTo'] as $name) {
			if (isset($filters[$name]) === true && $filters[$name] !== '' && $filters[$name] !== []) {
				$params[$name] = $filters[$name];
			}
		}

		return self::translateSearchParams(params: $params);

	}//end fromSavedQuery()
}//end class
