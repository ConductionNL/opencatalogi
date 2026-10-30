<?php

/**
 * OpenCatalogi cross-origin headers of the public read-only API controllers.
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
 * The GET-only CORS header set, its preflight answer and the allowed origin.
 *
 * Consuming controllers MUST expose a `private readonly ?IAppConfig $appConfig`
 * property (without one every origin is allowed), and inherit `$appName` and
 * `$request` from `OCP\AppFramework\Controller`.
 *
 * @spec openspec/specs/cross-origin-api-access/spec.md#requirement-answer-cors-preflight-requests-on-public-api-controllers-cor-001
 */
trait AnswersCrossOriginReadRequests {
	use ResolvesAllowedOrigin;

	/**
	 * The `cors_allowed_origins` setting; '*' without an app config.
	 *
	 * @return string The setting.
	 */
	private function allowedOriginsSetting(): string {
		return ($this->appConfig?->getValueString($this->appName, 'cors_allowed_origins', '*') ?? '*');
	}//end allowedOriginsSetting()

	/**
	 * Build the GET-only public CORS header set.
	 *
	 * @return array<string, string> The header map.
	 *
	 * @spec openspec/specs/cross-origin-api-access/spec.md#requirement-answer-cors-preflight-requests-on-public-api-controllers-cor-001
	 */
	private function corsHeaders(): array {
		return [
			'Access-Control-Allow-Origin' => $this->resolveAllowedOrigin(),
			'Access-Control-Allow-Methods' => 'GET, OPTIONS',
			'Access-Control-Allow-Headers' => 'Authorization, Content-Type, Accept',
		];

	}//end corsHeaders()

	/**
	 * Add the GET-only public CORS headers to a response.
	 *
	 * @param T $response The response to add them to.
	 *
	 * @return T The same response.
	 *
	 * @template T of Response
	 *
	 * @spec openspec/specs/cross-origin-api-access/spec.md#requirement-answer-cors-preflight-requests-on-public-api-controllers-cor-001
	 */
	private function withCorsHeaders(Response $response): Response {
		foreach ($this->corsHeaders() as $name => $value) {
			$response->addHeader($name, $value);
		}

		return $response;
	}//end withCorsHeaders()

	/**
	 * The answer to a GET-only CORS preflight: the header set, a max age and no credentials.
	 *
	 * @return Response The preflight response.
	 *
	 * @spec openspec/specs/cross-origin-api-access/spec.md#requirement-answer-cors-preflight-requests-on-public-api-controllers-cor-001
	 */
	private function readOnlyPreflight(): Response {
		$response = $this->withCorsHeaders(response: new Response());
		$response->addHeader('Access-Control-Max-Age', '1728000');
		$response->addHeader('Access-Control-Allow-Credentials', 'false');
		return $response;
	}//end readOnlyPreflight()
}//end trait
