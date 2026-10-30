<?php

/**
 * OpenCatalogi DCAT mapping service.
 *
 * Pure, dependency-free mapping layer that turns OpenCatalogi publication
 * objects into DCAT-AP-NL graph fragments. The property mapping is declared on
 * the OpenRegister schema via an `x-dcat` extension (mirroring how
 * `x-openregister-lifecycle` and `x-openregister-notifications` declare
 * behaviour on schemas); schemas without an annotation fall back to a
 * conservative built-in default, and a schema MAY opt out entirely with
 * `"x-dcat": false`.
 *
 * This class has NO Nextcloud dependencies so the mapping and mandatory-property
 * completion chain are fully unit-testable in isolation.
 *
 * @category Service
 * @package  OCA\OpenCatalogi\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2025 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2025 Conduction B.V. <info@conduction.nl>
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenCatalogi.nl
 */

namespace OCA\OpenCatalogi\Service;

/**
 * Maps OpenCatalogi publications to DCAT-AP-NL dataset/distribution fragments.
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 * @SuppressWarnings(PHPMD.ExcessiveClassComplexity)
 *
 * @spec openspec/changes/dcat-ap-harvest/tasks.md#task-2-dcat-mapping-layer-x-dcat-defaults
 */
class DcatMappingService {

	/**
	 * The conservative default property map used when a schema carries no
	 * `x-dcat` annotation. Keys are DCAT-AP-NL property IRIs (CURIE form),
	 * values are dot/bracket paths into the publication object.
	 *
	 * @var array<string, string>
	 */
	public const DEFAULT_MAPPING = [
		'dct:title' => 'title',
		'dct:description' => 'description',
		'dcat:keyword' => 'tags[]',
		'dcat:theme' => 'category',
		'dct:license' => 'license',
	];

	/**
	 * Constructor.
	 *
	 * @param DcatVocabularyService $vocabulary The HVD-category / data-theme resolver.
	 */
	public function __construct(
		private readonly DcatVocabularyService $vocabulary,
	) {

	}//end __construct()

	/**
	 * Build the DCAT-AP-NL JSON-LD `@context` shared by every emitted document.
	 *
	 * @return array<string, string> The JSON-LD context prefixes.
	 *
	 * @spec openspec/specs/dcat-ap-harvest/spec.md#requirement-per-catalog-dcat-ap-nl-document-endpoint-dcat-001
	 */
	public function context(): array {
		return [
			'dcat' => 'http://www.w3.org/ns/dcat#',
			'dcatap' => 'http://data.europa.eu/r5r/',
			'dct' => 'http://purl.org/dc/terms/',
			'dqv' => 'http://www.w3.org/ns/dqv#',
			'foaf' => 'http://xmlns.com/foaf/0.1/',
			'vcard' => 'http://www.w3.org/2006/vcard/ns#',
			'hydra' => 'http://www.w3.org/ns/hydra/core#',
			'profile' => 'https://data.overheid.nl/dcat-ap-nl/3.0',
		];

	}//end context()

	/**
	 * Resolve the optional `x-dcat.hvd` block from a schema annotation.
	 *
	 * @param array<string, mixed>|null $schema The OpenRegister schema array.
	 *
	 * @return array{categoryProperty?: string, legislation?: string}|null The HVD config, or null.
	 *
	 * @spec openspec/specs/dcat-ap-harvest/spec.md
	 */
	public function resolveHvd(?array $schema): ?array {
		$annotation = ($schema['x-dcat'] ?? null);
		if (is_array($annotation) === false) {
			return null;
		}

		$hvd = ($annotation['hvd'] ?? null);
		if (is_array($hvd) === false || $hvd === []) {
			return null;
		}

		return $hvd;
	}//end resolveHvd()

