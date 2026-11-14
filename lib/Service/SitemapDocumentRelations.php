<?php

/**
 * Which documents of one publication belong together in a DiWoo sitemap.
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
 * @spec openspec/changes/woo-sitemap-document-signals/specs/woo-compliance/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenCatalogi\Service;

/**
 * The DiWoo `isPartOf` and `hasParts` elements of the documents of one
 * publication (woo-sitemap-document-signals D2/D3).
 *
 * One document: no relation. Two or more: every document is part of the
 * publication, at its public API URL. When exactly one file carries the label
 * `main-document`, that document lists the others as its parts and each other
 * document is part of it. Two or more labelled files fall back to the
 * publication, and the DiWoo validator reports the publication.
 *
 * The element names and their content model come from the DiWoo metadata XSD
 * (`diwoo:isPartOf` and `diwoo:hasPart` are a `documentverwijzingType`: a label
 * with a `resource` attribute; `diwoo:hasParts` holds one or more `hasPart`).
 *
 * @spec openspec/changes/woo-sitemap-document-signals/specs/woo-compliance/spec.md
 */
final class SitemapDocumentRelations {

	/**
	 * The file label an editor gives the main document.
	 */
	public const MAIN_DOCUMENT_LABEL = 'main-document';

	/**
	 * Each downloadable file of a publication with the relation elements of its
	 * document, in file order.
	 *
	 * @param array<string, mixed> $publication The publication, with its files under `@self.files`.
	 * @param string               $apiBase     The catalog's public API base, `{baseUrl}/apps/opencatalogi/api/{catalogSlug}`.
	 *
	 * @return array<int, array{0: array<string, mixed>, 1: array<string, array<string, mixed>>}>
	 *
	 * @spec openspec/changes/woo-sitemap-document-signals/specs/woo-compliance/spec.md#requirement-documents-of-one-publication-say-they-belong-together-req-wsd-002
	 */
	public static function forPublication(array $publication, string $apiBase): array {
		$files = self::filesOf(publication: $publication);
		$id = (string)($publication['id'] ?? ($publication['@self']['id'] ?? ''));
		$relations = self::forFiles(
			files: $files,
			publicationUrl: $apiBase . '/' . rawurlencode($id),
			publicationTitle: self::titleOf(publication: $publication)
		);

		$pairs = [];
		foreach ($files as $index => $file) {
			$pairs[] = [$file, ($relations[$index] ?? [])];
		}

		return $pairs;
	}//end forPublication()

	/**
	 * The validator's report for a publication with two or more files marked
	 * as the main document, at its first file, or null (REQ-WSD-003).
	 *
	 * @param array<string, mixed> $publication The publication, with its files under `@self.files`.
	 *
	 * @return array{documentLoc: string, axis: string, reason: string}|null
	 *
	 * @spec openspec/changes/woo-sitemap-document-signals/specs/woo-compliance/spec.md#requirement-a-main-document-lists-its-parts-req-wsd-003
	 */
	public static function mainDocumentViolation(array $publication): ?array {
		$files = self::filesOf(publication: $publication);
		if (self::mainDocumentCount(files: $files) < 2) {
			return null;
		}

		$name = self::titleOf(publication: $publication);
		if ($name === '') {
			$name = (string)($publication['id'] ?? '');
		}

		return [
			'documentLoc' => (string)$files[0]['downloadUrl'],
			'axis' => 'hasParts',
			'reason' => sprintf('publication %s has two or more files marked as the main document', $name),
		];
	}//end mainDocumentViolation()

	/**
	 * A publication's files with a download URL, in their order, numbered from 0.
	 *
	 * @param array<string, mixed> $publication The publication, with its files under `@self.files`.
	 *
	 * @return array<int, array<string, mixed>>
	 *
	 * @spec openspec/changes/woo-sitemap-document-signals/specs/woo-compliance/spec.md#requirement-documents-of-one-publication-say-they-belong-together-req-wsd-002
	 */
	private static function filesOf(array $publication): array {
		$files = [];
		foreach (($publication['@self']['files'] ?? []) as $file) {
			if (is_array($file) === true && isset($file['downloadUrl']) === true) {
				$files[] = $file;
			}
		}

		return $files;
	}//end filesOf()

