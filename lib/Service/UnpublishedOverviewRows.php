<?php

/**
 * Shapes the rows of the unpublished overview lists.
 *
 * @category Service
 * @package  OCA\OpenCatalogi\Service
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

namespace OCA\OpenCatalogi\Service;

/**
 * The rows of the unpublished publications and documents lists, and the
 * rule for when a moment has passed.
 *
 * @spec openspec/changes/publications-unpublished-overview/specs/dashboard/spec.md
 */
class UnpublishedOverviewRows {

	/**
	 * Whether a moment is set and not in the future.
	 *
	 * @param mixed $moment An ISO date, or null.
	 *
	 * @return bool True when it is set and has passed.
	 *
	 * @spec openspec/changes/publications-unpublished-overview/specs/dashboard/spec.md#requirement-unpublished-content-dashboard-widgets-dsh-011
	 */
	public function isLive(mixed $moment): bool {
		if (is_string($moment) === false || $moment === '') {
			return false;
		}

		$time = strtotime($moment);
		return $time !== false && $time <= time();
	}//end isLive()

	/**
	 * The slug of the catalogue whose registers and schemas hold a publication.
	 *
	 * @param array<string, mixed> $publication The publication.
	 * @param list<array<string, mixed>> $catalogs The catalogues read.
	 *
	 * @return string|null The slug, or null when no catalogue holds it.
	 *
	 * @spec openspec/changes/publications-unpublished-overview/specs/dashboard/spec.md#requirement-unpublished-content-dashboard-widgets-dsh-011
	 */
	private function catalogSlugOf(array $publication, array $catalogs): ?string {
		$register = (string)($publication['@self']['register'] ?? '');
		$schema = (string)($publication['@self']['schema'] ?? '');

		foreach ($catalogs as $catalog) {
			if ($this->holds(catalog: $catalog, register: $register, schema: $schema) === true) {
				return $this->slugOf(catalog: $catalog);
			}
		}

		return null;
	}//end catalogSlugOf()

	/**
	 * Whether a catalogue covers a register and schema.
	 *
	 * @param array<string, mixed> $catalog The catalogue.
	 * @param string $register The register id.
	 * @param string $schema The schema id.
	 *
	 * @return bool True when both are in its scope.
	 *
	 * @spec openspec/changes/publications-unpublished-overview/specs/dashboard/spec.md#requirement-unpublished-content-dashboard-widgets-dsh-011
	 */
	private function holds(array $catalog, string $register, string $schema): bool {
		$registers = array_map('strval', (array)($catalog['registers'] ?? []));
		$schemas = array_map('strval', (array)($catalog['schemas'] ?? []));

		return in_array($register, $registers, true) === true && in_array($schema, $schemas, true) === true;
	}//end holds()

	/**
	 * A catalogue's slug, or null when it has none.
	 *
	 * @param array<string, mixed> $catalog The catalogue.
	 *
	 * @return string|null The slug.
	 *
	 * @spec openspec/changes/publications-unpublished-overview/specs/dashboard/spec.md#requirement-unpublished-content-dashboard-widgets-dsh-011
	 */
	private function slugOf(array $catalog): ?string {
		$slug = ($catalog['slug'] ?? null);
		if (is_string($slug) === false || $slug === '') {
			return null;
		}

		return $slug;
	}//end slugOf()

	/**
	 * One row of the publications list.
	 *
	 * @param array<string, mixed> $publication The publication.
	 * @param list<array<string, mixed>> $catalogs The catalogues read.
	 *
	 * @return array<string, mixed> The row.
	 *
	 * @spec openspec/changes/publications-unpublished-overview/specs/dashboard/spec.md#requirement-unpublished-content-dashboard-widgets-dsh-011
	 */
	public function publicationRow(array $publication, array $catalogs): array {
		return [
			'title' => (string)($publication['title'] ?? ($publication['@self']['name'] ?? '')),
			'updated' => ($publication['@self']['updated'] ?? null),
			'publicationId' => (string)($publication['@self']['uuid'] ?? ($publication['id'] ?? '')),
			'catalogSlug' => $this->catalogSlugOf(publication: $publication, catalogs: $catalogs),
			'publicationDate' => ($publication['publicationDate'] ?? null),
		];
	}//end publicationRow()

	/**
	 * One row of the documents list.
	 *
	 * @param array<string, mixed> $file The file record.
	 * @param array<string, mixed> $publication The publication holding it.
	 * @param list<array<string, mixed>> $catalogs The catalogues read.
	 *
	 * @return array<string, mixed> The row.
	 *
	 * @spec openspec/changes/publications-unpublished-overview/specs/dashboard/spec.md#requirement-unpublished-content-dashboard-widgets-dsh-011
	 */
	public function documentRow(array $file, array $publication, array $catalogs): array {
		$updated = null;
		if (isset($file['mtime']) === true && is_numeric($file['mtime']) === true) {
			$updated = date('c', (int)$file['mtime']);
		}

		$row = $this->publicationRow(publication: $publication, catalogs: $catalogs);

		return [
			'title' => (string)($file['name'] ?? ''),
			'updated' => $updated,
			'fileId' => (int)($file['fileid'] ?? 0),
			'publicationTitle' => $row['title'],
			'publicationId' => $row['publicationId'],
			'catalogSlug' => $row['catalogSlug'],
			'published' => ($file['published'] ?? null),
		];
	}//end documentRow()
}//end class
