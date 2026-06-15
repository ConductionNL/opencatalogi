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
class DcatMappingService
{

    /**
     * The conservative default property map used when a schema carries no
     * `x-dcat` annotation. Keys are DCAT-AP-NL property IRIs (CURIE form),
     * values are dot/bracket paths into the publication object.
     *
     * @var array<string, string>
     */
    public const DEFAULT_MAPPING = [
        'dct:title'       => 'title',
        'dct:description' => 'description',
        'dcat:keyword'    => 'tags[]',
        'dcat:theme'      => 'category',
        'dct:license'     => 'license',
    ];

    /**
     * Build the DCAT-AP-NL JSON-LD `@context` shared by every emitted document.
     *
     * @return array<string, string> The JSON-LD context prefixes.
     *
     * @spec openspec/changes/dcat-ap-harvest/specs/dcat-ap-harvest/spec.md#requirement-per-catalog-dcat-ap-nl-document-endpoint-dcat-001
     */
    public function context(): array
    {
        return [
            'dcat'    => 'http://www.w3.org/ns/dcat#',
            'dct'     => 'http://purl.org/dc/terms/',
            'foaf'    => 'http://xmlns.com/foaf/0.1/',
            'vcard'   => 'http://www.w3.org/2006/vcard/ns#',
            'hydra'   => 'http://www.w3.org/ns/hydra/core#',
            'profile' => 'https://data.overheid.nl/dcat-ap-nl/3.0',
        ];

    }//end context()

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
     *                                     or null when the schema opted out.
     *
     * @spec openspec/changes/dcat-ap-harvest/specs/dcat-ap-harvest/spec.md#requirement-schema-driven-dcat-mapping-via-x-dcat-annotation-dcat-004
     */
    public function resolveMapping(?array $schema): ?array
    {
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
     * URLs so harvesters dedupe on a stable identifier across runs.
     *
     * @param array<string, mixed>  $publication The publication object (jsonSerialize shape).
     * @param array<string, string> $mapping     The resolved DCAT property map.
     * @param array<int, array>     $files       Published file attachments (formatFiles shape).
     * @param string                $datasetIri  The canonical public dataset IRI (PUB-002 URL).
     * @param array<string, mixed>  $defaults    Catalog-level publisher/license/contactPoint defaults.
     *
     * @return array<string, mixed> The `dcat:Dataset` node.
     *
     * @SuppressWarnings(PHPMD.CyclomaticComplexity)
     * @SuppressWarnings(PHPMD.NPathComplexity)
     * @SuppressWarnings(PHPMD.ExcessiveMethodLength)
     *
     * @spec openspec/changes/dcat-ap-harvest/specs/dcat-ap-harvest/spec.md#requirement-schema-driven-dcat-mapping-via-x-dcat-annotation-dcat-004
     * @spec openspec/changes/dcat-ap-harvest/specs/dcat-ap-harvest/spec.md#requirement-dcat-ap-nl-mandatory-property-completion-dcat-005
     * @spec openspec/changes/dcat-ap-harvest/specs/dcat-ap-harvest/spec.md#requirement-attachments-rendered-as-distributions-with-stable-iris-dcat-006
     */
    public function mapDataset(
        array $publication,
        array $mapping,
        array $files,
        string $datasetIri,
        array $defaults
    ): array {
        $dataset = [
            '@id'   => $datasetIri,
            '@type' => 'dcat:Dataset',
        ];

        foreach ($mapping as $dcatProperty => $sourcePath) {
            $value = $this->extractValue($publication, $sourcePath);
            if ($value === null || $value === '' || $value === []) {
                continue;
            }

            // `tags[]`-style paths produce a list → emit each element separately.
            if (str_ends_with($sourcePath, '[]') === true && is_array($value) === true) {
                $dataset[$dcatProperty] = array_values(
                    array_filter(
                        array_map(
                            static function ($entry) {
                                if (is_scalar($entry) === true) {
                                    return (string) $entry;
                                }

                                return null;
                            },
                            $value
                        ),
                        static fn($entry) => $entry !== null && $entry !== ''
                    )
                );
                continue;
            }

            // Theme: emit a TOOI/overheid-thema URI when one is supplied on the object.
            if ($dcatProperty === 'dcat:theme') {
                $themeUri = ($publication['tooiThemaUri'] ?? $publication['themeUri'] ?? null);
                if (is_string($themeUri) === true && $themeUri !== '') {
                    $dataset['dcat:theme'] = ['@id' => $themeUri];
                    continue;
                }
            }

            $dataset[$dcatProperty] = $value;
        }//end foreach

        // Mandatory: dct:modified (from @self.updated/published).
        $modified = ($publication['@self']['updated'] ?? $publication['@self']['published'] ?? null);
        if ($modified !== null) {
            $dataset['dct:modified'] = $this->isoDate((string) $modified);
        }

        // Mandatory: dcat:landingPage → the canonical dataset URL.
        $dataset['dcat:landingPage'] = ['@id' => $datasetIri];

        // Mandatory: dct:identifier.
        $dataset['dct:identifier'] = ($publication['id'] ?? $datasetIri);

        // Mandatory-property completion chain: object → catalog defaults → Organisation.
        $dataset = $this->completePublisher(dataset: $dataset, publication: $publication, defaults: $defaults);
        $dataset = $this->completeContactPoint(dataset: $dataset, publication: $publication, defaults: $defaults);

        // Distributions from published attachments.
        $defaultLicense = ($publication['license'] ?? $defaults['license'] ?? null);
        $distributions  = [];
        foreach ($files as $file) {
            $distribution = $this->mapDistribution($file, $defaultLicense);
            if ($distribution !== null) {
                $distributions[] = $distribution;
            }
        }

        if (empty($distributions) === false) {
            $dataset['dcat:distribution'] = $distributions;
        }

        return $dataset;

    }//end mapDataset()

    /**
     * Map a single published file attachment to a `dcat:Distribution`.
     *
     * @param array<string, mixed> $file           A formatFiles-shape file entry.
     * @param string|null          $defaultLicense Fallback license URI for the distribution.
     *
     * @return array<string, mixed>|null The distribution node, or null when the
     *                                    file has no public download URL.
     *
     * @SuppressWarnings(PHPMD.CyclomaticComplexity)
     *
     * @spec openspec/changes/dcat-ap-harvest/specs/dcat-ap-harvest/spec.md#requirement-attachments-rendered-as-distributions-with-stable-iris-dcat-006
     */
    public function mapDistribution(array $file, ?string $defaultLicense): ?array
    {
        $downloadUrl = ($file['downloadUrl'] ?? $file['accessUrl'] ?? null);
        if (is_string($downloadUrl) === false || $downloadUrl === '') {
            return null;
        }

        $extension = strtolower((string) ($file['extension'] ?? ''));
        $mediaType = $this->mediaTypeFor($extension, ($file['mimetype'] ?? $file['mimeType'] ?? null));

        $distribution = [
            // IRI is the stable public download URL → harvesters dedupe across runs.
            '@id'              => $downloadUrl,
            '@type'            => 'dcat:Distribution',
            'dcat:downloadURL' => ['@id' => $downloadUrl],
            'dcat:accessURL'   => ['@id' => (string) ($file['accessUrl'] ?? $downloadUrl)],
        ];

        $title = ($file['title'] ?? $file['name'] ?? null);
        if ($title !== null && $title !== '') {
            $distribution['dct:title'] = (string) $title;
        }

        if ($mediaType !== null) {
            $distribution['dcat:mediaType'] = $mediaType;
            $distribution['dct:format']     = $this->formatUriFor($extension);
        }

        if (isset($file['size']) === true && is_numeric($file['size']) === true) {
            $distribution['dcat:byteSize'] = (int) $file['size'];
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
     * @param array<string, mixed>      $defaults     Catalog-level defaults (publisher name/uri).
     *
     * @return array<string, mixed>|null The `foaf:Agent`, or null when nothing resolves.
     *
     * @spec openspec/changes/dcat-ap-harvest/specs/dcat-ap-harvest/spec.md#requirement-instance-level-dcat-catalog-document-dcat-002
     * @spec openspec/changes/dcat-ap-harvest/specs/dcat-ap-harvest/spec.md#requirement-dcat-ap-nl-mandatory-property-completion-dcat-005
     */
    public function buildPublisher(?array $organisation, array $defaults): ?array
    {
        $name = ($organisation['title'] ?? $organisation['name'] ?? $defaults['publisherName'] ?? null);
        $uri  = ($organisation['oin'] ?? $organisation['tooiUri'] ?? $organisation['uri'] ?? $defaults['publisherUri'] ?? null);

        if (($name === null || $name === '') && ($uri === null || $uri === '')) {
            return null;
        }

        $agent = ['@type' => 'foaf:Agent'];
        if ($uri !== null && $uri !== '') {
            $agent['@id'] = (string) $uri;
        }

        if ($name !== null && $name !== '') {
            $agent['foaf:name'] = (string) $name;
        }

        return $agent;

    }//end buildPublisher()

    /**
     * Complete `dct:publisher` from the object, then catalog defaults, then Organisation.
     *
     * @param array<string, mixed> $dataset     The dataset under construction.
     * @param array<string, mixed> $publication The source publication object.
     * @param array<string, mixed> $defaults    Catalog-level defaults incl. an optional `organisation` object.
     *
     * @return array<string, mixed> The dataset with `dct:publisher` populated when resolvable.
     */
    private function completePublisher(array $dataset, array $publication, array $defaults): array
    {
        $objectPublisher = ($publication['publisher'] ?? $publication['organisation'] ?? null);
        if (is_array($objectPublisher) === true) {
            $agent = $this->buildPublisher($objectPublisher, $defaults);
            if ($agent !== null) {
                $dataset['dct:publisher'] = $agent;
                return $dataset;
            }
        } else if (is_string($objectPublisher) === true && $objectPublisher !== '') {
            $dataset['dct:publisher'] = [
                '@type'     => 'foaf:Agent',
                'foaf:name' => $objectPublisher,
            ];
            return $dataset;
        }

        $agent = $this->buildPublisher(($defaults['organisation'] ?? null), $defaults);
        if ($agent !== null) {
            $dataset['dct:publisher'] = $agent;
        }

        return $dataset;

    }//end completePublisher()

    /**
     * Complete `dcat:contactPoint` from catalog defaults when the object lacks one.
     *
     * @param array<string, mixed> $dataset     The dataset under construction.
     * @param array<string, mixed> $publication The source publication object.
     * @param array<string, mixed> $defaults    Catalog-level defaults.
     *
     * @return array<string, mixed> The dataset with `dcat:contactPoint` populated when resolvable.
     */
    private function completeContactPoint(array $dataset, array $publication, array $defaults): array
    {
        $email = ($publication['contactEmail'] ?? $defaults['contactPoint'] ?? null);
        if (is_string($email) === true && $email !== '') {
            $dataset['dcat:contactPoint'] = [
                '@type'          => 'vcard:Organization',
                'vcard:hasEmail' => ['@id' => 'mailto:'.ltrim($email, 'mailto:')],
            ];
        }

        return $dataset;

    }//end completeContactPoint()

    /**
     * Extract a value from a publication object by a dot/bracket source path.
     *
     * Supports `a.b.c` nesting and a trailing `[]` denoting a list-valued field.
     *
     * @param array<string, mixed> $object The source object.
     * @param string               $path   The source path (e.g. `title`, `meta.summary`, `tags[]`).
     *
     * @return mixed The resolved value or null when absent.
     */
    private function extractValue(array $object, string $path): mixed
    {
        $path     = rtrim($path, '[]');
        $segments = explode('.', $path);
        $cursor   = $object;
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
    private function isoDate(string $value): string
    {
        $timestamp = strtotime($value);
        if ($timestamp === false) {
            return $value;
        }

        return date('c', $timestamp);

    }//end isoDate()

    /**
     * Resolve a media type for a file from its extension or supplied mimetype.
     *
     * @param string      $extension The lowercase file extension.
     * @param string|null $mimetype  An explicit mimetype when available.
     *
     * @return string|null The IANA media type, or null when unknown.
     */
    private function mediaTypeFor(string $extension, ?string $mimetype): ?string
    {
        if (is_string($mimetype) === true && $mimetype !== '') {
            return $mimetype;
        }

        $map = [
            'pdf'  => 'application/pdf',
            'csv'  => 'text/csv',
            'json' => 'application/json',
            'xml'  => 'application/xml',
            'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'zip'  => 'application/zip',
            'txt'  => 'text/plain',
            'html' => 'text/html',
            'odt'  => 'application/vnd.oasis.opendocument.text',
            'ods'  => 'application/vnd.oasis.opendocument.spreadsheet',
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
    private function formatUriFor(string $extension): array
    {
        return [
            '@id' => 'http://publications.europa.eu/resource/authority/file-type/'.strtoupper($extension),
        ];

    }//end formatUriFor()
}//end class
