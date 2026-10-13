<?php

/**
 * OpenCatalogi draws no officer screen for Woo requests.
 *
 * Ruben decided on 2026-10-09 that handling a Woo request is dossiq's work, as
 * a case type. The design boards OcWooVerzoek and OcWooVerzoeken are retired;
 * dossiq's DqWooVerzoeken and DqZaak show what they showed. This test keeps a
 * request page from growing back into the manifest, and keeps the parity rows
 * pointing at dossiq.
 *
 * @category Test
 * @package  OCA\OpenCatalogi\Tests
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://www.OpenCatalogi.nl
 *
 * @spec openspec/changes/woo-request-screens-move-to-dossiq/specs/woo-request-intake/spec.md#requirement-opencatalogi-offers-no-officer-screen-for-woo-requests-req-wrs-001
 */

declare(strict_types=1);

namespace Unit\AppInfo;

use PHPUnit\Framework\TestCase;

/**
 * Reads src/manifest.json and openspec/parity/capabilities.json as shipped.
 */
class NoWooRequestScreenTest extends TestCase {

	/**
	 * A value that names a Woo request: the schema, its config keys, its routes.
	 */
	private const REQUEST_PATTERN = '/woo.?request|verzoek|\/woo\/requests/i';

	/**
	 * Read and decode a JSON file of this repository.
	 *
	 * @param string $relative Path relative to the repository root.
	 *
	 * @return array<string, mixed> The decoded document.
	 */
	private function readJson(string $relative): array {
		$path = dirname(__DIR__, 3) . '/' . $relative;
		$this->assertFileExists($path);

		$decoded = json_decode((string)file_get_contents($path), true);
		$this->assertIsArray($decoded, $relative . ' is not valid JSON');

		return $decoded;
	}//end readJson()

	/**
	 * Collect every string value that names a Woo request, with its path.
	 *
	 * Keys that start with an underscore are notes for people and are skipped.
	 *
	 * @param mixed  $node The node to walk.
	 * @param string $path The path of the node.
	 *
	 * @return list<string> One "path: value" line per hit.
	 */
	private function requestReferences(mixed $node, string $path): array {
		if (is_string($node) === true) {
			if (preg_match(self::REQUEST_PATTERN, $node) === 1) {
				return [$path . ': ' . $node];
			}

			return [];
		}

		if (is_array($node) === false) {
			return [];
		}

		$hits = [];
		foreach ($node as $key => $child) {
			if (is_string($key) === true && str_starts_with($key, '_') === true) {
				continue;
			}

			$hits = array_merge($hits, $this->requestReferences(node: $child, path: $path . '.' . $key));
		}

		return $hits;
	}//end requestReferences()

	/**
	 * No page, menu entry or widget of the manifest points at a Woo request.
	 *
	 * @spec openspec/changes/woo-request-screens-move-to-dossiq/specs/woo-request-intake/spec.md#requirement-opencatalogi-offers-no-officer-screen-for-woo-requests-req-wrs-001
	 *
	 * @return void
	 */
	public function testTheManifestHasNoWooRequestPage(): void {
		$manifest = $this->readJson('src/manifest.json');

		$hits = array_merge(
			$this->requestReferences(node: ($manifest['pages'] ?? []), path: 'pages'),
			$this->requestReferences(node: ($manifest['menu'] ?? []), path: 'menu')
		);

		$this->assertSame([], $hits, 'Woo requests are handled in dossiq; OpenCatalogi draws no screen for them');
	}//end testTheManifestHasNoWooRequestPage()

	/**
	 * The request rows of the parity matrix name dossiq's boards, not ours.
	 *
	 * @spec openspec/changes/woo-request-screens-move-to-dossiq/specs/woo-request-intake/spec.md#requirement-opencatalogi-offers-no-officer-screen-for-woo-requests-req-wrs-001
	 *
	 * @return void
	 */
	public function testTheRequestRowsPointAtDossiq(): void {
		$matrix = $this->readJson('openspec/parity/capabilities.json');

		$found = [];
		$rows = $this->rowsById(node: $matrix, ids: ['wr-request-record', 'wr-search-sources']);
		foreach (['wr-request-record', 'wr-search-sources'] as $id) {
			$this->assertArrayHasKey($id, $rows, 'parity row ' . $id . ' is missing');
			$screen = ($rows[$id]['screen'] ?? []);
			$this->assertArrayHasKey('board', $screen, $id . ' has no screen.board');
			$this->assertNull($screen['board'], $id . ' still names a board');
			$this->assertMatchesRegularExpression('/DqWooVerzoeken|DqZaak/', (string)($screen['reason'] ?? ''), $id);
			$found[] = $id;
		}

		$this->assertCount(2, $found);
	}//end testTheRequestRowsPointAtDossiq()

	/**
	 * Find the rows with the given ids anywhere in the matrix.
	 *
	 * @param mixed        $node The node to search.
	 * @param list<string> $ids  The row ids wanted.
	 *
	 * @return array<string, array<string, mixed>> The rows, keyed by id.
	 */
	private function rowsById(mixed $node, array $ids): array {
		if (is_array($node) === false) {
			return [];
		}

		$rows = [];
		if (isset($node['id']) === true && in_array($node['id'], $ids, true) === true) {
			$rows[(string)$node['id']] = $node;
		}

		foreach ($node as $child) {
			$rows = array_merge($rows, $this->rowsById(node: $child, ids: $ids));
		}

		return $rows;
	}//end rowsById()
}//end class
