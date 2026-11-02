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

use OCP\AppFramework\Http\Response;

/**
 * Resolve the `Access-Control-Allow-Origin` value for the current request.
 *
 * The one place that reads the caller's Origin and hands it, with the
 * `cors_allowed_origins` setting, to CorsAllowlist, and that sets the
 * allowed-origin header (with `Vary: Origin` when it is not the wildcard). The consumer supplies the
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
	 * The allowed-origin header, plus `Vary: Origin` when the value depends on the caller.
	 *
	 * Any value but the wildcard comes from the allowlist and may be the caller's
	 * own Origin, so a shared cache must key the answer on the Origin.
	 *
	 * @return array<string, string> The headers.
	 *
	 * @spec openspec/specs/cross-origin-api-access/spec.md#requirement-answer-cors-preflight-requests-on-public-api-controllers-cor-001
	 */
	private function allowedOriginHeaders(): array {
		$origin = $this->resolveAllowedOrigin();
		if ($origin === '*') {
			return ['Access-Control-Allow-Origin' => $origin];
		}

		return ['Access-Control-Allow-Origin' => $origin, 'Vary' => 'Origin'];

	}//end allowedOriginHeaders()

	/**
	 * Put the allowed-origin header on a response, adding `Origin` to its Vary when needed.
	 *
	 * @param Response $response The response.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/cross-origin-api-access/spec.md#requirement-answer-cors-preflight-requests-on-public-api-controllers-cor-001
	 */
	private function addAllowedOriginHeaders(Response $response): void {
		foreach ($this->allowedOriginHeaders() as $name => $value) {
			if ($name === 'Vary') {
				$this->addOriginToVary(response: $response);
				continue;
			}

			$response->addHeader($name, $value);
		}

	}//end addAllowedOriginHeaders()

	/**
	 * Name Origin in the response's Vary, merging every Vary already set in any letter case.
	 *
	 * Nextcloud keeps a header name as given and PHP's header() replaces case-insensitively,
	 * so a `Vary` written beside an existing `vary` would drop what that one named. The
	 * existing entries are read, removed and written back as one `Vary`.
	 *
	 * @param Response $response The response.
	 *
	 * @return void
	 */
	private function addOriginToVary(Response $response): void {
		$current = [];
		foreach ($response->getHeaders() as $name => $value) {
			if (strcasecmp((string)$name, 'Vary') === 0) {
				$current[] = (string)$value;
				$response->addHeader((string)$name, null);
			}
		}

		$response->addHeader('Vary', $this->varyWithOrigin(current: implode(',', $current)));

	}//end addOriginToVary()

	/**
	 * A Vary value that names Origin, keeping what it already named.
	 *
	 * @param string $current The current Vary value.
	 *
	 * @return string The value.
	 */
	private function varyWithOrigin(string $current): string {
		$names = array_values(array_filter(array_map('trim', explode(',', $current)), static fn (string $name): bool => $name !== ''));
		foreach ($names as $name) {
			if (strcasecmp($name, 'Origin') === 0 || $name === '*') {
				return implode(', ', $names);
			}
		}

		$names[] = 'Origin';
		return implode(', ', $names);

	}//end varyWithOrigin()

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
