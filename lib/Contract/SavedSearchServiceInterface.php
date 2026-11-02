<?php

/**
 * Contract of SavedSearchService, for callers in other components.
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
 */

declare(strict_types=1);

namespace OCA\OpenCatalogi\Contract;


/**
 * Contract of SavedSearchService, for callers in other components.
 *
 * Callers outside Service/ depend on this interface instead of on the class.
 */
interface SavedSearchServiceInterface {
	/**
	 * Save a search for this resident.
	 *
	 * @param string               $owner The subject reference.
	 * @param array<string, mixed> $input title, query (object or JSON), frequency.
	 *
	 * @return array<string, mixed> The saved search.
	 *
	 * @throws \OCA\OpenCatalogi\Exception\PortalInputException When the input is refused.
	 *
	 * @spec openspec/changes/saved-searches-and-alerts/specs/saved-searches/spec.md#requirement-a-signed-in-resident-saves-a-search-with-a-frequency-req-ssa-001
	 */
	public function save(string $owner, array $input): array;

	/**
	 * Stop the notices of a saved search: one click from the notice.
	 *
	 * @param string $owner         The subject reference.
	 * @param string $savedSearchId The saved search.
	 *
	 * @return array{active: bool}
	 *
	 * @throws \OCA\OpenCatalogi\Exception\PortalNotFoundException When it is not the owner's.
	 *
	 * @spec openspec/changes/saved-searches-and-alerts/specs/saved-searches/spec.md#requirement-a-signed-in-resident-saves-a-search-with-a-frequency-req-ssa-001
	 */
	public function pause(string $owner, string $savedSearchId): array;

	/**
	 * Delete a saved search.
	 *
	 * @param string $owner         The subject reference.
	 * @param string $savedSearchId The saved search.
	 *
	 * @return array{deleted: bool}
	 *
	 * @throws \OCA\OpenCatalogi\Exception\PortalNotFoundException When it is not the owner's.
	 *
	 * @spec openspec/changes/saved-searches-and-alerts/specs/saved-searches/spec.md#requirement-a-signed-in-resident-saves-a-search-with-a-frequency-req-ssa-001
	 */
	public function delete(string $owner, string $savedSearchId): array;
}//end interface
