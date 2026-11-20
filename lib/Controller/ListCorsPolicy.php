<?php

/**
 * The CORS answer of a public list controller: its settings and its configured origin allowlist.
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

use OCP\IAppConfig;

/**
 * The allowed methods, headers and max age of a CORS answer, and the allowed
 * origin for a caller, read from the `cors_allowed_origins` allowlist. Without
 * an app config the allowlist is `*`.
 *
 * @spec openspec/specs/cross-origin-api-access/spec.md#requirement-answer-cors-preflight-requests-on-public-api-controllers-cor-001
 */
final class ListCorsPolicy {

	/**
	 * The allowed methods.
	 *
	 * @var string
	 */
	public readonly string $methods;

	/**
	 * The allowed request headers.
	 *
	 * @var string
	 */
	public readonly string $allowedHeaders;

	/**
	 * The preflight max age in seconds.
	 *
	 * @var integer
	 */
	public readonly int $maxAge;

	/**
	 * Constructor.
	 *
	 * @param IAppConfig|null $config App config for the CORS allowlist (optional).
	 * @param CorsSettings $settings Allowed CORS methods, headers and max age.
	 *
	 * @spec exclude pure framework plumbing: constructor dependency injection.
	 */
	public function __construct(
		private readonly ?IAppConfig $config = null,
		CorsSettings $settings = new CorsSettings(),
	) {
		$this->methods = $settings->methods;
		$this->allowedHeaders = $settings->allowedHeaders;
		$this->maxAge = $settings->maxAge;

	}//end __construct()

	/**
	 * The configured `cors_allowed_origins` setting of the app; '*' without an app config.
	 *
	 * @param string $appName The app whose setting is read.
	 *
	 * @return string The allowlist as configured.
	 *
	 * @spec openspec/specs/cors-allowlist/spec.md
	 */
	public function allowedOriginsSetting(string $appName): string {
		return ($this->config?->getValueString($appName, 'cors_allowed_origins', '*') ?? '*');
	}//end allowedOriginsSetting()

	/**
	 * The Access-Control-Allow-Origin value for a caller.
	 *
	 * The caller's origin is never echoed back unless it is on the allowlist (#735).
	 *
	 * @param string $appName The app whose `cors_allowed_origins` key is read.
	 * @param string $callerOrigin The caller's Origin.
	 *
	 * @return string The header value.
	 *
	 * @spec openspec/specs/cross-origin-api-access/spec.md#requirement-answer-cors-preflight-requests-on-public-api-controllers-cor-001
	 */
	public function allowedOrigin(string $appName, string $callerOrigin): string {
		return (new CorsAllowlist($this->allowedOriginsSetting(appName: $appName)))->allowedFor($callerOrigin);
	}//end allowedOrigin()
}//end class