	/**
	 * Resolve the effective DCAT mapping for a schema.
	 *
	 * Reads the schema's `x-dcat` annotation. When the annotation is the literal
	 * boolean `false` the schema is opted out (returns null). When absent or
	 * lacking a `mapping` key the built-in default mapping is returned.
	 *
	 * @param array<string, mixed>|null $schema The OpenRegister schema array (jsonSerialize shape).
	 *
	 * @return array<string, string>|null The property map (DCAT CURIE => object path),
	 *                                    or null when the schema opted out.
	 *
	 * @spec openspec/specs/dcat-ap-harvest/spec.md#requirement-schema-driven-dcat-mapping-via-x-dcat-annotation-dcat-004
	 */
	public function resolveMapping(?array $schema): ?array {
		$annotation = ($schema['x-dcat'] ?? null);

		// Explicit opt-out.
		if ($annotation === false) {
			return null;
		}

		if (is_array($annotation) === true && isset($annotation['mapping']) === true
			&& is_array($annotation['mapping']) === true
			&& empty($annotation['mapping']) === false
		) {
			return $annotation['mapping'];
		}

		// Unannotated (or malformed) schema → conservative default.
		return self::DEFAULT_MAPPING;
	}//end resolveMapping()

	/**
	 * Map a single publication object to a `dcat:Dataset` graph fragment.
	 *
	 * Applies the resolved mapping, then completes DCAT-AP-NL mandatory
	 * properties from the catalog-level defaults and finally the owning
	 * Organisation. Distributions are derived from the publication's published
	 * file attachments. IRIs are taken verbatim from the supplied canonical
	 * URLs so harvesters dedupe on a stable identifier across runs. Theme
	 * violations are collected on the source.
	 *
	 * @param DcatDatasetSource $source The publication, its dataset IRI and attachments.
	 * @param DcatMappingProfile $profile The property map, catalog defaults and HVD settings.
	 *
	 * @return array<string, mixed> The `dcat:Dataset` node.
	 *
	 * @spec openspec/specs/dcat-ap-harvest/spec.md#requirement-schema-driven-dcat-mapping-via-x-dcat-annotation-dcat-004
	 * @spec openspec/specs/dcat-ap-harvest/spec.md#requirement-dcat-ap-nl-mandatory-property-completion-dcat-005
	 * @spec openspec/specs/dcat-ap-harvest/spec.md#requirement-attachments-rendered-as-distributions-with-stable-iris-dcat-006
	 */
	public function mapDataset(DcatDatasetSource $source, DcatMappingProfile $profile): array {
		// Later fragments overwrite earlier keys in place, so a mapped property
		// keeps its position when completion fills it.
		return array_replace(
			[
				'@id' => $source->datasetIri,
				'@type' => 'dcat:Dataset',
			],
			$this->mapProperties(source: $source, mapping: $profile->mapping),
			$this->hvdProperties(publication: $source->publication, profile: $profile),
			$this->mandatoryProperties(source: $source),
			$this->completionProperties(source: $source, profile: $profile)
		);
	}//end mapDataset()

	/**
	 * Apply the property map to the publication.
	 *
	 * `tags[]`-style paths emit each scalar element; `dcat:theme` binds to a
	 * controlled authority URI or is omitted and reported (DCAT-NPF-003).
	 *
	 * @param DcatDatasetSource $source The publication being mapped.
	 * @param array<string, string> $mapping The resolved DCAT property map.
	 *
	 * @return array<string, mixed> The mapped properties, in mapping order.
	 *
	 * @spec openspec/specs/dcat-ap-harvest/spec.md#requirement-schema-driven-dcat-mapping-via-x-dcat-annotation-dcat-004
	 */
	private function mapProperties(DcatDatasetSource $source, array $mapping): array {
		$properties = [];
		foreach ($mapping as $dcatProperty => $sourcePath) {
			$value = $this->extractValue(object: $source->publication, path: $sourcePath);
			if ($value === null || $value === '' || $value === []) {
				continue;
			}

			if (str_ends_with($sourcePath, '[]') === true && is_array($value) === true) {
				$properties[$dcatProperty] = $this->scalarList(values: $value);
				continue;
			}

			if ($dcatProperty === 'dcat:theme') {
				$theme = $this->themeNode(source: $source, value: $value);
				if ($theme !== null) {
					$properties['dcat:theme'] = $theme;
				}

				continue;
			}

			$properties[$dcatProperty] = $value;
		}//end foreach

		return $properties;
	}//end mapProperties()

