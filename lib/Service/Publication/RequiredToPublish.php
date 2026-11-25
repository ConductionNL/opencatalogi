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
 * @spec openspec/changes/archive/2026-11-25-publication-required-at-publish/specs/publications/spec.md#requirement-a-draft-needs-only-a-title-req-prp-001
 */

declare(strict_types=1);

namespace OCA\OpenCatalogi\Service\Publication;

/**
 * Names the fields of design D1 that a publication record leaves empty.
 *
 * The same three names the publication schema's lifecycle declares for `in_review`, `approved` and `published`.
 * OpenRegister stays the authority on a save; this only reads a record, for counts and hints.
 *
 * @spec openspec/changes/archive/2026-11-25-publication-required-at-publish/specs/publications/spec.md#requirement-a-draft-needs-only-a-title-req-prp-001
 */
final class RequiredToPublish {

	/**
	 * The fields required from review on, in the order a message names them.
	 */
	public const FIELDS = ['summary', 'description', 'organization'];

	/**
	 * The lifecycle states that declare the rule (design D1); a draft declares none.
	 */
	public const STATES = ['in_review', 'approved', 'published'];

	/**
	 * OpenRegister's body key that records a value as deliberately not supplied.
	 */
	public const NOT_SUPPLIED_KEY = '@notSupplied';

	/**
	 * The reason the publication schema administers for a field left empty before the rule existed.
	 */
	public const NOT_SUPPLIED_REASON = 'published-before-required';

	/**
	 * The required fields the record leaves empty.
	 *
	 * A value is empty when it is absent, null, a blank string or an empty list or object.
	 *
	 * @param array<string, mixed> $publication The publication record.
	 *
	 * @return array<int, string> The missing field names, in the order of FIELDS.
	 *
	 * @spec openspec/changes/archive/2026-11-25-publication-required-at-publish/specs/publications/spec.md#requirement-a-draft-needs-only-a-title-req-prp-001
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
	 * The record as a system save must write it: every required field it leaves empty recorded as not supplied.
	 *
	 * The upgrade repair steps re-save legacy publications as the system, and OpenRegister applies a state's
	 * `required` on every save. A publication published before the rule existed would be refused there and keep no
	 * state or audience, which hides it. Recording the gap under the reason the schema administers lets the save
	 * through and keeps the gap visible: nothing is invented, and the next person to edit the publication fills it
	 * (design D2). A record in a state without the rule is returned as it is, and a field that carries a value is
	 * never marked; an existing record's other entries are kept.
	 *
	 * @param array<string, mixed> $publication The publication record about to be saved.
	 *
	 * @return array<string, mixed> The record to save.
	 *
	 * @spec openspec/changes/archive/2026-11-25-publication-required-at-publish/design.md#d2-existing-publications
	 */
	public static function recordGaps(array $publication): array {
		$state = trim((string)($publication['status'] ?? ''));
		if (in_array($state, self::STATES, true) === false) {
			return $publication;
		}

		$missing = self::missing(publication: $publication);
		if ($missing === []) {
			return $publication;
		}

		$record = $publication[self::NOT_SUPPLIED_KEY] ?? [];
		if (is_array($record) === false || ($record !== [] && array_is_list($record) === true)) {
			$record = [];
		}

		foreach ($missing as $field) {
			// OpenRegister refuses a marked field that carries a value, and it counts a blank string as one.
			$publication[$field] = null;
			$reason = $record[$field] ?? null;
			if (is_string($reason) === false || $reason === '') {
				$record[$field] = self::NOT_SUPPLIED_REASON;
			}
		}

		$publication[self::NOT_SUPPLIED_KEY] = $record;

		return $publication;
	}//end recordGaps()

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