	/**
	 * A publication's title, the label of a relation to it.
	 *
	 * @param array<string, mixed> $publication The publication.
	 *
	 * @return string
	 *
	 * @spec openspec/changes/woo-sitemap-document-signals/specs/woo-compliance/spec.md#requirement-documents-of-one-publication-say-they-belong-together-req-wsd-002
	 */
	private static function titleOf(array $publication): string {
		$title = ($publication['title'] ?? ($publication['@self']['name'] ?? ''));
		if (is_string($title) === false) {
			return '';
		}

		return trim($title);
	}//end titleOf()

	/**
	 * The relation elements of each document, by the file's position in `$files`.
	 *
	 * @param array<int, array<string, mixed>> $files            The publication's downloadable files, each with `downloadUrl`.
	 * @param string                           $publicationUrl   The publication's public API URL.
	 * @param string                           $publicationTitle The publication's title, the label of a relation to it.
	 *
	 * @return array<int, array<string, array<string, mixed>>> The elements to add to each document's `diwoo:DiWoo`.
	 *
	 * @spec openspec/changes/woo-sitemap-document-signals/specs/woo-compliance/spec.md#requirement-documents-of-one-publication-say-they-belong-together-req-wsd-002
	 */
	public static function forFiles(array $files, string $publicationUrl, string $publicationTitle): array {
		if (count($files) < 2) {
			return [];
		}

		$main = self::mainDocumentIndex(files: $files);
		if ($main === null) {
			$toPublication = ['diwoo:isPartOf' => ['@resource' => $publicationUrl, '#text' => $publicationTitle]];
			return array_fill_keys(array_keys($files), $toPublication);
		}

		$relations = [];
		$parts = [];
		foreach ($files as $index => $file) {
			if ($index === $main) {
				continue;
			}

			$parts[] = self::reference(file: $file);
			$relations[$index] = ['diwoo:isPartOf' => self::reference(file: $files[$main])];
		}

		$relations[$main] = ['diwoo:hasParts' => ['diwoo:hasPart' => $parts]];
		ksort($relations);

		return $relations;
	}//end forFiles()

	/**
	 * How many of the files carry the main-document label.
	 *
	 * @param array<int, array<string, mixed>> $files The publication's files.
	 *
	 * @return int
	 *
	 * @spec openspec/changes/woo-sitemap-document-signals/specs/woo-compliance/spec.md#requirement-a-main-document-lists-its-parts-req-wsd-003
	 */
	public static function mainDocumentCount(array $files): int {
		return count(array_filter($files, static fn (array $file): bool => self::isMainDocument(file: $file)));
	}//end mainDocumentCount()

	/**
	 * The position of the one file labelled as the main document, or null when
	 * none or more than one is.
	 *
	 * @param array<int, array<string, mixed>> $files The publication's files.
	 *
	 * @return int|null
	 *
	 * @spec openspec/changes/woo-sitemap-document-signals/specs/woo-compliance/spec.md#requirement-a-main-document-lists-its-parts-req-wsd-003
	 */
	private static function mainDocumentIndex(array $files): ?int {
		$marked = array_keys(array_filter($files, static fn (array $file): bool => self::isMainDocument(file: $file)));
		if (count($marked) !== 1) {
			return null;
		}

		return $marked[0];
	}//end mainDocumentIndex()

	/**
	 * Whether a file carries the main-document label.
	 *
	 * @param array<string, mixed> $file The file.
	 *
	 * @return bool
	 *
	 * @spec openspec/changes/woo-sitemap-document-signals/specs/woo-compliance/spec.md#requirement-a-main-document-lists-its-parts-req-wsd-003
	 */
	private static function isMainDocument(array $file): bool {
		$labels = ($file['labels'] ?? []);

		return is_array($labels) === true && in_array(self::MAIN_DOCUMENT_LABEL, $labels, true) === true;
	}//end isMainDocument()

	/**
	 * A reference to one file's document: its download URL as `resource`, its
	 * name as the label.
	 *
	 * @param array<string, mixed> $file The file.
	 *
	 * @return array{'@resource': string, '#text': string}
	 *
	 * @spec openspec/changes/woo-sitemap-document-signals/specs/woo-compliance/spec.md#requirement-a-main-document-lists-its-parts-req-wsd-003
	 */
	private static function reference(array $file): array {
		$name = ($file['title'] ?? ($file['name'] ?? ($file['filename'] ?? '')));
		if (is_string($name) === false) {
			$name = '';
		}

		return ['@resource' => (string)($file['downloadUrl'] ?? ''), '#text' => $name];
	}//end reference()
}//end class
