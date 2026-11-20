<?php

/**
 * OpenCatalogi CORS allowlist setting as the administrator enters it.
 *
 * @category Service
 * @package  OCA\OpenCatalogi\Service
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

namespace OCA\OpenCatalogi\Service;

use InvalidArgumentException;

/**
 * Turns the websites an administrator lists into the `cors_allowed_origins` value.
 *
 * One website per line (a comma also separates). Each line is `*` or a bare
 * origin: `http` or `https`, a host and an optional port, with no path, query or
 * fragment. The value stored is the comma-separated list the shared allowlist rule
 * (`CorsAllowlist`) reads; a blank list or one holding `*` stores `*`.
 *
 * @spec openspec/changes/search-cors-on-every-public-endpoint/specs/cross-origin-api-access/spec.md#requirement-the-administrator-edits-the-list-of-allowed-websites-req-sco-003
 */
final class CorsOriginSetting {
	/**
	 * A bare origin: scheme, host and optional port.
	 */
	private const ORIGIN_PATTERN = '~^https?://[a-z0-9]([a-z0-9-]*[a-z0-9])?(\.[a-z0-9]([a-z0-9-]*[a-z0-9])?)*(:[0-9]{1,5})?$~';

	/**
	 * The value to store for what the administrator entered.
	 *
	 * Scheme and host are lower-cased, because a browser sends its Origin in lower case
	 * and the allowlist compares exactly.
	 *
	 * @param mixed $value The entered list.
	 *
	 * @return string The comma-separated allowlist, or `*`.
	 *
	 * @throws InvalidArgumentException Naming the first line that is not `*` or a bare origin.
	 *
	 * @spec openspec/changes/search-cors-on-every-public-endpoint/specs/cross-origin-api-access/spec.md#requirement-the-administrator-edits-the-list-of-allowed-websites-req-sco-003
	 */
	public static function storable(mixed $value): string {
		if (is_string($value) === false) {
			throw new InvalidArgumentException('Setting cors_allowed_origins takes text: one website per line');
		}

		$origins = [];
		foreach (preg_split('/[\r\n,]+/', $value) as $line) {
			$line = trim($line);
			if ($line === '') {
				continue;
			}

			$origins[] = self::origin(line: $line);
		}

		if ($origins === [] || in_array('*', $origins, true) === true) {
			return '*';
		}

		return implode(',', array_values(array_unique($origins)));
	}//end storable()

	/**
	 * One checked line: `*`, or the origin in lower case.
	 *
	 * @param string $line The trimmed line.
	 *
	 * @return string The origin to store.
	 *
	 * @throws InvalidArgumentException When the line is not `*` or a bare origin.
	 *
	 * @spec openspec/changes/search-cors-on-every-public-endpoint/specs/cross-origin-api-access/spec.md#requirement-the-administrator-edits-the-list-of-allowed-websites-req-sco-003
	 */
	private static function origin(string $line): string {
		if ($line === '*') {
			return '*';
		}

		$origin = strtolower($line);
		if (preg_match(self::ORIGIN_PATTERN, $origin) !== 1) {
			throw new InvalidArgumentException(
				'"' . $line . '" is not a website origin. Give the scheme, host and optional port without a path, for example https://www.example.nl'
			);
		}

		return $origin;
	}//end origin()
}//end class
