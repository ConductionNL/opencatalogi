<?php

/**
 * Contract of DcatSerializer, for callers in other components.
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
 * Contract of DcatSerializer, for callers in other components.
 *
 * Callers outside Service/ depend on this interface instead of on the class.
 */
interface DcatSerializerInterface {
	/**
	 * Supported serialization formats keyed by canonical name.
	 *
	 * @var array<string, string> Format name => MIME type.
	 */
	public const FORMATS = [
		'jsonld' => 'application/ld+json',
		'turtle' => 'text/turtle',
		'rdfxml' => 'application/rdf+xml',
	];

	/**
	 * Resolve the requested serialization format from the `?format=` query
	 * parameter (authoritative) and the `Accept` header (fallback).
	 *
	 * @param string|null $formatParam The `?format=` query value (jsonld|turtle|rdfxml).
	 * @param string|null $acceptHeader The HTTP Accept header value.
	 *
	 * @return string|null The canonical format name, or null when an explicit
	 *                     unsupported format was requested (caller emits 406).
	 *
	 * @spec openspec/specs/dcat-ap-harvest/spec.md#requirement-content-negotiation-across-rdf-serializations-dcat-007
	 */
	public function negotiate(?string $formatParam, ?string $acceptHeader): ?string;

	/**
	 * Serialize the document to the requested format.
	 *
	 * @param array<string, mixed> $document The JSON-LD document (with `@context` + `@graph`).
	 * @param string $format The canonical format name.
	 *
	 * @return string The serialized document.
	 *
	 * @spec openspec/specs/dcat-ap-harvest/spec.md#requirement-content-negotiation-across-rdf-serializations-dcat-007
	 */
	public function serialize(array $document, string $format): string;

	/**
	 * Serialize as JSON-LD (the native form — the document is already JSON-LD).
	 *
	 * @param array<string, mixed> $document The JSON-LD document.
	 *
	 * @return string Pretty-printed JSON-LD.
	 *
	 * @spec openspec/changes/dcat-ap-harvest/tasks.md#task-3-serializer-public-endpoints
	 */
	public function toJsonLd(array $document): string;

	/**
	 * Serialize as Turtle.
	 *
	 * @param array<string, mixed> $document The JSON-LD document.
	 *
	 * @return string The Turtle representation.
	 *
	 * @spec openspec/changes/dcat-ap-harvest/tasks.md#task-3-serializer-public-endpoints
	 */
	public function toTurtle(array $document): string;

	/**
	 * Serialize as RDF/XML.
	 *
	 * @param array<string, mixed> $document The JSON-LD document.
	 *
	 * @return string The RDF/XML representation.
	 *
	 * @spec openspec/changes/dcat-ap-harvest/tasks.md#task-3-serializer-public-endpoints
	 */
	public function toRdfXml(array $document): string;

	/**
	 * Extract the flat list of graph nodes from a JSON-LD document.
	 *
	 * @param array<string, mixed> $document The JSON-LD document.
	 *
	 * @return array<int, array<string, mixed>> The top-level nodes.
	 *
	 * @spec openspec/changes/dcat-ap-harvest/tasks.md#task-3-serializer-public-endpoints
	 */
	public function graphNodes(array $document): array;
}//end interface
