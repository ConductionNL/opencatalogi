<?php

/**
 * Contract of PublicationVersionService, for callers in other components.
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
use OCA\OpenRegister\Db\ObjectEntity;

/**
 * Contract of PublicationVersionService, for callers in other components.
 *
 * Callers outside Service/ depend on this interface instead of on the class.
 */
interface PublicationVersionServiceInterface {
	/**
	 * Whether an update replaces a public version that a reader could have cited.
	 *
	 * @param ObjectEntity $old The publication as stored.
	 * @param ObjectEntity $new The publication as it would be written.
	 *
	 * @return bool True when the old version has to be kept.
	 *
	 * @spec openspec/changes/publication-withdrawal-aftercare/specs/publications/spec.md#requirement-a-corrected-publication-keeps-its-earlier-public-versions-reachable-req-pwa-001
	 */
	public function replacesPublicVersion(ObjectEntity $old, ObjectEntity $new): bool;

	/**
	 * Store the old public version of a publication with copies of its public files.
	 *
	 * @param ObjectEntity $old The publication as stored before the correction.
	 * @param DateTimeInterface|null $now The moment the version is superseded, now when omitted.
	 *
	 * @return array<string, mixed>|null The stored version, or null when the publication is not public.
	 *
	 * @throws \RuntimeException When the version cannot be stored.
	 *
	 * @spec openspec/changes/publication-withdrawal-aftercare/specs/publications/spec.md#requirement-a-corrected-publication-keeps-its-earlier-public-versions-reachable-req-pwa-001
	 */
	public function snapshot(ObjectEntity $old, ?DateTimeInterface $now = null): ?array;

	/**
	 * The stored versions of a publication, oldest first.
	 *
	 * @param string $publication The publication uuid.
	 *
	 * @return list<array<string, mixed>> The versions, each with its `id`.
	 *
	 * @throws \RuntimeException When the version schema is not configured.
	 *
	 * @spec openspec/changes/publication-withdrawal-aftercare/specs/publications/spec.md#requirement-a-corrected-publication-keeps-its-earlier-public-versions-reachable-req-pwa-001
	 */
	public function versionsOf(string $publication): array;

	/**
	 * One stored document of a version, or null when the version does not list it.
	 *
	 * @param array<string, mixed> $version A version from versionsOf().
	 * @param int $fileId The id of the copy kept on the version.
	 *
	 * @return object|null The file node.
	 *
	 * @spec openspec/changes/publication-withdrawal-aftercare/specs/publications/spec.md#requirement-a-corrected-publication-keeps-its-earlier-public-versions-reachable-req-pwa-001
	 */
	public function documentOf(array $version, int $fileId): ?object;
}//end interface
