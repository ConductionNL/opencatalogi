<?php

/**
 * OpenCatalogi DCAT serializer.
 *
 * Pure, dependency-free RDF serializer. Takes a single intermediate graph
 * (a JSON-LD document array shaped by DcatMappingService / DcatService) and
 * renders it as JSON-LD (native), Turtle, or RDF/XML. All three serializations
 * are derived from the same intermediate graph, so they always express the same
 * triples.
 *
 * This class has NO Nextcloud dependencies and is fully unit-testable.
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

use OCA\OpenCatalogi\Contract\DcatSerializerInterface;

/**
 * Serializes a DCAT-AP-NL JSON-LD graph to JSON-LD / Turtle / RDF/XML.
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 * @SuppressWarnings(PHPMD.ExcessiveClassComplexity)
 *
 * @spec openspec/changes/dcat-ap-harvest/tasks.md#task-3-serializer-public-endpoints
 */
class DcatSerializer implements DcatSerializerInterface {

	/**
	 * The `?format=` values understood, each with its canonical format name.
	 */
	private const FORMAT_ALIASES = [
		'jsonld' => 'jsonld',
		'json-ld' => 'jsonld',
		'json' => 'jsonld',
		'turtle' => 'turtle',
		'ttl' => 'turtle',
		'rdfxml' => 'rdfxml',
		'rdf+xml' => 'rdfxml',
		'rdf' => 'rdfxml',
		'xml' => 'rdfxml',
	];

