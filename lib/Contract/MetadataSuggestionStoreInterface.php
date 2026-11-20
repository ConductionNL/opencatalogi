<?php

/**
 * Contract of MetadataSuggestionStore, for callers in other components.
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
 * Contract of MetadataSuggestionStore, for callers in other components.
 *
 * Callers outside Service/ depend on this interface instead of on the class.
 *
 * @spec openspec/changes/woo-metadata-suggestions/specs/woo-metadata-suggestions/spec.md#requirement-nothing-is-written-until-a-person-accepts-req-wms-003
 */
interface MetadataSuggestionStoreInterface {
	/**
	 * Whether the suggestion register and schema are configured.
	 *
	 * @return bool True when both the register and the schema are set.
	 *
	 * @spec openspec/changes/woo-metadata-suggestions/specs/woo-metadata-suggestions/spec.md#requirement-nothing-is-written-until-a-person-accepts-req-wms-003
	 */
	public function isConfigured(): bool;

	/**
	 * The suggestions stored for one publication.
	 *
	 * @param string $publicationId The publication's uuid.
	 *
	 * @return array<int, array<string, mixed>> The suggestions.
	 *
	 * @throws \RuntimeException When the store is not configured or OpenRegister is unavailable.
	 *
	 * @spec openspec/changes/woo-metadata-suggestions/specs/woo-metadata-suggestions/spec.md#requirement-nothing-is-written-until-a-person-accepts-req-wms-003
	 */
	public function forPublication(string $publicationId): array;

	/**
	 * Store one proposal as a pending suggestion.
	 *
	 * @param array<string, mixed> $proposal      The proposal: field, value, valueLabel, source, reason.
	 * @param string               $publicationId The publication's uuid.
	 *
	 * @return array<string, mixed> The stored suggestion.
	 *
	 * @throws \RuntimeException When the store is not configured or the write fails.
	 *
	 * @spec openspec/changes/woo-metadata-suggestions/specs/woo-metadata-suggestions/spec.md#requirement-nothing-is-written-until-a-person-accepts-req-wms-003
	 */
	public function create(array $proposal, string $publicationId): array;
}//end interface
