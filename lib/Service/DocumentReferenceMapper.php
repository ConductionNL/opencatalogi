<?php

/**
 * OpenCatalogi Document Reference Mapper.
 *
 * Gives a document reference the shape of a formatted file, so the sitemap,
 * DCAT and attachments code that already maps files maps references the same
 * way, with the source URL as the document's location.
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
 *
 * @spec openspec/changes/integration-publish-by-reference/specs/publication-document-references/spec.md#requirement-a-reference-is-published-like-a-file-pointing-at-its-source-req-pbr-002
 */

declare(strict_types=1);

namespace OCA\OpenCatalogi\Service;

/**
 * Maps a documentReference to the formatFiles entry shape (design D2).
 *
 * @spec openspec/changes/integration-publish-by-reference/specs/publication-document-references/spec.md#requirement-a-reference-is-published-like-a-file-pointing-at-its-source-req-pbr-002
 */
class DocumentReferenceMapper {

	/**
	 * The `kind` a reference carries in an attachments list.
	 */
	public const KIND = 'reference';

	/**
	 * A reference as a formatted file entry.
	 *
	 * `downloadUrl` and `accessUrl` are the source URL, `extension` is the
	 * format, `published` is the reference's publication date and `modified`
	 * the time OpenRegister last saved it. The reachability fields of the last
	 * check travel along for the validator report.
	 *
	 * @param array<string, mixed> $reference The reference's properties, with `@self` when there is one.
	 *
	 * @return array<string, mixed> The entry.
	 *
	 * @spec openspec/changes/integration-publish-by-reference/specs/publication-document-references/spec.md#requirement-a-reference-is-published-like-a-file-pointing-at-its-source-req-pbr-002
	 */
	public function asFileEntry(array $reference): array {
		$url = (string)($reference['url'] ?? '');
		$format = strtolower(trim((string)($reference['format'] ?? '')));
		$title = trim((string)($reference['title'] ?? ''));
		if ($title === '') {
			$title = $url;
		}

		return [
			'id' => (string)($reference['id'] ?? $reference['@self']['id'] ?? ''),
			'kind' => self::KIND,
			'title' => $title,
			'name' => $title,
			'downloadUrl' => $url,
			'accessUrl' => $url,
			'extension' => $format,
			'published' => ($reference['publicationDate'] ?? null),
			'modified' => ($reference['@self']['updated'] ?? null),
			'sourceSystem' => ($reference['sourceSystem'] ?? null),
			'reachable' => ($reference['reachable'] ?? null),
			'lastStatus' => ($reference['lastStatus'] ?? null),
			'lastCheckedAt' => ($reference['lastCheckedAt'] ?? null),
		];
	}//end asFileEntry()

	/**
	 * Whether a reference is usable as a document: it has an https URL and a format.
	 *
	 * @param array<string, mixed> $reference The reference.
	 *
	 * @return bool True when it can be listed.
	 *
	 * @spec openspec/changes/integration-publish-by-reference/specs/publication-document-references/spec.md#requirement-a-reference-is-published-like-a-file-pointing-at-its-source-req-pbr-002
	 */
	public function isListable(array $reference): bool {
		$url = (string)($reference['url'] ?? '');
		return str_starts_with(strtolower($url), 'https://') === true
			&& trim((string)($reference['format'] ?? '')) !== '';
	}//end isListable()
}//end class
