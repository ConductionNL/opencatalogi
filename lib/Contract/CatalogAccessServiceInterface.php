<?php

/**
 * Contract of CatalogAccessService, for callers in other components.
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
 * Contract of CatalogAccessService, for callers in other components.
 *
 * Callers outside Service/ depend on this interface instead of on the class.
 */
interface CatalogAccessServiceInterface {
	/**
	 * Recompute and, when it changed, write the authorization of one schema.
	 *
	 * @param int $schemaId The schema id.
	 *
	 * @return bool Whether the schema was written.
	 *
	 * @spec openspec/changes/catalogue-staff-access/specs/catalogs/spec.md#requirement-staff-access-becomes-openregister-rules-on-the-covered-schemas-req-csa-002
	 */
	public function recompute(int $schemaId): bool;

	/**
	 * Recompute and, where it changed, write the authorization of several
	 * schemas, reading the catalogues once; never throws.
	 *
	 * @param array<int, int> $schemaIds The schema ids.
	 *
	 * @return int How many schemas were written.
	 *
	 * @spec openspec/changes/catalogue-staff-access/specs/catalogs/spec.md#requirement-staff-access-becomes-openregister-rules-on-the-covered-schemas-req-csa-002
	 */
	public function recomputeMany(array $schemaIds): int;

	/**
	 * The authorization a schema should have, given the catalogues.
	 *
	 * @param int                              $schemaId The schema id.
	 * @param array<string, mixed>             $current  Its authorization now.
	 * @param array<int, array<string, mixed>> $catalogs Every catalogue.
	 *
	 * @return array<string, mixed> The authorization.
	 *
	 * @spec openspec/changes/catalogue-staff-access/specs/catalogs/spec.md#requirement-staff-access-becomes-openregister-rules-on-the-covered-schemas-req-csa-002
	 */
	public function authorizationFor(int $schemaId, array $current, array $catalogs): array;

	/**
	 * Every schema id any catalogue covers, and every schema still carrying a
	 * remembered authorization from before a restriction.
	 *
	 * @return array<int, int> The ids.
	 *
	 * @spec openspec/changes/catalogue-staff-access/specs/catalogs/spec.md#requirement-staff-access-becomes-openregister-rules-on-the-covered-schemas-req-csa-002
	 */
	public function coveredSchemaIds(): array;

	/**
	 * Every schema id a restricted catalogue covers.
	 *
	 * @return array<int, int>|null The ids; null when the catalogues cannot be read.
	 *
	 * @spec openspec/changes/catalogue-staff-access/specs/catalogs/spec.md#requirement-staff-access-becomes-openregister-rules-on-the-covered-schemas-req-csa-002
	 */
	public function restrictedSchemaIds(): ?array;

	/**
	 * The schema ids a catalogue covers.
	 *
	 * @param array<string, mixed> $catalog The catalogue.
	 *
	 * @return array<int, int> The ids.
	 *
	 * @spec openspec/changes/catalogue-staff-access/specs/catalogs/spec.md#requirement-staff-access-becomes-openregister-rules-on-the-covered-schemas-req-csa-002
	 */
	public function schemaIdsOf(array $catalog): array;
}//end interface
