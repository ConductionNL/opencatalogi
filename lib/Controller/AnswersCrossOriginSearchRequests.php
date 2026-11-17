<?php

/**
 * OpenCatalogi cross-origin headers of the search and federation endpoints.
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

use OCP\AppFramework\Http\Response;

/**
 * The GET-only CORS headers, plus `Vary: Origin` and no credentials, on every answer.
 *
 * Builds on AnswersCrossOriginReadRequests, so the allowed origin comes from the
 * one shared allowlist rule. Consuming controllers MUST expose a
 * `private readonly ?IAppConfig $appConfig` property (without one every origin
 * is allowed), and inherit `$appName` and `$request` from
 * `OCP\AppFramework\Controller`.
 *
 * @spec openspec/changes/search-cors-on-every-public-endpoint/specs/cross-origin-api-access/spec.md#requirement-the-search-and-federation-endpoints-answer-cross-origin-calls-req-sco-001
 */
trait AnswersCrossOriginSearchRequests {
	use AnswersCrossOriginReadRequests;

	/**
	 * Add the CORS headers, `Vary: Origin` and `Access-Control-Allow-Credentials: false` to an answer.
	 *
	 * A `Vary` header the answer already carries is kept, with `Origin` added when it is not named.
	 *
	 * @param T $response The answer.
	 *
	 * @return T The same answer.
	 *
	 * @template T of Response
	 *
	 * @spec openspec/changes/search-cors-on-every-public-endpoint/specs/cross-origin-api-access/spec.md#requirement-the-search-and-federation-endpoints-answer-cross-origin-calls-req-sco-001
	 */
	private function withSearchCorsHeaders(Response $response): Response {
		$this->withCorsHeaders(response: $response);
		$response->addHeader('Access-Control-Allow-Credentials', 'false');
		$response->addHeader('Vary', self::varyWithOrigin(vary: ($response->getHeaders()['Vary'] ?? '')));

		return $response;
	}//end withSearchCorsHeaders()

	/**
	 * The answer to a preflight on a search or federation route.
	 *
	 * @return Response The preflight answer.
	 *
	 * @spec openspec/changes/search-cors-on-every-public-endpoint/specs/cross-origin-api-access/spec.md#requirement-the-search-and-federation-endpoints-answer-cross-origin-calls-req-sco-001
	 */
	private function searchPreflight(): Response {
		$response = $this->readOnlyPreflight();
		$response->addHeader('Vary', 'Origin');

		return $response;
	}//end searchPreflight()

	/**
	 * A Vary value that names Origin, keeping the names it already had.
	 *
	 * @param string $vary The current Vary value, '' when there is none.
	 *
	 * @return string The Vary value.
	 *
	 * @spec openspec/changes/search-cors-on-every-public-endpoint/specs/cross-origin-api-access/spec.md#requirement-the-search-and-federation-endpoints-answer-cross-origin-calls-req-sco-001
	 */
	private static function varyWithOrigin(string $vary): string {
		$names = array_values(array_filter(array_map('trim', explode(',', $vary)), static fn (string $name): bool => $name !== ''));
		foreach ($names as $name) {
			if (strcasecmp($name, 'Origin') === 0 || $name === '*') {
				return implode(', ', $names);
			}
		}

		$names[] = 'Origin';
		return implode(', ', $names);
	}//end varyWithOrigin()
}//end trait
