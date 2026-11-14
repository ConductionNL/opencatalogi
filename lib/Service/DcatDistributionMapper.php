<?php

/**
 * Maps a published file attachment to a DCAT-AP-NL distribution.
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
 * @spec openspec/specs/dcat-ap-harvest/spec.md
 */

namespace OCA\OpenCatalogi\Service;

/**
 * Turns one formatFiles-shape file entry into a `dcat:Distribution` node whose
 * IRI is the file's public download URL.
 *
 * @spec openspec/specs/dcat-ap-harvest/spec.md#requirement-attachments-rendered-as-distributions-with-stable-iris-dcat-006
 */
final class DcatDistributionMapper {

	/**
	 * The IANA media type of each known file extension.
	 *
	 * @var array<string, string>
	 */
	private const MEDIA_TYPES = [
		'pdf' => 'application/pdf',
		'csv' => 'text/csv',
		'json' => 'application/json',
		'xml' => 'application/xml',
		'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
		'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
		'zip' => 'application/zip',
		'txt' => 'text/plain',
		'html' => 'text/html',
		'odt' => 'application/vnd.oasis.opendocument.text',
		'ods' => 'application/vnd.oasis.opendocument.spreadsheet',
	];

	/**
	 * Map a single published file attachment to a `dcat:Distribution`.
	 *
	 * @param array<string, mixed> $file A formatFiles-shape file entry.
	 * @param string|null $defaultLicense Fallback license URI for the distribution.
	 *
	 * @return array<string, mixed>|null The distribution node, or null when the
	 *                                   file has no public download URL.
	 *
	 * @spec openspec/specs/dcat-ap-harvest/spec.md#requirement-attachments-rendered-as-distributions-with-stable-iris-dcat-006
	 */
	public function map(array $file, ?string $defaultLicense): ?array {
		$downloadUrl = ($file['downloadUrl'] ?? $file['accessUrl'] ?? null);
		if (is_string($downloadUrl) === false || $downloadUrl === '') {
			return null;
		}

		$distribution = [
			// IRI is the stable public download URL → harvesters dedupe across runs.
			'@id' => $downloadUrl,
			'@type' => 'dcat:Distribution',
			'dcat:downloadURL' => ['@id' => $downloadUrl],
			'dcat:accessURL' => ['@id' => (string)($file['accessUrl'] ?? $downloadUrl)],
		];

		return array_replace(
			$distribution,
			$this->fileDetails(file: $file),
			$this->licenseNode(license: $defaultLicense),
			$this->dictionaryNode(file: $file)
		);
	}//end map()

	/**
	 * The `dct:conformsTo` of a file published as a table: its data dictionary URL.
	 *
	 * @param array<string, mixed> $file A formatFiles-shape file entry, with `dataDictionaryUrl` when it is a table.
	 *
	 * @return array<string, mixed> The property, or an empty array.
	 *
	 * @spec openspec/changes/open-data-table-query-and-dictionary/specs/open-data-tables/spec.md#requirement-readers-see-what-each-column-means-req-odt-003
	 */
	private function dictionaryNode(array $file): array {
		$url = ($file['dataDictionaryUrl'] ?? null);
		if (is_string($url) === false || $url === '') {
			return [];
		}

		return ['dct:conformsTo' => ['@id' => $url]];
	}//end dictionaryNode()

	/**
	 * The title, media type, format and byte size of a distribution, each only when known.
	 *
	 * @param array<string, mixed> $file A formatFiles-shape file entry.
	 *
	 * @return array<string, mixed> The distribution properties, in DCAT output order.
	 *
	 * @spec openspec/specs/dcat-ap-harvest/spec.md#requirement-attachments-rendered-as-distributions-with-stable-iris-dcat-006
	 */
	private function fileDetails(array $file): array {
		$details = [];
		$title = ($file['title'] ?? $file['name'] ?? null);
		if ($this->filled(value: $title) === true) {
			$details['dct:title'] = (string)$title;
		}

		$details += $this->mediaTypeNodes(file: $file);
		if (isset($file['size']) === true && is_numeric($file['size']) === true) {
			$details['dcat:byteSize'] = (int)$file['size'];
		}

		return $details;
	}//end fileDetails()

	/**
	 * The `dcat:mediaType` and `dct:format` of a file, or none when the media type is unknown.
	 *
	 * @param array<string, mixed> $file A formatFiles-shape file entry.
	 *
	 * @return array<string, mixed> The two properties, or an empty array.
	 *
	 * @spec openspec/specs/dcat-ap-harvest/spec.md#requirement-attachments-rendered-as-distributions-with-stable-iris-dcat-006
	 */
	private function mediaTypeNodes(array $file): array {
		$extension = strtolower((string)($file['extension'] ?? ''));
		$mediaType = $this->mediaTypeFor(extension: $extension, mimetype: ($file['mimetype'] ?? $file['mimeType'] ?? null));
		if ($mediaType === null) {
			return [];
		}

		return [
			'dcat:mediaType' => $mediaType,
			'dct:format' => $this->formatUriFor(extension: $extension),
		];
	}//end mediaTypeNodes()

	/**
	 * The `dct:license` node, or none when no license is set.
	 *
	 * @param string|null $license The license URI.
	 *
	 * @return array<string, array{'@id': string}> The license property, or an empty array.
	 *
	 * @spec openspec/specs/dcat-ap-harvest/spec.md#requirement-attachments-rendered-as-distributions-with-stable-iris-dcat-006
	 */
	private function licenseNode(?string $license): array {
		if ($this->filled(value: $license) === false) {
			return [];
		}

		return ['dct:license' => ['@id' => $license]];
	}//end licenseNode()

	/**
	 * Resolve a media type for a file from its extension or supplied mimetype.
	 *
	 * @param string $extension The lowercase file extension.
	 * @param string|null $mimetype An explicit mimetype when available.
	 *
	 * @return string|null The IANA media type, or null when unknown.
	 */
	private function mediaTypeFor(string $extension, ?string $mimetype): ?string {
		if (is_string($mimetype) === true && $mimetype !== '') {
			return $mimetype;
		}

		return (self::MEDIA_TYPES[$extension] ?? null);
	}//end mediaTypeFor()

	/**
	 * Build the EU file-type authority URI for an extension (DCAT `dct:format`).
	 *
	 * @param string $extension The lowercase file extension.
	 *
	 * @return array<string, string> A `dct:format` node referencing the EU authority.
	 */
	private function formatUriFor(string $extension): array {
		return [
			'@id' => 'http://publications.europa.eu/resource/authority/file-type/' . strtoupper($extension),
		];

	}//end formatUriFor()

	/**
	 * Whether a value is set and not the empty string.
	 *
	 * @param mixed $value The value to test.
	 *
	 * @return bool False for null and for ''.
	 *
	 * @spec openspec/specs/dcat-ap-harvest/spec.md#requirement-attachments-rendered-as-distributions-with-stable-iris-dcat-006
	 */
	private function filled(mixed $value): bool {
		return $value !== null && $value !== '';
	}//end filled()
}//end class
