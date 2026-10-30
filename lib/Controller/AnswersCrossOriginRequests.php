<?php

/**
 * OpenCatalogi cross-origin allowlist trait.
 *
 * One copy of the rule that decides which origin a public endpoint names in
 * its `Access-Control-Allow-Origin` header. It was written three times, once
 * per public API controller, and three copies of an allowlist is three places
 * for the allowlist to stop being one.
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
 * Resolve the `Access-Control-Allow-Origin` value through ResolvesAllowedOrigin,
 * and answer the read-write preflight.
 *
 * Consuming controllers MUST expose a `private readonly IAppConfig $config`
 * constructor-promoted property, and inherit `$appName` and `$request` from
 * `OCP\AppFramework\Controller`.
 *
 * @spec openspec/specs/cross-origin-api-access/spec.md#requirement-answer-cors-preflight-requests-on-public-api-controllers-cor-001
 */
trait AnswersCrossOriginRequests {
	use ResolvesAllowedOrigin;

	/**
	 * The `cors_allowed_origins` setting.
	 *
	 * @return string The setting.
	 */
	private function allowedOriginsSetting(): string {
		return $this->config->getValueString($this->appName, 'cors_allowed_origins', '*');

	}//end allowedOriginsSetting()

	/**
	 * The answer to a GET/POST CORS preflight: origin, methods, max age, headers and no credentials.
	 *
	 * @return Response The preflight response.
	 *
	 * @spec openspec/specs/cross-origin-api-access/spec.md#requirement-answer-cors-preflight-requests-on-public-api-controllers-cor-001
	 */
	private function readWritePreflight(): Response {
		$response = new Response();
		$response->addHeader('Access-Control-Allow-Origin', $this->resolveAllowedOrigin());
		$response->addHeader('Access-Control-Allow-Methods', 'GET, POST, OPTIONS');
		$response->addHeader('Access-Control-Max-Age', '1728000');
		$response->addHeader('Access-Control-Allow-Headers', 'Authorization, Content-Type, Accept');
		$response->addHeader('Access-Control-Allow-Credentials', 'false');

		return $response;
	}//end readWritePreflight()
}//end trait
