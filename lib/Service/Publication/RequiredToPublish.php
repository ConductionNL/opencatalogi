<?php

/**
 * The fields a publication needs before it leaves draft (REQ-PRP-001).
 *
 * @category Service
 * @package  OCA\OpenCatalogi\Service\Publication
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
 * @spec openspec/changes/publication-required-at-publish/specs/publications/spec.md#requirement-a-draft-needs-only-a-title-req-prp-001
 */

declare(strict_types=1);

namespace OCA\OpenCatalogi\Service\Publication;

/**
 * Names the fields of design D1 that a publication record leaves empty.
 *
 * The same three names the publication schema's lifecycle declares for `in_review`, `approved` and `published`.
 * OpenRegister stays the authority on a save; this only reads a record, for counts and hints.
 *
 * @spec openspec/changes/publication-required-at-publish/specs/publications/spec.md#requirement-a-draft-needs-only-a-title-req-prp-001
 */
final class RequiredToPublish {

	/**
	 * The fields required from review on, in the order a message names them.
	 */
	public const FIELDS = ['summary', 'description', 'organization'];

	/**
	 * The required fields the record leaves empty.
	 *
	 * A value is empty when it is absent, null, a blank string or an empty list or object.
	 *
	 * @param array<string, mixed> $publication The publication record.
	 *
	 * @return array<int, string> The missing field names, in the order of FIELDS.
	 *
	 * @spec openspec/changes/publication-required-at-publish/specs/publications/spec.md#requirement-a-draft-needs-only-a-title-req-prp-001
	 */
	public static function missing(array $publication): array {
		$missing = [];
		foreach (self::FIELDS as $field) {
			if (self::isEmpty(value: $publication[$field] ?? null) === true) {
				$missing[] = $field;
			}
		}

		return $missing;
	}//end missing()

	/**
	 * Whether one value counts as not filled in.
	 *
	 * @param mixed $value The value.
	 *
	 * @return bool True when empty.
	 */
	private static function isEmpty(mixed $value): bool {
		if ($value === null) {
			return true;
		}

		if (is_string($value) === true) {
			return trim($value) === '';
		}

		if (is_array($value) === true) {
			return $value === [];
		}

		return false;
	}//end isEmpty()
}//end class
