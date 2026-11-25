<?php

/**
 * OpenCatalogi TOOI value-list graph: a TOOI download as the graph OpenRegister imports.
 *
 * @category Service
 * @package  OCA\OpenCatalogi\Service\Vocabulary
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
 * @spec openspec/changes/woo-value-lists-on-the-concept-register/specs/woo-compliance/spec.md#requirement-each-scheme-is-refreshed-from-its-national-source-daily-req-wvc-002
 */

declare(strict_types=1);

namespace OCA\OpenCatalogi\Service\Vocabulary;

use InvalidArgumentException;

/**
 * Turns the JSON download of a TOOI value list into the `{"@graph": [...]}`
 * document OpenRegister's `importJsonLd()` reads.
 *
 * The TOOI repository serves expanded JSON-LD: a top-level LIST of nodes whose
 * `@type` is a list of IRIs. OpenRegister reads a `@graph` envelope and a
 * single `@type`, so both are rewritten here. A collection-based list (`ccw_`)
 * carries no `skos:ConceptScheme` at all: its concepts live in the TOOI core
 * thesaurus, and the list node itself is what groups them, so that node
 * becomes the scheme. Grouping concepts (`Groeperingsconcept`) only structure
 * the TOOI page and are left out.
 *
 * @spec openspec/changes/woo-value-lists-on-the-concept-register/specs/woo-compliance/spec.md#requirement-each-scheme-is-refreshed-from-its-national-source-daily-req-wvc-002
 */
class TooiValueListGraph {

	/**
	 * The SKOS namespace.
	 *
	 * @var string
	 */
	private const SKOS = 'http://www.w3.org/2004/02/skos/core#';

	/**
	 * The local type name of a TOOI value-list node.
	 *
	 * @var string
	 */
	private const VALUE_LIST_SUFFIX = 'Conceptwaardelijst';

	/**
	 * The local type name of a TOOI grouping concept.
	 *
	 * @var string
	 */
	private const GROUPING = 'Groeperingsconcept';

	/**
	 * The OWL version predicate OpenRegister stores as the scheme's version.
	 *
	 * @var string
	 */
	private const VERSION_INFO = 'http://www.w3.org/2002/07/owl#versionInfo';

	/**
	 * The longest version OpenRegister's scheme column holds.
	 *
	 * @var integer
	 */
	private const VERSION_MAX = 100;

	/**
	 * The import document and the concept uris of one download.
	 *
	 * @param mixed  $decoded   The decoded download.
	 * @param string $schemeUri The uri the scheme is stored under.
	 *
	 * @return array{jsonLd: array{"@graph": array<int, array<string, mixed>>}, conceptUris: array<int, string>}
	 *
	 * @throws InvalidArgumentException When the download holds no scheme or no concept.
	 *
	 * @spec openspec/changes/woo-value-lists-on-the-concept-register/specs/woo-compliance/spec.md#requirement-each-scheme-is-refreshed-from-its-national-source-daily-req-wvc-002
	 */
	public function build(mixed $decoded, string $schemeUri): array {
		$nodes = $this->nodesOf(decoded: $decoded);
		$scheme = $this->schemeNode(nodes: $nodes, schemeUri: $schemeUri);

		$graph = [$scheme];
		$uris = [];
		foreach ($nodes as $node) {
			if ($this->isConcept(node: $node) === false) {
				continue;
			}

			$node['@type'] = self::SKOS . 'Concept';
			$node[self::SKOS . 'inScheme'] = [['@id' => $schemeUri]];
			$graph[] = $node;
			$uris[] = (string)$node['@id'];
		}

		if ($uris === []) {
			throw new InvalidArgumentException('The download of ' . $schemeUri . ' holds no concept.');
		}

		return ['jsonLd' => ['@graph' => $graph], 'conceptUris' => $uris];
	}//end build()

	/**
	 * The node list of a download: a top-level list, or a `@graph` envelope.
	 *
	 * @param mixed $decoded The decoded download.
	 *
	 * @return array<int, array<string, mixed>> The nodes with an `@id`.
	 *
	 * @throws InvalidArgumentException When it is neither.
	 */
	private function nodesOf(mixed $decoded): array {
		if (is_array($decoded) === true && is_array($decoded['@graph'] ?? null) === true) {
			$decoded = $decoded['@graph'];
		}

		if (is_array($decoded) === false || array_is_list($decoded) === false) {
			throw new InvalidArgumentException('The download is not a JSON-LD node list.');
		}

		return array_values(
			array_filter(
				$decoded,
				static fn (mixed $node): bool => is_array($node) === true && is_string($node['@id'] ?? null) === true
			)
		);
	}//end nodesOf()

