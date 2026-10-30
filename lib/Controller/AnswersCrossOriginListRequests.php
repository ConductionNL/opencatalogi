<?php

/**
 * OpenCatalogi cross-origin rule of the public list controllers.
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

/**
 * Resolve the `Access-Control-Allow-Origin` value the way the list controllers do.
 *
 * Two things set it apart from AnswersCrossOriginRequests: the caller is read
 * from the server's HTTP_ORIGIN when the Origin header is empty, and only the
 * setting's first entry can be the fallback, so a blank first entry names the
 * wildcard.
 *
 * Consuming controllers MUST expose a `private readonly IAppConfig $config`
 * property, and inherit `$appName` and `$request` from `OCP\AppFramework\Controller`.
 *
 * @spec openspec/specs/cross-origin-api-access/spec.md#requirement-answer-cors-preflight-requests-on-public-api-controllers-cor-001
 */
trait AnswersCrossOriginListRequests {
	/**
	 * Resolve the Access-Control-Allow-Origin header value for the current request.
	 *
	 * @return string The header value.
	 *
	 * @spec openspec/specs/cross-origin-api-access/spec.md#requirement-answer-cors-preflight-requests-on-public-api-controllers-cor-001
	 */
	private function resolveAllowedOrigin(): string {
		$allowlist = new CorsAllowlist($this->config->getValueString($this->appName, 'cors_allowed_origins', '*'));
		return $allowlist->allowedForPositionally($this->callerOrigin());
	}//end resolveAllowedOrigin()

	/**
	 * The caller's Origin header, or the server's HTTP_ORIGIN when the header is empty.
	 *
	 * @return string The caller's origin, or '' when it sent none.
	 *
	 * @spec openspec/specs/cross-origin-api-access/spec.md#requirement-answer-cors-preflight-requests-on-public-api-controllers-cor-001
	 */
	private function callerOrigin(): string {
		$callerOrigin = $this->request->getHeader('Origin');
		if ($callerOrigin === '') {
			$callerOrigin = ($this->request->server['HTTP_ORIGIN'] ?? '');
		}

		return $callerOrigin;
	}//end callerOrigin()
}//end trait
