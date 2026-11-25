<?php

/**
 * OpenCatalogi Value List Merge.
 *
 * Applies the organisation's choices to the entries of a national value list,
 * and orders the result: entries the administrator ordered first, lowest
 * first, then the rest in the list's own order. The normative fields (code,
 * URI, the source's label) always come from the list; a choice only adds the
 * organisation's own label, explanation, order and the hidden and active flags.
 *
 * @category Service
 * @package  OCA\OpenCatalogi\Service\ValueList
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * @version GIT: <git_id>
 * @link https://www.OpenCatalogi.nl
 * @spec openspec/changes/woo-value-list-curation/tasks.md#task-1.1
 */

declare(strict_types=1);

namespace OCA\OpenCatalogi\Service\ValueList;

/**
 * Merges list entries with choices and orders them.
 *
 * @spec openspec/changes/woo-value-list-curation/specs/woo-compliance/spec.md#requirement-the-organisations-own-text-sits-beside-the-normative-entry-req-wvl-001
 */
class ValueListMerge {

	/**
	 * The entries of a list with their choices applied, in the organisation's order.
	 *
	 * @param array<int|string, mixed>             $source  The list's entries, in source order.
	 * @param array<string, array<string, mixed>> $choices The choices by entry URI.
	 *
	 * @return list<array<string, mixed>> Each `{code, uri, sourceLabel, label, explanation, order, hidden, active, status}`.
	 *
	 * @spec openspec/changes/woo-value-list-curation/specs/woo-compliance/spec.md#requirement-the-organisations-own-text-sits-beside-the-normative-entry-req-wvl-001
	 */
	public function merge(array $source, array $choices): array {
		$entries = [];
		foreach (array_values($source) as $row) {
			$uri = (string)($row['uri'] ?? '');
			$entries[] = self::merged(row: (array)$row, choice: ($choices[$uri] ?? null));
		}

		return self::ordered(entries: $entries);

	}//end merge()

	/**
	 * One source entry with its choice applied.
	 *
	 * @param array<string, mixed>      $row    The source entry.
	 * @param array<string, mixed>|null $choice The choice (`order`, `localLabel`, `explanation`, `hidden`, `active`), or null.
	 *
	 * @return array<string, mixed> The entry: `{code, uri, sourceLabel, label, explanation, order, hidden, active, status}`.
	 */
	private static function merged(array $row, ?array $choice): array {
		$sourceLabel = (string)($row['label'] ?? '');
		$label = $sourceLabel;
		if ($choice !== null && $choice['localLabel'] !== '') {
			$label = $choice['localLabel'];
		}

		return [
			'code' => (string)($row['code'] ?? ''),
			'uri' => (string)($row['uri'] ?? ''),
			'sourceLabel' => $sourceLabel,
			'label' => $label,
			'explanation' => ($choice['explanation'] ?? ''),
			'order' => ($choice['order'] ?? null),
			'hidden' => ($choice['hidden'] ?? false),
			'active' => ($choice['active'] ?? false),
			'status' => (string)($row['status'] ?? 'active'),
		];

	}//end merged()

	/**
	 * The entries by order, then by source order; an entry without an order follows the ordered ones.
	 *
	 * @param list<array<string, mixed>> $entries The merged entries, in source order.
	 *
	 * @return list<array<string, mixed>>
	 */
	private static function ordered(array $entries): array {
		$keyed = [];
		foreach ($entries as $position => $entry) {
			$keyed[] = ['entry' => $entry, 'position' => $position];
		}

		usort(
			$keyed,
			static function (array $left, array $right): int {
				$a = $left['entry']['order'];
				$b = $right['entry']['order'];
				if ($a !== $b) {
					if ($a === null) {
						return 1;
					}

					if ($b === null) {
						return -1;
					}

					return $a <=> $b;
				}

				return $left['position'] <=> $right['position'];
			}
		);

		return array_column($keyed, 'entry');

	}//end ordered()
}//end class