	/**
	 * The scheme node, stored under the configured uri.
	 *
	 * The download's own `skos:ConceptScheme` when it has one, else its value-list node.
	 *
	 * @param array<int, array<string, mixed>> $nodes     The nodes.
	 * @param string                           $schemeUri The uri the scheme is stored under.
	 *
	 * @return array<string, mixed> The scheme node.
	 *
	 * @throws InvalidArgumentException When neither is there.
	 */
	private function schemeNode(array $nodes, string $schemeUri): array {
		$listNode = null;
		foreach ($nodes as $node) {
			$types = $this->typesOf(node: $node);
			if (in_array(self::SKOS . 'ConceptScheme', $types, true) === true) {
				return $this->asScheme(node: $node, schemeUri: $schemeUri);
			}

			if ($listNode === null && $this->hasLocalType(types: $types, suffix: self::VALUE_LIST_SUFFIX) === true) {
				$listNode = $node;
			}
		}

		if ($listNode === null) {
			throw new InvalidArgumentException('The download of ' . $schemeUri . ' holds no scheme or value-list node.');
		}

		return $this->asScheme(node: $listNode, schemeUri: $schemeUri);
	}//end schemeNode()

	/**
	 * A node retyped as the scheme under the configured uri, keeping its labels.
	 *
	 * @param array<string, mixed> $node      The node.
	 * @param string               $schemeUri The uri the scheme is stored under.
	 *
	 * @return array<string, mixed> The scheme node.
	 */
	private function asScheme(array $node, string $schemeUri): array {
		$node[self::VERSION_INFO] = $this->versionOf(node: $node);
		if ($node[self::VERSION_INFO] === []) {
			unset($node[self::VERSION_INFO]);
		}

		$node['@id'] = $schemeUri;
		$node['@type'] = self::SKOS . 'ConceptScheme';

		return $node;
	}//end asScheme()

	/**
	 * The scheme's version: its `owl:versionInfo` when that is a version, else the list's number.
	 *
	 * TOOI writes a change note into `owl:versionInfo` on some lists ("Vereenvoudigde
	 * structuur, ..."); the version itself is the last segment of the list's id
	 * (`.../ccw_plooi_documenthandelingen/2`).
	 *
	 * @param array<string, mixed> $node The scheme or value-list node.
	 *
	 * @return array<int, array{"@value": string}> The literal, or none.
	 */
	private function versionOf(array $node): array {
		$info = ($node[self::VERSION_INFO][0]['@value'] ?? null);
		if (is_string($info) === true && $info !== '' && mb_strlen($info) <= self::VERSION_MAX) {
			return [['@value' => $info]];
		}

		$last = substr((string)strrchr('/' . (string)$node['@id'], '/'), 1);
		if (ctype_digit($last) === true) {
			return [['@value' => $last]];
		}

		return [];
	}//end versionOf()

	/**
	 * Whether a node is a concept of the list (not a grouping concept).
	 *
	 * @param array<string, mixed> $node The node.
	 *
	 * @return bool
	 */
	private function isConcept(array $node): bool {
		$types = $this->typesOf(node: $node);

		return in_array(self::SKOS . 'Concept', $types, true) === true
			&& $this->hasLocalType(types: $types, suffix: self::GROUPING) === false;
	}//end isConcept()

	/**
	 * The `@type` of a node as a list of strings.
	 *
	 * @param array<string, mixed> $node The node.
	 *
	 * @return array<int, string>
	 */
	private function typesOf(array $node): array {
		$types = ($node['@type'] ?? []);
		if (is_string($types) === true) {
			return [$types];
		}

		if (is_array($types) === false) {
			return [];
		}

		return array_values(array_filter($types, 'is_string'));
	}//end typesOf()

	/**
	 * Whether one of the types ends in the given local name.
	 *
	 * @param array<int, string> $types  The types.
	 * @param string             $suffix The local name.
	 *
	 * @return bool
	 */
	private function hasLocalType(array $types, string $suffix): bool {
		foreach ($types as $type) {
			if (str_ends_with($type, $suffix) === true) {
				return true;
			}
		}

		return false;
	}//end hasLocalType()
}//end class
