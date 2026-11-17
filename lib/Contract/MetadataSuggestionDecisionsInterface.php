<?php

/**
 * Contract of MetadataSuggestionDecisions, for callers in other components.
 *
 * @category Contract
 * @package  OCA\OpenCatalogi\Contract
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
 * @spec openspec/changes/woo-metadata-suggestions/specs/woo-metadata-suggestions/spec.md#requirement-nothing-is-written-until-a-person-accepts-req-wms-003
 */

declare(strict_types=1);

namespace OCA\OpenCatalogi\Contract;

/**
 * Contract of MetadataSuggestionDecisions, for callers in other components.
 *
 * Callers outside Service/ depend on this interface instead of on the class.
 *
 * @spec openspec/changes/woo-metadata-suggestions/specs/woo-metadata-suggestions/spec.md#requirement-nothing-is-written-until-a-person-accepts-req-wms-003
 */
interface MetadataSuggestionDecisionsInterface {
	/**
	 * A publication's suggestions, whether Hermiq can be asked, and whether the caller may decide.
	 *
	 * @param string $publicationId The publication's uuid.
	 *
	 * @return array{suggestions: array<int, array<string, mixed>>, hermiq: bool, canDecide: bool} The overview.
	 *
	 * @throws \RuntimeException A MetadataSuggestionRefusedException: 404 when the caller cannot read the publication, 503 when suggestions are not set up.
	 *
	 * @spec openspec/changes/woo-metadata-suggestions/specs/woo-metadata-suggestions/spec.md#requirement-hermiq-may-add-suggestions-never-replace-them-req-wms-002
	 * @spec openspec/changes/woo-metadata-suggestions/specs/woo-metadata-suggestions/spec.md#requirement-nothing-is-written-until-a-person-accepts-req-wms-003
	 */
	public function overview(string $publicationId): array;

	/**
	 * Ask Hermiq for suggestions on the publication's missing value-list fields.
	 *
	 * Hermiq's values are checked against the same value lists as the rules
	 * floor and only added: no existing suggestion is changed.
	 *
	 * @param string $publicationId The publication's uuid.
	 * @param string $userId The editor who asked.
	 *
	 * @return array<int, array<string, mixed>> The suggestions stored for Hermiq's values.
	 *
	 * @throws \RuntimeException A MetadataSuggestionRefusedException: 404, 403, 409 `hermiq-unavailable`, or 503.
	 *
	 * @spec openspec/changes/woo-metadata-suggestions/specs/woo-metadata-suggestions/spec.md#requirement-hermiq-may-add-suggestions-never-replace-them-req-wms-002
	 */
	public function askHermiq(string $publicationId, string $userId): array;

	/**
	 * Accept a suggestion: write its value to the publication, then record who accepted it and when.
	 *
	 * @param string $id The suggestion's uuid.
	 * @param string $userId The person accepting.
	 *
	 * @return array<string, mixed> The accepted suggestion.
	 *
	 * @throws \RuntimeException A MetadataSuggestionRefusedException: 404, 403, 409 when already decided, or 503.
	 *
	 * @spec openspec/changes/woo-metadata-suggestions/specs/woo-metadata-suggestions/spec.md#requirement-nothing-is-written-until-a-person-accepts-req-wms-003
	 */
	public function accept(string $id, string $userId): array;

	/**
	 * Reject a suggestion: record who rejected it and when; the publication is left as it is.
	 *
	 * @param string $id The suggestion's uuid.
	 * @param string $userId The person rejecting.
	 *
	 * @return array<string, mixed> The rejected suggestion.
	 *
	 * @throws \RuntimeException A MetadataSuggestionRefusedException: 404, 403, 409 when already decided, or 503.
	 *
	 * @spec openspec/changes/woo-metadata-suggestions/specs/woo-metadata-suggestions/spec.md#requirement-nothing-is-written-until-a-person-accepts-req-wms-003
	 */
	public function reject(string $id, string $userId): array;
}//end interface
