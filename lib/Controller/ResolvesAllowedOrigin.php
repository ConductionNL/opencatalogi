<?php

/**
 * OpenCatalogi allowed-origin resolution shared by every public controller.
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
 * Resolve the `Access-Control-Allow-Origin` value for the current request.
 *
 * The one place that reads the caller's Origin and hands it, with the
 * `cors_allowed_origins` setting, to CorsAllowlist. The consumer supplies the
 * setting through `allowedOriginsSetting()` (its app config lives under
 * different property names) and inherits `$request` from
 * `OCP\AppFramework\Controller`.
 *
 * @spec openspec/specs/cross-origin-api-access/spec.md#requirement-answer-cors-preflight-requests-on-public-api-controllers-cor-001
 */
trait ResolvesAllowedOrigin {
	/**
	 * Resolve the Access-Control-Allow-Origin header value for the current request.
	 *
	 * The caller's Origin is never echoed back unless it is on the allowlist.
	 *
	 * @return string The header value.
	 *
	 * @spec openspec/specs/cross-origin-api-access/spec.md#requirement-answer-cors-preflight-requests-on-public-api-controllers-cor-001
	 */
	private function resolveAllowedOrigin(): string {
		return (new CorsAllowlist($this->allowedOriginsSetting()))->allowedFor($this->callerOrigin());

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

	/**
	 * The `cors_allowed_origins` setting; '*' when the consumer has no app config.
	 *
	 * @return string The setting.
	 */
	abstract private function allowedOriginsSetting(): string;
}//end trait
