<?php

/**
 * Contract of CaseTypeCatalogueService, for callers in other components.
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

use DateTimeInterface;

/**
 * Contract of CaseTypeCatalogueService, for callers in other components.
 *
 * Callers outside Service/ depend on this interface instead of on the class.
 */
interface CaseTypeCatalogueServiceInterface {
	/**
	 * Import one definition from a registered external catalogue.
	 *
	 * The returned definition carries its source, the version imported, the
	 * moment of the import, and the snapshot of what arrived. The snapshot is
	 * what a later resynchronisation compares the local values against, so a
	 * property an administrator edited afterwards can be told apart from one
	 * that never moved.
	 *
	 * @param string $sourceId The registered external catalogue.
	 * @param string $externalId The identifier of the definition at the source.
	 * @param DateTimeInterface|null $now The moment of the import; defaults to now.
	 *
	 * @return array<string, mixed> The local definition to save.
	 *
	 * @throws \OCA\OpenCatalogi\Service\Catalogue\ExternalCatalogueUnreachableException When the source cannot be asked.
	 *
	 * @spec openspec/changes/published-service-and-case-type-catalogue/specs/published-service-and-case-type-catalogue/spec.md#requirement-case-types-are-imported-from-a-published-external-catalogue-and-resynchronised-req-psc-103
	 */
	public function importDefinition(string $sourceId, string $externalId, ?DateTimeInterface $now = null): array;

	/**
	 * The difference between a local definition and its source, before anything applies.
	 *
	 * Every entry names the property, what the local definition holds, what the
	 * source now holds, and whether the local value was changed after the
	 * import. A locally changed property is flagged because accepting the
	 * source's value for it throws away somebody's deliberate edit.
	 *
	 * @param array<string, mixed> $local The local definition, carrying its importedSnapshot.
	 * @param string $sourceId The registered external catalogue.
	 * @param string $externalId The identifier of the definition at the source.
	 *
	 * @return array{sourceVersion: string, changes: array<int, array<string, mixed>>, locallyChanged: array<int, string>}
	 *
	 * @throws \OCA\OpenCatalogi\Service\Catalogue\ExternalCatalogueUnreachableException When the source cannot be asked.
	 *
	 * @spec openspec/changes/published-service-and-case-type-catalogue/specs/published-service-and-case-type-catalogue/spec.md#requirement-case-types-are-imported-from-a-published-external-catalogue-and-resynchronised-req-psc-103
	 */
	public function diffAgainstSource(array $local, string $sourceId, string $externalId): array;

	/**
	 * Apply the properties an administrator accepted, and nothing else.
	 *
	 * A property that is not named in `$accepted` keeps its local value, even
	 * when the source changed it. An empty accepted list changes nothing, which
	 * is the state a previewed but unaccepted resynchronisation leaves behind.
	 *
	 * @param array<string, mixed> $local The local definition.
	 * @param array{sourceVersion: string, changes: array<int, array<string, mixed>>, locallyChanged: array<int, string>} $diff The diff.
	 * @param array<int, string> $accepted The properties the administrator accepted.
	 * @param DateTimeInterface|null $now The moment of the resynchronisation.
	 *
	 * @return array<string, mixed> The definition to save.
	 *
	 * @spec openspec/changes/published-service-and-case-type-catalogue/specs/published-service-and-case-type-catalogue/spec.md#requirement-case-types-are-imported-from-a-published-external-catalogue-and-resynchronised-req-psc-103
	 */
	public function applyResync(array $local, array $diff, array $accepted, ?DateTimeInterface $now = null): array;

	/**
	 * The links a published case type carries.
	 *
	 * An administrator uses the form, an integrator the API description, and
	 * both are published beside the definition rather than hunted for.
	 *
	 * @param array<string, mixed> $definition The case type definition.
	 *
	 * @return array{form: string|null, apiDescription: string|null, complete: boolean}
	 *
	 * @spec openspec/changes/published-service-and-case-type-catalogue/specs/published-service-and-case-type-catalogue/spec.md#requirement-a-published-case-type-links-to-its-form-and-its-api-description-req-psc-102
	 */
	public function publishedLinks(array $definition): array;

	/**
	 * The date an imported definition was last synchronised, for the surface to show.
	 *
	 * @param array<string, mixed> $definition The case type definition.
	 *
	 * @return string|null The ISO 8601 moment, or null when the definition was never imported.
	 *
	 * @spec openspec/changes/published-service-and-case-type-catalogue/specs/published-service-and-case-type-catalogue/spec.md#requirement-case-types-are-imported-from-a-published-external-catalogue-and-resynchronised-req-psc-103
	 */
	public function syncedAt(array $definition): ?string;
}//end interface
