<?php

/**
 * OpenCatalogi public object list trait.
 *
 * The parts a public, read-only controller over one configured register and
 * schema shares: the search query scoped to that pair, the paginated answer,
 * and the CORS headers on every answer.
 *
 * @category Controller
 * @package  OCA\OpenCatalogi\Controller
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

namespace OCA\OpenCatalogi\Controller;

use OCA\OpenCatalogi\Service\CallerScope;
use OCP\AppFramework\Http\JSONResponse;

/**
 * Build scoped searches and CORS-enabled paginated answers for a public object list.
 *
 * The consuming controller provides `$appName`, `$request`, `$config`,
 * `$corsMethods` and `$corsAllowedHeaders`.
 */
trait ListsConfiguredObjects {
	/**
	 * The request's query, without route keys or any caller scope, scoped to the configured pair.
	 *
	 * The configured scope wins for the rows, but OpenRegister's facet path reads
	 * `@self.schemas ?? _schemas` first, so every caller scope key is dropped (WOO-581).
	 *
	 * @param array<string, string> $objectConfig The configured `register` and `schema`.
	 *
	 * @return array<string, mixed> The search query.
	 *
	 * @SuppressWarnings(PHPMD.StaticAccess) CallerScope::strip() is a pure function over the query (WOO-581)
	 *
	 * @spec openspec/specs/content-management/spec.md
	 */
	private function scopedSearchQuery(array $objectConfig): array {
		$searchQuery = $this->request->getParams();
		unset($searchQuery['id'], $searchQuery['_route']);

		$searchQuery = CallerScope::strip(query: $searchQuery);

		if (empty($objectConfig['schema']) === false) {
			$searchQuery['@self']['schema'] = $objectConfig['schema'];
		}

		if (empty($objectConfig['register']) === false) {
			$searchQuery['@self']['register'] = $objectConfig['register'];
		}

		return $searchQuery;

	}//end scopedSearchQuery()

	/**
	 * The paginated answer for a search result: rows and paging with defaults,
	 * plus the links, facets and facetable fields when present.
	 *
	 * @param array<string, mixed> $result The paginated search result.
	 *
	 * @return array<string, mixed> The response data.
	 *
	 * @spec openspec/specs/content-management/spec.md
	 */
	private function paginatedResponseData(array $result): array {
		$responseData = [
			'results' => ($result['results'] ?? []),
			'total' => ($result['total'] ?? 0),
			'limit' => ($result['limit'] ?? 20),
			'offset' => ($result['offset'] ?? 0),
			'page' => ($result['page'] ?? 1),
			'pages' => ($result['pages'] ?? 1),
		];

		if (isset($result['next']) === true) {
			$responseData['next'] = $result['next'];
		}

		if (isset($result['prev']) === true) {
			$responseData['prev'] = $result['prev'];
		}

		if (isset($result['facets']) === true) {
			$facetsData = $result['facets'];
			// Unwrap nested facets if needed.
			if (isset($facetsData['facets']) === true && is_array($facetsData['facets']) === true) {
				$facetsData = $facetsData['facets'];
			}

			$responseData['facets'] = $facetsData;
		}

		if (isset($result['facetable']) === true) {
			$responseData['facetable'] = $result['facetable'];
		}

		return $responseData;

	}//end paginatedResponseData()

	/**
	 * A JSON answer with the CORS headers for public API access (#735: never reflect an arbitrary Origin).
	 *
	 * @param mixed $data The response data.
	 *
	 * @return JSONResponse The response.
	 *
	 * @spec openspec/specs/cross-origin-api-access/spec.md#requirement-answer-cors-preflight-requests-on-public-api-controllers-cor-001
	 */
	private function publicJsonResponse(mixed $data): JSONResponse {
		$response = new JSONResponse($data);

		$response->addHeader('Access-Control-Allow-Origin', $this->resolveAllowedOrigin());
		$response->addHeader('Access-Control-Allow-Methods', $this->corsMethods);
		$response->addHeader('Access-Control-Allow-Headers', $this->corsAllowedHeaders);

		return $response;

	}//end publicJsonResponse()

	/**
	 * Resolve the Access-Control-Allow-Origin header value for the current request.
	 *
	 * Reads the allowlist from the `cors_allowed_origins` app config (CSV). The
	 * value `*` (the default) means any origin and answers a literal `*`; the
	 * caller's Origin is never echoed back unless it is on the allowlist (#735).
	 *
	 * @return string The header value.
	 *
	 * @spec openspec/specs/cross-origin-api-access/spec.md#requirement-answer-cors-preflight-requests-on-public-api-controllers-cor-001
	 */
	private function resolveAllowedOrigin(): string {
		$configured = trim($this->config->getValueString($this->appName, 'cors_allowed_origins', '*'));
		if ($configured === '' || $configured === '*') {
			return '*';
		}

		$allowlist = array_filter(
			array_map('trim', explode(',', $configured)),
			static fn (string $entry): bool => $entry !== ''
		);

		$callerOrigin = $this->request->getHeader('Origin');
		if ($callerOrigin === '') {
			$callerOrigin = ($this->request->server['HTTP_ORIGIN'] ?? '');
		}

		if ($callerOrigin !== '' && in_array($callerOrigin, $allowlist, true) === true) {
			return $callerOrigin;
		}

		return ($allowlist[0] ?? '*');

	}//end resolveAllowedOrigin()
}//end trait
