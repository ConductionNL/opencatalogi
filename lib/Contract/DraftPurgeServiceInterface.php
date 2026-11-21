<?php

/**
 * Contract of DraftPurgeService, for callers in other components.
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
 * @spec openspec/changes/publication-lifecycle-on-or-draft-purge/specs/publications/spec.md#requirement-a-draft-that-was-never-released-is-deleted-permanently-with-everything-on-it-req-plc-004
 */

declare(strict_types=1);

namespace OCA\OpenCatalogi\Contract;

/**
 * Contract of DraftPurgeService, for callers in other components.
 *
 * Callers outside Service/ depend on this interface instead of on the class.
 *
 * @spec openspec/changes/publication-lifecycle-on-or-draft-purge/specs/publications/spec.md#requirement-a-draft-that-was-never-released-is-deleted-permanently-with-everything-on-it-req-plc-004
 */
interface DraftPurgeServiceInterface {
	/**
	 * Find the publication with the caller's rights.
	 *
	 * @param string $id The publication id.
	 *
	 * @return object|null The publication, or null when it is not there or the caller cannot read it.
	 *
	 * @throws \Throwable When the register cannot be reached.
	 *
	 * @spec openspec/changes/publication-lifecycle-on-or-draft-purge/specs/publications/spec.md#requirement-a-draft-that-was-never-released-is-deleted-permanently-with-everything-on-it-req-plc-004
	 */
	public function find(string $id): ?object;

	/**
	 * Delete a never released draft permanently with everything on it.
	 *
	 * The caller has already checked the update right on the publication.
	 *
	 * @param object $publication The publication as OpenRegister found it.
	 *
	 * @return array<int, array{kind: string, id: string, name: string}> Everything removed, the publication last.
	 *
	 * @throws \OCA\OpenCatalogi\Exception\DraftPurgeRefusedException When a proof does not hold; nothing was removed.
	 * @throws \OCA\OpenCatalogi\Exception\DraftPurgeFailedException When a removal failed part way.
	 *
	 * @spec openspec/changes/publication-lifecycle-on-or-draft-purge/specs/publications/spec.md#requirement-a-draft-that-was-never-released-is-deleted-permanently-with-everything-on-it-req-plc-004
	 */
	public function purge(object $publication): array;
}//end interface
