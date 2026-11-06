<?php

/**
 * Reads a stored list field that may be JSON text.
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
 *
 * @spec openspec/specs/dcat-ap-harvest/spec.md
 */

namespace OCA\OpenCatalogi\Service;

/**
 * A list field as an array: decoded when it is JSON text, empty when it is not a list.
 *
 * @spec openspec/specs/dcat-ap-harvest/spec.md
 */
final class JsonList {

	/**
	 * The field as an array.
	 *
	 * @param mixed $raw The field: an array, JSON text of one, or anything else.
	 *
	 * @return array<mixed>
	 *
	 * @spec openspec/specs/dcat-ap-harvest/spec.md
	 */
	public static function fromField(mixed $raw): array {
		if (is_string($raw) === true) {
			$raw = json_decode($raw, true);
		}

		if (is_array($raw) === false) {
			return [];
		}

		return $raw;
	}//end fromField()
}//end class