	/**
	 * The Accept media types understood, in the order they are tried, each with
	 * its canonical format name.
	 */
	private const ACCEPT_FORMATS = [
		'text/turtle' => 'turtle',
		'application/rdf+xml' => 'rdfxml',
		'application/ld+json' => 'jsonld',
		'application/json' => 'jsonld',
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
	public function negotiate(?string $formatParam, ?string $acceptHeader): ?string {
		// Explicit query parameter wins.
		if ($this->namesExplicitFormat(formatParam: $formatParam) === true) {
			// Unknown explicit format → signal 406.
			return $this->formatFromAlias(formatParam: (string) $formatParam);
		}

		return $this->formatFromAcceptHeader(acceptHeader: $acceptHeader);
	}//end negotiate()

	/**
	 * Whether the `?format=` query parameter was given a value.
	 *
	 * @param string|null $formatParam The `?format=` query value.
	 *
	 * @return bool True when the parameter decides the format.
	 */
	private function namesExplicitFormat(?string $formatParam): bool {
		return $formatParam !== null && $formatParam !== '';
	}//end namesExplicitFormat()

	/**
	 * The canonical format a `?format=` value names, case and whitespace
	 * insensitive.
	 *
	 * @param string $formatParam The `?format=` query value.
	 *
	 * @return string|null The canonical format name, or null for an unknown one.
	 */
	private function formatFromAlias(string $formatParam): ?string {
		return (self::FORMAT_ALIASES[strtolower(trim($formatParam))] ?? null);
	}//end formatFromAlias()

	/**
	 * The format the Accept header asks for, JSON-LD when it leaves it open.
	 *
	 * @param string|null $acceptHeader The HTTP Accept header value.
	 *
	 * @return string|null The canonical format name, or null when none matched.
	 */
	private function formatFromAcceptHeader(?string $acceptHeader): ?string {
		if ($acceptHeader === null || $this->acceptsAnyFormat(acceptHeader: $acceptHeader) === true) {
			return 'jsonld';
		}

		return $this->formatFromAccept(accept: strtolower($acceptHeader));
	}//end formatFromAcceptHeader()

	/**
	 * Whether an Accept header leaves the format open: empty, or a wildcard.
	 *
	 * @param string $acceptHeader The HTTP Accept header value.
	 *
	 * @return bool True when any format will do.
	 */
	private function acceptsAnyFormat(string $acceptHeader): bool {
		return $acceptHeader === '' || str_contains($acceptHeader, '*/*') === true;
	}//end acceptsAnyFormat()

	/**
	 * The format of the first supported media type the Accept header names.
	 *
	 * @param string $accept The lower-cased Accept header value.
	 *
	 * @return string|null The canonical format name, or null when none matched.
	 */
	private function formatFromAccept(string $accept): ?string {
		foreach (self::ACCEPT_FORMATS as $mediaType => $format) {
			if (str_contains($accept, $mediaType) === true) {
				return $format;
			}
		}

		// Accept header present but none of the supported types matched.
		return null;
	}//end formatFromAccept()

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
	public function serialize(array $document, string $format): string {
		return match ($format) {
			'turtle' => $this->toTurtle(document: $document),
			'rdfxml' => $this->toRdfXml(document: $document),
			default => $this->toJsonLd(document: $document),
		};

	}//end serialize()

	/**
	 * Serialize as JSON-LD (the native form — the document is already JSON-LD).
	 *
	 * @param array<string, mixed> $document The JSON-LD document.
	 *
	 * @return string Pretty-printed JSON-LD.
	 *
	 * @spec openspec/changes/dcat-ap-harvest/tasks.md#task-3-serializer-public-endpoints
	 */
	public function toJsonLd(array $document): string {
		$json = json_encode($document, (JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
		if ($json === false) {
			return '{}';
		}

		return $json;
	}//end toJsonLd()

	/**
	 * Serialize as Turtle.
	 *
	 * @param array<string, mixed> $document The JSON-LD document.
	 *
	 * @return string The Turtle representation.
	 *
	 * @spec openspec/changes/dcat-ap-harvest/tasks.md#task-3-serializer-public-endpoints
	 */
	public function toTurtle(array $document): string {
		$context = ($document['@context'] ?? []);
		$lines = [];
		foreach ($context as $prefix => $iri) {
			if (is_string($iri) === true) {
				$lines[] = "@prefix $prefix: <$iri> .";
			}
		}

		$lines[] = '';

		foreach ($this->graphNodes(document: $document) as $node) {
			$lines[] = $this->turtleNode(node: $node);
		}

		return implode("\n", $lines) . "\n";
	}//end toTurtle()

	/**
	 * Serialize as RDF/XML.
	 *
	 * @param array<string, mixed> $document The JSON-LD document.
	 *
	 * @return string The RDF/XML representation.
	 *
	 * @spec openspec/changes/dcat-ap-harvest/tasks.md#task-3-serializer-public-endpoints
	 */
	public function toRdfXml(array $document): string {
		$context = ($document['@context'] ?? []);
		$xmlns = [];
		foreach ($context as $prefix => $iri) {
			if (is_string($iri) === true) {
				$xmlns[] = 'xmlns:' . $prefix . '="' . htmlspecialchars($iri, ENT_QUOTES) . '"';
			}
		}

		$out = '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
		$out .= '<rdf:RDF xmlns:rdf="http://www.w3.org/1999/02/22-rdf-syntax-ns#" ' . implode(' ', $xmlns) . '>' . "\n";

		foreach ($this->graphNodes(document: $document) as $node) {
			$out .= $this->rdfXmlNode(node: $node);
		}

		$out .= '</rdf:RDF>' . "\n";
		return $out;
	}//end toRdfXml()

	/**
	 * Extract the flat list of graph nodes from a JSON-LD document.
	 *
	 * @param array<string, mixed> $document The JSON-LD document.
	 *
	 * @return array<int, array<string, mixed>> The top-level nodes.
	 *
	 * @spec openspec/changes/dcat-ap-harvest/tasks.md#task-3-serializer-public-endpoints
	 */
	public function graphNodes(array $document): array {
		if (isset($document['@graph']) === true && is_array($document['@graph']) === true) {
			return $document['@graph'];
		}

		// A bare node document (no @graph wrapper).
		$node = $document;
		unset($node['@context']);
		return [$node];
	}//end graphNodes()

	/**
	 * Render a single node as a Turtle block.
	 *
	 * @param array<string, mixed> $node The graph node.
	 *
	 * @return string The Turtle block for the node.
	 *
	 * @SuppressWarnings(PHPMD.CyclomaticComplexity)
	 */
	private function turtleNode(array $node): string {
		$subject = '[]';
		if (isset($node['@id']) === true) {
			$subject = '<' . $node['@id'] . '>';
		}

		$preds = [];
		foreach ($node as $key => $value) {
			if ($key === '@id') {
				continue;
			}

			$predicate = $this->turtlePredicate(key: $key);
			$preds[] = '    ' . $predicate . ' ' . $this->turtleValue(value: $value) . ' ;';
		}

		if (empty($preds) === true) {
			return $subject . ' .';
		}

		// Replace the trailing ' ;' on the last predicate with ' .'.
		$last = (count($preds) - 1);
		$preds[$last] = rtrim($preds[$last], ';') . '.';
		return $subject . "\n" . implode("\n", $preds);
	}//end turtleNode()

	/**
	 * Render a value as a Turtle object term (handles lists, IRIs, literals).
	 *
	 * @param mixed $value The value to render.
	 *
	 * @return string The Turtle term(s), comma-joined for lists.
	 */
	private function turtleValue(mixed $value): string {
		if (is_array($value) === true) {
			// IRI reference node.
			if (isset($value['@id']) === true) {
				return '<' . $value['@id'] . '>';
			}

			// Language-tagged literal.
			if (isset($value['@value']) === true) {
				return $this->turtleValue(value: (string)$value['@value']) . $this->languageSuffix(value: $value);
			}

			// Nested blank node (e.g. foaf:Agent / distribution).
			if ($this->isAssoc(array: $value) === true) {
				return $this->turtleBlankNode(node: $value);
			}

			// List of values.
			$terms = array_map(fn ($entry) => $this->turtleValue(value: $entry), $value);
			return implode(' , ', $terms);
		}

		if (is_int($value) === true || is_float($value) === true) {
			return (string)$value;
		}

		if (is_bool($value) === true) {
			if ($value === true) {
				return 'true';
			}

			return 'false';
		}

		return '"' . str_replace(['\\', '"', "\n"], ['\\\\', '\\"', '\\n'], (string)$value) . '"';
	}//end turtleValue()

	/**
	 * The `@lang` suffix of a language-tagged literal, empty without a language.
	 *
	 * @param array<string, mixed> $value The literal node.
	 *
	 * @return string The suffix.
	 *
	 * @spec openspec/changes/publication-plain-language-and-translation/specs/publications/spec.md#requirement-a-publication-carries-a-plain-language-summary-with-its-level-req-ppl-001
	 */
	private function languageSuffix(array $value): string {
		$language = (string)($value['@language'] ?? '');
		if (preg_match('/^[A-Za-z]{2,3}(-[A-Za-z0-9]{1,8})*$/', $language) !== 1) {
			return '';
		}

		return '@' . $language;
	}//end languageSuffix()

	/**
	 * Resolve a Turtle predicate for a JSON-LD key (`@type` → `a`).
	 *
	 * @param string $key The JSON-LD property key.
	 *
	 * @return string The Turtle predicate.
	 */
	private function turtlePredicate(string $key): string {
		if ($key === '@type') {
			return 'a';
		}

		return $key;
	}//end turtlePredicate()

	/**
	 * Render a nested associative array as a Turtle blank node.
	 *
	 * @param array<string, mixed> $node The blank node properties.
	 *
	 * @return string The Turtle blank-node term.
	 */
	private function turtleBlankNode(array $node): string {
		$parts = [];
		foreach ($node as $key => $value) {
			$predicate = $this->turtlePredicate(key: $key);
			$parts[] = $predicate . ' ' . $this->turtleValue(value: $value);
		}

		return '[ ' . implode(' ; ', $parts) . ' ]';
	}//end turtleBlankNode()

	/**
	 * Render a single node as an RDF/XML description block.
	 *
	 * @param array<string, mixed> $node The graph node.
	 *
	 * @return string The RDF/XML block.
	 */
	private function rdfXmlNode(array $node): string {
		$about = '';
		if (isset($node['@id']) === true) {
			$about = ' rdf:about="' . htmlspecialchars((string)$node['@id'], ENT_QUOTES) . '"';
		}

		$out = '  <rdf:Description' . $about . '>' . "\n";
		foreach ($node as $key => $value) {
			if ($key === '@id') {
				continue;
			}

			$tag = $this->rdfXmlTag(key: $key);
			$out .= $this->rdfXmlProperty(tag: $tag, value: $value);
		}

		$out .= '  </rdf:Description>' . "\n";
		return $out;
	}//end rdfXmlNode()

	/**
	 * Render a single predicate/value as RDF/XML property element(s).
	 *
	 * @param string $tag The property tag (CURIE).
	 * @param mixed $value The property value.
	 *
	 * @return string The RDF/XML property element(s).
	 */
	private function rdfXmlProperty(string $tag, mixed $value): string {
		// Lists → repeat the element.
		if (is_array($value) === true && $this->isAssoc(array: $value) === false) {
			$out = '';
			foreach ($value as $entry) {
				$out .= $this->rdfXmlProperty(tag: $tag, value: $entry);
			}

			return $out;
		}

		if (is_array($value) === true && isset($value['@id']) === true && count($value) === 1) {
			return '    <' . $tag . ' rdf:resource="' . htmlspecialchars((string)$value['@id'], ENT_QUOTES) . '"/>' . "\n";
		}

		// Language-tagged literal.
		if (is_array($value) === true && isset($value['@value']) === true) {
			$lang = '';
			if (isset($value['@language']) === true) {
				$lang = ' xml:lang="' . htmlspecialchars((string)$value['@language'], ENT_QUOTES) . '"';
			}

			return '    <' . $tag . $lang . '>' . htmlspecialchars((string)$value['@value'], ENT_QUOTES) . '</' . $tag . '>' . "\n";
		}

		// Nested blank node.
		if (is_array($value) === true) {
			$inner = '';
			foreach ($value as $k => $v) {
				$innerTag = $this->rdfXmlTag(key: (string)$k);
				$inner .= '      ' . $this->rdfXmlProperty(tag: $innerTag, value: $v);
			}

			return '    <' . $tag . '>' . "\n" . '    <rdf:Description>' . "\n" . $inner . '    </rdf:Description>' . "\n" . '    </' . $tag . '>' . "\n";
		}

		return '    <' . $tag . '>' . htmlspecialchars((string)$value, ENT_QUOTES) . '</' . $tag . '>' . "\n";
	}//end rdfXmlProperty()

	/**
	 * Resolve an RDF/XML element tag for a JSON-LD key (`@type` → `rdf:type`).
	 *
	 * @param string $key The JSON-LD property key.
	 *
	 * @return string The RDF/XML tag.
	 */
	private function rdfXmlTag(string $key): string {
		if ($key === '@type') {
			return 'rdf:type';
		}

		return $key;
	}//end rdfXmlTag()

	/**
	 * Determine whether an array is associative (string keys) vs. a list.
	 *
	 * @param array<mixed> $array The array under test.
	 *
	 * @return boolean True when associative.
	 */
	private function isAssoc(array $array): bool {
		if ($array === []) {
			return false;
		}

		return array_keys($array) !== range(0, (count($array) - 1));
	}//end isAssoc()
}//end class
