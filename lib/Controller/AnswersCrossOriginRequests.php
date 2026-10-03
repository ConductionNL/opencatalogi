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

/**
 * Resolve the `Access-Control-Allow-Origin` value for the current request.
 *
 * Consuming controllers MUST expose a `private readonly IAppConfig $config`
 * constructor-promoted property, and inherit `$appName` and `$request` from
 * `OCP\AppFramework\Controller`.
 *
 * @spec openspec/specs/cross-origin-api-access/spec.md#requirement-answer-cors-preflight-requests-on-public-api-controllers-cor-001
 */
trait AnswersCrossOriginRequests {
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
		$configured = trim($this->config->getValueString($this->appName, 'cors_allowed_origins', '*'));
		if ($configured === '' || $configured === '*') {
			return '*';
		}

		$allowlist = array_values(
			array_filter(
				array_map('trim', explode(',', $configured)),
				static fn (string $entry): bool => $entry !== ''
			)
		);

		$callerOrigin = $this->request->getHeader('Origin');
		if ($callerOrigin !== '' && in_array($callerOrigin, $allowlist, true) === true) {
			return $callerOrigin;
		}

		return ($allowlist[0] ?? '*');

	}//end resolveAllowedOrigin()
}//end trait