	/**
	 * The non-empty scalar elements of a list, as strings.
	 *
	 * @param array<mixed> $values The list value.
	 *
	 * @return array<int, string> The scalar elements, reindexed.
	 *
	 * @spec openspec/specs/dcat-ap-harvest/spec.md#requirement-schema-driven-dcat-mapping-via-x-dcat-annotation-dcat-004
	 */
	private function scalarList(array $values): array {
		return array_values(
			array_filter(
				array_map(
					static function ($entry) {
						if (is_scalar($entry) === true) {
							return (string)$entry;
						}

						return null;
					},
					$values
				),
				static fn ($entry) => $entry !== null && $entry !== ''
			)
		);
	}//end scalarList()

	/**
	 * The `dcat:theme` node, or null after reporting a violation on the source.
	 *
	 * @param DcatDatasetSource $source The publication being mapped.
	 * @param mixed $value The mapped source theme value.
	 *
	 * @return array{'@id': string}|null The theme node, or null when it does not resolve.
	 *
	 * @spec openspec/specs/dcat-ap-harvest/spec.md#requirement-schema-driven-dcat-mapping-via-x-dcat-annotation-dcat-004
	 */
	private function themeNode(DcatDatasetSource $source, mixed $value): ?array {
		$themeUri = $this->resolveThemeUri(publication: $source->publication, sourceValue: $value);
		if ($themeUri !== null) {
			return ['@id' => $themeUri];
		}

		$source->violations[] = [
			'iri' => $source->datasetIri,
			'axis' => 'dcat:theme',
			'reason' => 'theme does not resolve to a controlled data-theme authority URI',
		];
		return null;
	}//end themeNode()

	/**
	 * The two HVD triples (DCAT-NPF-002), or none when no HVD category resolves.
	 *
	 * @param array<string, mixed> $publication The publication object.
	 * @param DcatMappingProfile $profile The schema HVD block and catalog default.
	 *
	 * @return array<string, mixed> The HVD properties, possibly empty.
	 *
	 * @spec openspec/specs/dcat-ap-harvest/spec.md#requirement-schema-driven-dcat-mapping-via-x-dcat-annotation-dcat-004
	 */
	private function hvdProperties(array $publication, DcatMappingProfile $profile): array {
		$hvdCategory = $this->resolveHvdCategory(publication: $publication, profile: $profile);
		if ($hvdCategory === null) {
			return [];
		}

		return [
			'dcatap:hvdCategory' => ['@id' => $hvdCategory['uri']],
			'dcatap:applicableLegislation' => ['@id' => ($profile->hvd['legislation'] ?? DcatVocabularyService::HVD_LEGISLATION)],
		];
	}//end hvdProperties()

	/**
	 * The mandatory `dct:modified`, `dcat:landingPage` and `dct:identifier`.
	 *
	 * `dct:modified` comes from `@self.updated`, falling back to the object's own
	 * publicationDate, and is left out when neither is set.
	 *
	 * @param DcatDatasetSource $source The publication being mapped.
	 *
	 * @return array<string, mixed> The mandatory properties.
	 *
	 * @spec openspec/specs/dcat-ap-harvest/spec.md#requirement-dcat-ap-nl-mandatory-property-completion-dcat-005
	 */
	private function mandatoryProperties(DcatDatasetSource $source): array {
		$publication = $source->publication;
		$properties = [];

		$modified = ($publication['@self']['updated'] ?? $publication['publicationDate'] ?? null);
		if ($modified !== null) {
			$properties['dct:modified'] = $this->isoDate(value: (string)$modified);
		}

		$properties['dcat:landingPage'] = ['@id' => $source->datasetIri];
		$properties['dct:identifier'] = ($publication['id'] ?? $source->datasetIri);

		return $properties;
	}//end mandatoryProperties()

