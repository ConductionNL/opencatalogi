<?php

/**
 * OpenCatalogi CORS headers of the controllers with configurable CORS settings.
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
 * Add the CORS headers built from the controller's own methods, headers and max age.
 *
 * Consuming controllers MUST expose `$corsMethods`, `$corsAllowedHeaders`,
 * `$corsMaxAge`, and use ResolvesAllowedOrigin (directly or through a CORS trait).
 *
 * @spec openspec/specs/cross-origin-api-access/spec.md#requirement-answer-cors-preflight-requests-on-public-api-controllers-cor-001
 */
trait SendsListCorsHeaders {
	/**
	 * Add the origin, methods and headers a public answer carries.
	 *
	 * @param T $response The response.
	 *
	 * @return T The same response.
	 *
	 * @template T of Response
	 *
	 * @spec openspec/specs/cross-origin-api-access/spec.md#requirement-answer-cors-preflight-requests-on-public-api-controllers-cor-001
	 */
	private function withListCorsHeaders(Response $response): Response {
		$this->addAllowedOriginHeaders(response: $response);
		$response->addHeader('Access-Control-Allow-Methods', $this->corsMethods);
		$response->addHeader('Access-Control-Allow-Headers', $this->corsAllowedHeaders);

		return $response;
	}//end withListCorsHeaders()

	/**
	 * Add every preflight header: origin, methods, max age, headers and no credentials.
	 *
	 * @param T $response The response.
	 *
	 * @return T The same response.
	 *
	 * @template T of Response
	 *
	 * @spec openspec/specs/cross-origin-api-access/spec.md#requirement-answer-cors-preflight-requests-on-public-api-controllers-cor-001
	 */
	private function withListPreflightHeaders(Response $response): Response {
		$this->addAllowedOriginHeaders(response: $response);
		$response->addHeader('Access-Control-Allow-Methods', $this->corsMethods);
		$response->addHeader('Access-Control-Max-Age', (string)$this->corsMaxAge);
		$response->addHeader('Access-Control-Allow-Headers', $this->corsAllowedHeaders);
		$response->addHeader('Access-Control-Allow-Credentials', 'false');

		return $response;
	}//end withListPreflightHeaders()
}//end trait
