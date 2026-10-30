<?php

/**
 * OpenCatalogi cross-origin allowlist.
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
 * The `cors_allowed_origins` setting, and the origin it names for a caller.
 *
 * A blank setting or `*` names the wildcard. Otherwise the setting is a CSV
 * allowlist: a listed caller is named back, anyone else gets the first entry,
 * and an unlisted caller's Origin is never echoed.
 *
 * @spec openspec/specs/cross-origin-api-access/spec.md#requirement-answer-cors-preflight-requests-on-public-api-controllers-cor-001
 */
final class CorsAllowlist {

	/**
	 * The non-blank allowlist entries, in the setting's order.
	 *
	 * @var list<string>
	 */
	private array $entries = [];

	/**
	 * Parse the setting.
	 *
	 * @param string $configured The `cors_allowed_origins` setting.
	 *
	 * @spec openspec/specs/cross-origin-api-access/spec.md#requirement-answer-cors-preflight-requests-on-public-api-controllers-cor-001
	 */
	public function __construct(string $configured) {
		$configured = trim($configured);
		if ($configured === '' || $configured === '*') {
			return;
		}

		$this->entries = array_values(
			array_filter(
				array_map('trim', explode(',', $configured)),
				static fn (string $entry): bool => $entry !== ''
			)
		);
	}//end __construct()

	/**
	 * The `Access-Control-Allow-Origin` value for a caller; an unlisted one gets the first entry.
	 *
	 * @param string $callerOrigin The caller's Origin, or '' when it sent none.
	 *
	 * @return string The header value.
	 *
	 * @spec openspec/specs/cross-origin-api-access/spec.md#requirement-answer-cors-preflight-requests-on-public-api-controllers-cor-001
	 */
	public function allowedFor(string $callerOrigin): string {
		if ($callerOrigin !== '' && in_array($callerOrigin, $this->entries, true) === true) {
			return $callerOrigin;
		}

		return ($this->entries[0] ?? '*');
	}//end allowedFor()
}//end class
