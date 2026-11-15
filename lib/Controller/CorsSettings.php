<?php

/**
 * The methods, headers and max age a public list controller answers cross-origin requests with.
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
 *
 * @spec openspec/specs/cross-origin-api-access/spec.md#requirement-answer-cors-preflight-requests-on-public-api-controllers-cor-001
 */

declare(strict_types=1);

namespace OCA\OpenCatalogi\Controller;

/**
 * The allowed methods, allowed headers and preflight max age of a CORS answer.
 *
 * The allowed origin is not here: it is read per request from the allowlist.
 *
 * @spec openspec/specs/cross-origin-api-access/spec.md#requirement-answer-cors-preflight-requests-on-public-api-controllers-cor-001
 */
final class CorsSettings {

	/**
	 * Constructor.
	 *
	 * @param string $methods Allowed CORS methods.
	 * @param string $allowedHeaders Allowed CORS headers.
	 * @param int $maxAge CORS preflight max age, in seconds.
	 */
	public function __construct(
		public readonly string $methods = 'PUT, POST, GET, DELETE, PATCH',
		public readonly string $allowedHeaders = 'Authorization, Content-Type, Accept',
		public readonly int $maxAge = 1728000,
	) {

	}//end __construct()
}//end class