	/**
	 * Publisher, contact point and distributions, each only when it resolves.
	 *
	 * The publisher chain runs object, then catalog defaults, then Organisation.
	 *
	 * @param DcatDatasetSource $source The publication being mapped.
	 * @param DcatMappingProfile $profile The catalog defaults.
	 *
	 * @return array<string, mixed> The completed properties.
	 *
	 * @spec openspec/specs/dcat-ap-harvest/spec.md#requirement-dcat-ap-nl-mandatory-property-completion-dcat-005
	 * @spec openspec/specs/dcat-ap-harvest/spec.md#requirement-attachments-rendered-as-distributions-with-stable-iris-dcat-006
	 */
	private function completionProperties(DcatDatasetSource $source, DcatMappingProfile $profile): array {
		$completed = [
			'dct:publisher' => $this->resolvePublisher(publication: $source->publication, defaults: $profile->defaults),
			'dcat:contactPoint' => $this->contactPoint(publication: $source->publication, defaults: $profile->defaults),
			'dcat:distribution' => $this->mapDistributions(source: $source, defaults: $profile->defaults),
		];

		return array_filter($completed, static fn ($value) => $value !== null && $value !== []);
	}//end completionProperties()

	/**
	 * Map the published attachments to distributions, skipping files without a URL.
	 *
	 * @param DcatDatasetSource $source The publication and its attachments.
	 * @param array<string, mixed> $defaults Catalog-level defaults (fallback license).
	 *
	 * @return array<int, array<string, mixed>> The distribution nodes.
	 *
	 * @spec openspec/specs/dcat-ap-harvest/spec.md#requirement-attachments-rendered-as-distributions-with-stable-iris-dcat-006
	 */
	private function mapDistributions(DcatDatasetSource $source, array $defaults): array {
		$defaultLicense = ($source->publication['license'] ?? $defaults['license'] ?? null);
		$distributions = [];
		foreach ($source->files as $file) {
			$distribution = $this->mapDistribution(file: $file, defaultLicense: $defaultLicense);
			if ($distribution !== null) {
				$distributions[] = $distribution;
			}
		}

		return $distributions;
	}//end mapDistributions()

	/**
	 * Resolve a controlled `dcat:theme` authority URI for a publication.
	 *
	 * Accepts an authority URI already carried on the object (`tooiThemaUri` /
	 * `themeUri`) verbatim, otherwise resolves the mapped source value through the
	 * EU MDR data-theme value list. Returns null when nothing resolves.
	 *
	 * @param array<string, mixed> $publication The publication object.
	 * @param mixed $sourceValue The mapped source theme value.
	 *
	 * @return string|null The controlled authority URI, or null.
	 *
	 * @spec openspec/specs/dcat-ap-harvest/spec.md
	 */
	private function resolveThemeUri(array $publication, mixed $sourceValue): ?string {
		$onObject = ($publication['tooiThemaUri'] ?? $publication['themeUri'] ?? null);
		if (is_string($onObject) === true
			&& (str_starts_with($onObject, 'http://') === true || str_starts_with($onObject, 'https://') === true)
		) {
			return $onObject;
		}

		$candidate = null;
		if (is_scalar($sourceValue) === true) {
			$candidate = (string)$sourceValue;
		}

		return $this->vocabulary->resolveDataTheme($candidate);
	}//end resolveThemeUri()

