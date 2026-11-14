<?php

/**
 * The order of a publication's documents on its public attachment list.
 *
 * @category Service
 * @package  OCA\OpenCatalogi\Service\Publication
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
 * @spec openspec/changes/publication-detail-for-the-portal/specs/publications/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenCatalogi\Service\Publication;

use OCP\Files\Node;

/**
 * Sorts a publication's files by its stored `attachmentOrder` and numbers them.
 *
 * The files named in `attachmentOrder` come first, in that order. Files not in
 * it follow by upload time, oldest first, so a new upload goes last. An id in
 * the order that is no longer attached is skipped.
 *
 * @spec openspec/changes/publication-detail-for-the-portal/specs/publications/spec.md#requirement-an-officer-orders-a-publication-s-documents-and-the-public-list-keeps-that-order-req-pdp-001
 */
class AttachmentOrder {
	/**
	 * The file ids of a publication's stored order, as strings.
	 *
	 * @param mixed $publication The publication as an array, or an object with jsonSerialize().
	 *
	 * @return list<string> The ids, or an empty list when none is stored.
	 *
	 * @spec openspec/changes/publication-detail-for-the-portal/tasks.md#1.1
	 */
	public static function storedOrder(mixed $publication): array {
		if (is_object($publication) === true && method_exists($publication, 'jsonSerialize') === true) {
			$publication = $publication->jsonSerialize();
		}

		if (is_array($publication) === false) {
			return [];
		}

		$order = ($publication['attachmentOrder'] ?? null);
		if (is_array($order) === false) {
			return [];
		}

		$ids = [];
		foreach ($order as $id) {
			if (is_string($id) === true || is_int($id) === true) {
				$ids[] = (string) $id;
			}
		}

		return array_values(array_unique($ids));

	}//end storedOrder()

	/**
	 * The files in the stored order, then the rest by upload time.
	 *
	 * @param array<int, mixed> $files The publication's files (Nodes; anything else keeps its place among the unordered).
	 * @param list<string>      $order The stored file ids.
	 *
	 * @return list<mixed> The files, sorted.
	 *
	 * @spec openspec/changes/publication-detail-for-the-portal/tasks.md#1.1
	 */
	public static function sort(array $files, array $order): array {
		$rank = array_flip($order);
		$keyed = [];
		foreach (array_values($files) as $index => $file) {
			$id = null;
			if ($file instanceof Node) {
				$id = (string) $file->getId();
			}

			$keyed[] = [
				'file'    => $file,
				'ranked'  => ($id !== null && isset($rank[$id]) === true),
				'rank'    => ($rank[$id ?? ''] ?? 0),
				'time'    => self::uploadTime(file: $file),
				'index'   => $index,
			];
		}

		usort(
			$keyed,
			static function (array $a, array $b): int {
				if ($a['ranked'] !== $b['ranked']) {
					// A file in the stored order sorts before one that is not.
					return ($b['ranked'] <=> $a['ranked']);
				}

				if ($a['ranked'] === true) {
					return ($a['rank'] <=> $b['rank']);
				}

				return [$a['time'], $a['index']] <=> [$b['time'], $b['index']];
			}
		);

		return array_map(static fn (array $entry): mixed => $entry['file'], $keyed);

	}//end sort()

	/**
	 * Number each file of a paged answer from 1, counting the pages before it.
	 *
	 * @param array<string, mixed> $page The paged answer (`results`, `offset`).
	 *
	 * @return array<string, mixed> The answer with `position` on each result.
	 *
	 * @spec openspec/changes/publication-detail-for-the-portal/tasks.md#1.1
	 */
	public static function withPositions(array $page): array {
		if (isset($page['results']) === false || is_array($page['results']) === false) {
			return $page;
		}

		$offset = max(0, (int) ($page['offset'] ?? 0));
		foreach (array_keys($page['results']) as $index => $key) {
			if (is_array($page['results'][$key]) === true) {
				$page['results'][$key]['position'] = ($offset + $index + 1);
			}
		}

		return $page;

	}//end withPositions()

	/**
	 * When a file was uploaded: its upload time, else its modification time.
	 *
	 * @param mixed $file The file.
	 *
	 * @return int A Unix time, 0 when neither is known.
	 */
	private static function uploadTime(mixed $file): int {
		if (($file instanceof Node) === false) {
			return 0;
		}

		$time = (int) $file->getUploadTime();

		if ($time <= 0) {
			$time = (int) $file->getMTime();
		}

		return $time;

	}//end uploadTime()
}//end class
