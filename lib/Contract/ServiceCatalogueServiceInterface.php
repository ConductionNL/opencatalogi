<?php

/**
 * Contract of ServiceCatalogueService, for callers in other components.
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
 * Contract of ServiceCatalogueService, for callers in other components.
 *
 * Callers outside Service/ depend on this interface instead of on the class.
 */
interface ServiceCatalogueServiceInterface {
	/**
	 * Resolve the OpenRegister ObjectService.
	 *
	 * There is no null return and no empty-list fallback. A catalogue we cannot
	 * read is unreadable, and saying "no services" to a resident who is owed a
	 * published catalogue is the failure this method refuses to commit.
	 *
	 * @return object The ObjectService.
	 *
	 * @throws \OCA\OpenCatalogi\Service\Catalogue\CatalogueUnreadableException When OpenRegister cannot be reached.
	 *
	 * @spec exclude pure framework plumbing: resolves the consumed OR ObjectService.
	 */
	public function getObjectService(): object;

	/**
	 * Inject an ObjectService, for the caller that already holds one.
	 *
	 * @param object $objectService The OpenRegister ObjectService.
	 *
	 * @return void
	 *
	 * @spec exclude test and caller seam for the consumed OR ObjectService.
	 */
	public function setObjectService(object $objectService): void;

	/**
	 * Index the published case type definitions by their identifier.
	 *
	 * @param array<int, array<string, mixed>> $caseTypes The case type definitions as arrays.
	 *
	 * @return array<string, array<string, mixed>> The definitions keyed by identifier.
	 *
	 * @spec openspec/changes/published-service-and-case-type-catalogue/specs/published-service-and-case-type-catalogue/spec.md#requirement-a-public-catalogue-lists-everything-that-can-be-requested-req-psc-101
	 */
	public function indexCaseTypes(array $caseTypes): array;

	/**
	 * Decide whether one entry's form binding resolves, and say why when it does not.
	 *
	 * The entry is returned either way, with `available` and, when it is false,
	 * `unavailableReason`. Nothing is removed from the list here: REQ-PSC-101
	 * says an entry with no resolvable form is listed as unavailable.
	 *
	 * @param array<string, mixed> $entry One catalogue entry.
	 * @param array<string, array<string, mixed>> $caseTypesById Published case types keyed by identifier.
	 *
	 * @return array<string, mixed> The entry with its availability decided.
	 *
	 * @spec openspec/changes/published-service-and-case-type-catalogue/specs/published-service-and-case-type-catalogue/spec.md#requirement-a-public-catalogue-lists-everything-that-can-be-requested-req-psc-101
	 */
	public function decideAvailability(array $entry, array $caseTypesById): array;

	/**
	 * Decide availability for every entry and group them.
	 *
	 * @param array<int, array<string, mixed>> $entries The catalogue entries.
	 * @param array<int, array<string, mixed>> $caseTypes The published case type definitions.
	 *
	 * @return array{entries: array<int, array<string, mixed>>, groups: array<string, array<int, array<string, mixed>>>, unavailable: integer}
	 *
	 * @spec openspec/changes/published-service-and-case-type-catalogue/specs/published-service-and-case-type-catalogue/spec.md#requirement-a-public-catalogue-lists-everything-that-can-be-requested-req-psc-101
	 */
	public function assemble(array $entries, array $caseTypes): array;

	/**
	 * The entries an administrator has to repair, with the reason each one carries.
	 *
	 * @param array<int, array<string, mixed>> $entries The catalogue entries.
	 * @param array<int, array<string, mixed>> $caseTypes The published case type definitions.
	 *
	 * @return array<int, array<string, mixed>> The unavailable entries.
	 *
	 * @spec openspec/changes/published-service-and-case-type-catalogue/specs/published-service-and-case-type-catalogue/spec.md#requirement-a-public-catalogue-lists-everything-that-can-be-requested-req-psc-101
	 */
	public function unavailableEntries(array $entries, array $caseTypes): array;

	/**
	 * Read the catalogue entries and the published case types from OpenRegister.
	 *
	 * @param array<string, string> $entryConfig The register and schema of the entries.
	 * @param array<string, string> $caseTypeConfig The register and schema of the case type definitions.
	 * @param array<string, mixed> $filters Extra search filters from the caller.
	 *
	 * @return array{
	 *     entries: array<int, array<string, mixed>>,
	 *     groups: array<string, array<int, array<string, mixed>>>,
	 *     unavailable: integer,
	 *     total: integer
	 * }
	 *
	 * @throws \OCA\OpenCatalogi\Service\Catalogue\CatalogueUnreadableException When OpenRegister cannot be reached.
	 *
	 * @spec openspec/changes/published-service-and-case-type-catalogue/specs/published-service-and-case-type-catalogue/spec.md#requirement-a-public-catalogue-lists-everything-that-can-be-requested-req-psc-101
	 */
	public function readCatalogue(array $entryConfig, array $caseTypeConfig, array $filters = []): array;
}//end interface