	/**
	 * Resolve the HVD category for a publication from its schema `x-dcat.hvd` block
	 * or the catalog default.
	 *
	 * @param array<string, mixed> $publication The publication object.
	 * @param DcatMappingProfile $profile The schema HVD config and catalog default.
	 *
	 * @return array{uri: string, label: string}|null The resolved category, or null.
	 *
	 * @spec openspec/specs/dcat-ap-harvest/spec.md
	 */
	private function resolveHvdCategory(array $publication, DcatMappingProfile $profile): ?array {
		$hvd = $profile->hvd;
		$catalogHvdDefault = $profile->catalogHvdDefault;
		$categoryValue = null;
		if (is_array($hvd) === true && isset($hvd['categoryProperty']) === true) {
			$extracted = $this->extractValue(object: $publication, path: (string)$hvd['categoryProperty']);
			if (is_scalar($extracted) === true) {
				$categoryValue = (string)$extracted;
			}
		}

		if (($categoryValue === null || $categoryValue === '') && $catalogHvdDefault !== null && $catalogHvdDefault !== '') {
			$categoryValue = $catalogHvdDefault;
		}

		if ($categoryValue === null || $categoryValue === '') {
			return null;
		}

		return $this->vocabulary->resolveHvdCategory($categoryValue);
	}//end resolveHvdCategory()

	/**
	 * Map a single published file attachment to a `dcat:Distribution`.
	 *
	 * @param array<string, mixed> $file A formatFiles-shape file entry.
	 * @param string|null $defaultLicense Fallback license URI for the distribution.
	 *
	 * @return array<string, mixed>|null The distribution node, or null when the
	 *                                   file has no public download URL.
	 *
	 * @SuppressWarnings(PHPMD.CyclomaticComplexity)
	 *
	 * @spec openspec/specs/dcat-ap-harvest/spec.md#requirement-attachments-rendered-as-distributions-with-stable-iris-dcat-006
	 */
	public function mapDistribution(array $file, ?string $defaultLicense): ?array {
		$downloadUrl = ($file['downloadUrl'] ?? $file['accessUrl'] ?? null);
		if (is_string($downloadUrl) === false || $downloadUrl === '') {
			return null;
		}

		$extension = strtolower((string)($file['extension'] ?? ''));
		$mediaType = $this->mediaTypeFor(extension: $extension, mimetype: ($file['mimetype'] ?? $file['mimeType'] ?? null));

		$distribution = [
			// IRI is the stable public download URL → harvesters dedupe across runs.
			'@id' => $downloadUrl,
			'@type' => 'dcat:Distribution',
			'dcat:downloadURL' => ['@id' => $downloadUrl],
			'dcat:accessURL' => ['@id' => (string)($file['accessUrl'] ?? $downloadUrl)],
		];

		$title = ($file['title'] ?? $file['name'] ?? null);
		if ($title !== null && $title !== '') {
			$distribution['dct:title'] = (string)$title;
		}

		if ($mediaType !== null) {
			$distribution['dcat:mediaType'] = $mediaType;
			$distribution['dct:format'] = $this->formatUriFor(extension: $extension);
		}

		if (isset($file['size']) === true && is_numeric($file['size']) === true) {
			$distribution['dcat:byteSize'] = (int)$file['size'];
		}

		if ($defaultLicense !== null && $defaultLicense !== '') {
			$distribution['dct:license'] = ['@id' => $defaultLicense];
		}

		return $distribution;
	}//end mapDistribution()

	/**
	 * Build the publisher `foaf:Agent` for a catalog/instance from an Organisation.
	 *
	 * @param array<string, mixed>|null $organisation The owning Organisation object.
	 * @param array<string, mixed> $defaults Catalog-level defaults (publisher name/uri).
	 *
	 * @return array<string, mixed>|null The `foaf:Agent`, or null when nothing resolves.
	 *
	 * @spec openspec/specs/dcat-ap-harvest/spec.md#requirement-instance-level-dcat-catalog-document-dcat-002
	 * @spec openspec/specs/dcat-ap-harvest/spec.md#requirement-dcat-ap-nl-mandatory-property-completion-dcat-005
	 */
	public function buildPublisher(?array $organisation, array $defaults): ?array {
		$name = ($organisation['title'] ?? $organisation['name'] ?? $defaults['publisherName'] ?? null);
		$uri = ($organisation['oin'] ?? $organisation['tooiUri'] ?? $organisation['uri'] ?? $defaults['publisherUri'] ?? null);

		if (($name === null || $name === '') && ($uri === null || $uri === '')) {
			return null;
		}

		$agent = ['@type' => 'foaf:Agent'];
		if ($uri !== null && $uri !== '') {
			$agent['@id'] = (string)$uri;
		}

		if ($name !== null && $name !== '') {
			$agent['foaf:name'] = (string)$name;
		}

		return $agent;
	}//end buildPublisher()

