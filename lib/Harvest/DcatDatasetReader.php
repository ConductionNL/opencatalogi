<?php

/**
 * Finds the datasets in a parsed DCAT catalogue in JSON-LD.
 *
 * A dataset is a node typed `dcat:Dataset` anywhere in the graph, or a node a
 * catalogue lists under `dcat:dataset`. Each is keyed by its `@id`; one
 * without `@id` cannot be tracked, so it is an error rather than an item.
 *
 * @category Harvest
 * @package  OCA\OpenCatalogi\Harvest
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
 * @spec openspec/changes/harvest-feed-intake/specs/harvest-feed-intake/spec.md#requirement-opencatalogi-registers-a-dcat-json-ld-fetcher-req-hfi-001
 */

declare(strict_types=1);

namespace OCA\OpenCatalogi\Harvest;

use OCA\OpenRegister\Service\Sync\HarvestBatch;

/**
 * Reads the datasets out of a JSON-LD document.
 *
 * @spec openspec/changes/harvest-feed-intake/specs/harvest-feed-intake/spec.md#requirement-opencatalogi-registers-a-dcat-json-ld-fetcher-req-hfi-001
 */
class DcatDatasetReader {

	/**
	 * The ways a JSON-LD document names the dataset type.
	 *
	 * @var array<int, string>
	 */
	private const DATASET_TYPES = [
		'dcat:Dataset',
		'Dataset',
		'http://www.w3.org/ns/dcat#Dataset',
	];

	/**
	 * The ways a catalogue names its datasets.
	 *
	 * @var array<int, string>
	 */
	private const DATASET_KEYS = [
		'dcat:dataset',
		'dataset',
		'http://www.w3.org/ns/dcat#dataset',
	];

	/**
	 * Turn a parsed document into a batch.
	 *
	 * @param array<mixed> $document The JSON-LD document.
	 *
	 * @return HarvestBatch The batch.
	 *
	 * @spec openspec/changes/harvest-feed-intake/tasks.md#task-1.1
	 */
	public function batchOf(array $document): HarvestBatch {
		$items  = [];
		$errors = [];
		foreach ($this->datasetsIn(document: $document) as $dataset) {
			$id = $dataset['@id'] ?? null;
			if (is_string($id) === false || trim($id) === '') {
				$errors[] = [
					'externalId' => null,
					'message' => 'A dataset has no @id, so it cannot be harvested: ' . $this->titleOf(dataset: $dataset),
				];
				continue;
			}

			if (isset($items[$id]) === false) {
				$items[$id] = $dataset;
			}
		}

		return new HarvestBatch(items: $items, complete: true, errors: $errors);
	}//end batchOf()

	/**
	 * Every dataset node in the document: typed `dcat:Dataset` anywhere in the
	 * graph, or listed under a catalogue's `dcat:dataset`.
	 *
	 * @param array<mixed> $document The JSON-LD document.
	 *
	 * @return array<int, array<string, mixed>> The dataset nodes.
	 */
	private function datasetsIn(array $document): array {
		$nodes = $this->topNodes(document: $document);

		$datasets = [];
		foreach ($nodes as $node) {
			if ($this->isDataset(node: $node) === true) {
				$datasets[] = $node;
			}

			foreach ($this->listedDatasets(node: $node) as $listed) {
				$datasets[] = $listed;
			}
		}

		return $datasets;
	}//end datasetsIn()

	/**
	 * The document's top-level nodes: its `@graph`, a list, or the document itself.
	 *
	 * @param array<mixed> $document The JSON-LD document.
	 *
	 * @return array<int, array<string, mixed>> The nodes.
	 */
	private function topNodes(array $document): array {
		if (isset($document['@graph']) === true && is_array($document['@graph']) === true) {
			$document = $document['@graph'];
		} else if (array_is_list($document) === false) {
			$document = [$document];
		}

		return array_values(array_filter($document, 'is_array'));
	}//end topNodes()

	/**
	 * The dataset nodes a catalogue lists inline. A bare reference (`@id`
	 * only) is skipped: it names a node found elsewhere in the graph.
	 *
	 * @param array<string, mixed> $node The node.
	 *
	 * @return array<int, array<string, mixed>> The listed datasets.
	 */
	private function listedDatasets(array $node): array {
		$listed = [];
		foreach (self::DATASET_KEYS as $key) {
			$value = $node[$key] ?? null;
			if (is_array($value) === false) {
				continue;
			}

			if (array_is_list($value) === false) {
				$value = [$value];
			}

			foreach ($value as $entry) {
				if (is_array($entry) === true && array_keys($entry) !== ['@id']) {
					$listed[] = $entry;
				}
			}
		}

		return $listed;
	}//end listedDatasets()

	/**
	 * Whether a node is typed as a dataset.
	 *
	 * @param array<string, mixed> $node The node.
	 *
	 * @return bool True for a `dcat:Dataset`.
	 */
	private function isDataset(array $node): bool {
		$types = $node['@type'] ?? [];
		if (is_array($types) === false) {
			$types = [$types];
		}

		return array_intersect($types, self::DATASET_TYPES) !== [];
	}//end isDataset()

	/**
	 * A dataset's title for an error message, or a placeholder.
	 *
	 * @param array<string, mixed> $dataset The dataset node.
	 *
	 * @return string The title.
	 */
	private function titleOf(array $dataset): string {
		$title = $dataset['dct:title'] ?? $dataset['title'] ?? $dataset['http://purl.org/dc/terms/title'] ?? null;
		if (is_array($title) === true) {
			$title = $title['@value'] ?? ($title[0]['@value'] ?? ($title[0] ?? null));
		}

		if (is_string($title) === true && $title !== '') {
			return '"' . $title . '"';
		}

		return '(untitled)';
	}//end titleOf()
}//end class