	/**
	 * Resolve `dct:publisher` from the object, then catalog defaults, then Organisation.
	 *
	 * @param array<string, mixed> $publication The source publication object.
	 * @param array<string, mixed> $defaults Catalog-level defaults incl. an optional `organisation` object.
	 *
	 * @return array<string, mixed>|null The publisher agent, or null when nothing resolves.
	 *
	 * @spec openspec/specs/dcat-ap-harvest/spec.md#requirement-dcat-ap-nl-mandatory-property-completion-dcat-005
	 */
	private function resolvePublisher(array $publication, array $defaults): ?array {
		$objectPublisher = ($publication['publisher'] ?? $publication['organisation'] ?? null);
		if (is_array($objectPublisher) === true) {
			$agent = $this->buildPublisher(organisation: $objectPublisher, defaults: $defaults);
			if ($agent !== null) {
				return $agent;
			}
		} elseif (is_string($objectPublisher) === true && $objectPublisher !== '') {
			return [
				'@type' => 'foaf:Agent',
				'foaf:name' => $objectPublisher,
			];
		}

		return $this->buildPublisher(organisation: ($defaults['organisation'] ?? null), defaults: $defaults);
	}//end resolvePublisher()

	/**
	 * The `dcat:contactPoint` from the object's contact email or the catalog default.
	 *
	 * @param array<string, mixed> $publication The source publication object.
	 * @param array<string, mixed> $defaults Catalog-level defaults.
	 *
	 * @return array<string, mixed>|null The contact point, or null when no email is set.
	 *
	 * @spec openspec/specs/dcat-ap-harvest/spec.md#requirement-dcat-ap-nl-mandatory-property-completion-dcat-005
	 */
	private function contactPoint(array $publication, array $defaults): ?array {
		$email = ($publication['contactEmail'] ?? $defaults['contactPoint'] ?? null);
		if (is_string($email) === false || $email === '') {
			return null;
		}

		return [
			'@type' => 'vcard:Organization',
			'vcard:hasEmail' => ['@id' => 'mailto:' . ltrim($email, 'mailto:')],
		];
	}//end contactPoint()

	/**
	 * Extract a value from a publication object by a dot/bracket source path.
	 *
	 * Supports `a.b.c` nesting and a trailing `[]` denoting a list-valued field.
	 *
	 * @param array<string, mixed> $object The source object.
	 * @param string $path The source path (e.g. `title`, `meta.summary`, `tags[]`).
	 *
	 * @return mixed The resolved value or null when absent.
	 */
	private function extractValue(array $object, string $path): mixed {
		$path = rtrim($path, '[]');
		$segments = explode('.', $path);
		$cursor = $object;
		foreach ($segments as $segment) {
			if (is_array($cursor) === false || array_key_exists($segment, $cursor) === false) {
				return null;
			}

			$cursor = $cursor[$segment];
		}

		return $cursor;
	}//end extractValue()

	/**
	 * Normalise an arbitrary date string to ISO-8601 (DCAT `dct:modified` shape).
	 *
	 * @param string $value The source date string.
	 *
	 * @return string The ISO-8601 representation (falls back to the input on parse failure).
	 */
	private function isoDate(string $value): string {
		$timestamp = strtotime($value);
		if ($timestamp === false) {
			return $value;
		}

		return date('c', $timestamp);
	}//end isoDate()

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

		$map = [
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

		return ($map[$extension] ?? null);
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
}//end class
